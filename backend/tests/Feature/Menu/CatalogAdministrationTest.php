<?php

namespace Tests\Feature\Menu;

use App\Enums\ProductServiceDay;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_and_updates_a_regular_product_with_immediate_canonical_freshness(): void
    {
        $this->seedCatalog();
        $company = $this->company();
        $admin = $this->admin();
        $beverages = $this->category('bebidas');
        $juices = $this->category('sucos');

        $created = $this->actingAs($admin)
            ->postJson('/api/app/menu/products', $this->productPayload($beverages, 'H2O Lata', 1300))
            ->assertCreated()
            ->assertJsonPath('data.name', 'H2O Lata')
            ->assertJsonPath('data.product_type', Product::TYPE_BEVERAGE)
            ->assertJsonPath('data.is_counter_product', false)
            ->assertJsonPath('data.category.id', $beverages->id)
            ->json('data');

        $product = Product::query()->findOrFail($created['id']);
        $this->assertSame(1300, $this->catalogProduct('h2o-lata')['base_price_cents']);

        $order = Order::query()->create([
            'company_id' => $company->id,
            'order_date' => '2026-09-04',
            'daily_sequence' => 1,
            'code' => 'SOL-20260904-001',
        ]);
        $orderItem = $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_type' => $product->product_type,
            'quantity' => 1,
            'unit_price_cents' => 1300,
            'total_price_cents' => 1300,
            'currency' => 'BRL',
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/app/menu/products/{$product->id}", [
                ...$this->productPayload($juices, 'H2O Lata', 1400),
                'display_order' => 15,
            ])
            ->assertOk()
            ->assertJsonPath('data.base_price_cents', 1400)
            ->assertJsonPath('data.category.id', $juices->id)
            ->assertJsonPath('data.product_type', Product::TYPE_JUICE);

        $this->assertSame(1400, $this->catalogProduct('h2o-lata')['base_price_cents']);
        $this->assertSame(1400, $this->adminProduct('h2o-lata')['base_price_cents']);
        $this->assertSame(1300, $orderItem->fresh()->unit_price_cents);
        $this->assertSame('H2O Lata', $orderItem->fresh()->product_name);

        $newOrder = app(OrderWorkflowService::class)->createDraft($company, ['order_date' => '2026-09-04']);
        $newItem = app(OrderWorkflowService::class)->addItem($newOrder, $product->refresh());
        $this->assertSame(1400, $newItem->unit_price_cents);

        $this->actingAs($admin)
            ->patchJson("/api/app/menu/products/{$product->id}", [
                ...$this->productPayload($juices, 'H2O Lata', 1400),
                'display_order' => 15,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.administrative_status', 'inactive');

        $this->assertNull($this->catalogProductOrNull('h2o-lata'));
        $this->assertSame('inactive', $this->adminProduct('h2o-lata')['administrative_status']);
    }

    public function test_category_crud_and_product_association_are_tenant_scoped(): void
    {
        $this->seedCatalog();
        $admin = $this->admin();

        $category = $this->actingAs($admin)
            ->postJson('/api/app/menu/categories', [
                'name' => 'Lanches',
                'description' => 'Itens preparados na hora.',
                'display_order' => 70,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'lanches')
            ->assertJsonPath('data.category_type', 'general')
            ->json('data');

        $this->actingAs($admin)
            ->postJson('/api/app/menu/products', $this->productPayload(
                ProductCategory::query()->findOrFail($category['id']),
                'Sanduiche natural',
                900,
            ))
            ->assertCreated()
            ->assertJsonPath('data.product_type', Product::TYPE_PRODUCT)
            ->assertJsonPath('data.category.id', $category['id']);

        $this->actingAs($admin)
            ->patchJson("/api/app/menu/categories/{$category['id']}", [
                'name' => 'Lanches naturais',
                'description' => null,
                'display_order' => 75,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Lanches naturais')
            ->assertJsonPath('data.slug', 'lanches')
            ->assertJsonPath('data.is_active', false);

        $this->assertNull($this->catalogProductOrNull('sanduiche-natural'));

        $otherCompany = Company::query()->create(['name' => 'Outra empresa', 'slug' => 'outra-empresa']);
        $otherCategory = ProductCategory::query()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Privada',
            'slug' => 'privada',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/app/menu/products', $this->productPayload($otherCategory, 'Produto invasor', 100))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');

        $this->actingAs($admin)
            ->patchJson("/api/app/menu/categories/{$otherCategory->id}", [
                'name' => 'Invadida',
                'description' => null,
                'display_order' => 1,
                'is_active' => true,
            ])
            ->assertNotFound();

        $plainUser = User::factory()->create(['company_id' => $this->company()->id]);
        $this->actingAs($plainUser)
            ->postJson('/api/app/menu/categories', [
                'name' => 'Sem permissao',
                'description' => null,
                'display_order' => 1,
                'is_active' => true,
            ])
            ->assertForbidden();
    }

    public function test_delete_removes_an_unused_product_but_archives_a_product_with_sales_history(): void
    {
        $this->seedCatalog();
        $company = $this->company();
        $admin = $this->admin();
        $category = $this->category('bebidas');

        $unused = $this->createProduct($category, 'Temporario', 500);
        $this->actingAs($admin)
            ->deleteJson("/api/app/menu/products/{$unused->id}")
            ->assertOk()
            ->assertJsonPath('data.outcome', 'deleted')
            ->assertJsonPath('data.product', null);
        $this->assertDatabaseMissing('products', ['id' => $unused->id]);

        $used = $this->createProduct($category, 'Coca 2L', 1300);
        $order = Order::query()->create([
            'company_id' => $company->id,
            'order_date' => '2026-09-04',
            'daily_sequence' => 2,
            'code' => 'SOL-20260904-002',
        ]);
        $item = $order->items()->create([
            'product_id' => $used->id,
            'product_name' => 'Coca 2L',
            'product_type' => Product::TYPE_BEVERAGE,
            'quantity' => 1,
            'unit_price_cents' => 1300,
            'total_price_cents' => 1300,
            'currency' => 'BRL',
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/app/menu/products/{$used->id}")
            ->assertOk()
            ->assertJsonPath('data.outcome', 'archived')
            ->assertJsonPath('data.product.administrative_status', 'archived');

        $this->assertDatabaseHas('products', [
            'id' => $used->id,
            'is_active' => false,
            'is_available_by_default' => false,
        ]);
        $this->assertSame($used->id, $item->fresh()->product_id);
        $this->assertSame(1300, $item->fresh()->unit_price_cents);
        $this->assertNull($this->catalogProductOrNull('coca-2l'));
        $this->assertSame('archived', $this->adminProduct('coca-2l')['administrative_status']);
    }

    public function test_category_with_products_cannot_be_deleted_and_empty_category_can_be_deleted(): void
    {
        $this->seedCatalog();
        $admin = $this->admin();
        $usedCategory = $this->category('bebidas');

        $this->actingAs($admin)
            ->deleteJson("/api/app/menu/categories/{$usedCategory->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');
        $this->assertDatabaseHas('product_categories', ['id' => $usedCategory->id]);

        $empty = ProductCategory::query()->create([
            'company_id' => $this->company()->id,
            'name' => 'Vazia',
            'slug' => 'vazia',
        ]);
        $this->actingAs($admin)
            ->deleteJson("/api/app/menu/categories/{$empty->id}")
            ->assertOk()
            ->assertJsonPath('data.outcome', 'deleted');
        $this->assertDatabaseMissing('product_categories', ['id' => $empty->id]);
    }

    /** @return array<string, mixed> */
    private function productPayload(ProductCategory $category, string $name, int $priceCents): array
    {
        return [
            'date' => '2026-09-04',
            'name' => $name,
            'description' => null,
            'price_cents' => $priceCents,
            'is_active' => true,
            'is_available_by_default' => true,
            'category_id' => $category->id,
            'is_counter_product' => false,
            'service_days' => array_column(ProductServiceDay::cases(), 'value'),
        ];
    }

    private function createProduct(ProductCategory $category, string $name, int $priceCents): Product
    {
        return Product::query()->create([
            'company_id' => $category->company_id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'product_type' => Product::TYPE_BEVERAGE,
            'base_price_cents' => $priceCents,
            'currency' => 'BRL',
            'is_active' => true,
            'is_available_by_default' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function catalogProduct(string $slug): array
    {
        return $this->catalogProductOrNull($slug) ?? $this->fail("Product {$slug} was not found in the canonical catalog.");
    }

    /** @return array<string, mixed>|null */
    private function catalogProductOrNull(string $slug): ?array
    {
        $catalog = $this->actingAs($this->admin())
            ->getJson('/api/app/menu/catalog?date=2026-09-04')
            ->assertOk()
            ->json('data.categories');

        return collect($catalog)->flatMap(fn (array $category): array => $category['products'])->firstWhere('slug', $slug);
    }

    /** @return array<string, mixed> */
    private function adminProduct(string $slug): array
    {
        $categories = $this->actingAs($this->admin())
            ->getJson('/api/app/menu/admin/products?date=2026-09-04')
            ->assertOk()
            ->json('data.categories');

        $product = collect($categories)->flatMap(fn (array $category): array => $category['products'])->firstWhere('slug', $slug);
        $this->assertIsArray($product);

        return $product;
    }

    private function seedCatalog(): void
    {
        $this->seed([RoleAndPermissionSeeder::class, SolRestaurantStructuredMenuSeeder::class]);
    }

    private function company(): Company
    {
        return Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
    }

    private function category(string $slug): ProductCategory
    {
        return ProductCategory::query()
            ->where('company_id', $this->company()->id)
            ->where('slug', $slug)
            ->firstOrFail();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['company_id' => $this->company()->id]);
        $user->assignRole(Role::SUPER_ADMIN);

        return $user;
    }
}
