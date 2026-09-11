<?php

namespace Tests\Feature\Menu;

use App\Enums\ProductServiceDay;
use App\Models\Company;
use App\Models\MenuComponent;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\Menu\MenuComponentPresentation;
use App\Services\Menu\StructuredProductConfigurationService;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CounterProductManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_counter_products_in_an_existing_category_for_its_company(): void
    {
        $this->seedStructuredMenu();

        $admin = $this->admin();
        $payload = $this->counterProductPayload('Geladinho de morango');

        $created = $this->actingAs($admin)
            ->postJson('/api/app/menu/products', $payload)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Geladinho de morango')
            ->assertJsonPath('data.product_type', Product::TYPE_COUNTER)
            ->assertJsonPath('data.is_counter_product', true)
            ->assertJsonPath('data.category.slug', 'bebidas')
            ->assertJsonPath('data.image_url', null)
            ->json('data');

        $this->assertDatabaseHas('products', [
            'id' => $created['id'],
            'company_id' => $this->company()->id,
            'slug' => 'geladinho-de-morango',
            'product_type' => Product::TYPE_COUNTER,
        ]);
        $this->assertSame(1, ProductCategory::query()
            ->where('company_id', $this->company()->id)
            ->where('slug', 'bebidas')
            ->count());

        $newProductImage = $this->actingAs($admin)
            ->postJson("/api/app/menu/products/{$created['id']}/image", [
                'image' => $this->pngUpload('geladinho.png'),
            ])
            ->assertOk()
            ->json('data.image_url');

        $this->assertIsString($newProductImage);
        $this->assertStringStartsWith("/api/app/menu/products/{$created['id']}/image?v=", $newProductImage);

        $this->actingAs($admin)
            ->postJson('/api/app/menu/products', $this->counterProductPayload('Geladinho de uva'))
            ->assertCreated()
            ->assertJsonPath('data.category.slug', 'bebidas');

        $this->assertSame(1, ProductCategory::query()
            ->where('company_id', $this->company()->id)
            ->where('slug', 'bebidas')
            ->count());
    }

    public function test_product_image_upload_and_removal_are_scoped_to_the_product_company(): void
    {
        Storage::fake('public');
        $this->seedStructuredMenu();

        $admin = $this->admin();
        $product = Product::query()->create([
            'company_id' => $this->company()->id,
            'category_id' => ProductCategory::query()->where('company_id', $this->company()->id)->where('slug', 'bebidas')->value('id'),
            'name' => 'Doce de leite',
            'slug' => 'doce-de-leite',
            'product_type' => Product::TYPE_COUNTER,
            'base_price_cents' => 450,
            'is_active' => true,
            'is_available_by_default' => true,
            'metadata' => ['counter_sale' => true],
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/app/menu/products/{$product->id}/image", [
                'image' => $this->pngUpload('doce.png'),
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.is_counter_product', true);

        $imageUrl = $response->json('data.image_url');
        $this->assertIsString($imageUrl);
        $this->assertStringStartsWith("/api/app/menu/products/{$product->id}/image?v=", $imageUrl);
        $this->get($imageUrl)->assertOk()->assertHeader('x-content-type-options', 'nosniff');

        $product->refresh();
        $path = data_get($product->metadata, 'catalog_image_path');
        $this->assertIsString($path);
        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)
            ->deleteJson("/api/app/menu/products/{$product->id}/image")
            ->assertOk()
            ->assertJsonPath('data.image_url', null);

        Storage::disk('public')->assertMissing($path);
        $this->assertNull(data_get($product->refresh()->metadata, 'catalog_image_path'));
    }

    public function test_existing_regular_product_image_can_be_added_replaced_reloaded_and_removed(): void
    {
        Storage::fake('public');
        $this->seedStructuredMenu();

        $admin = $this->admin();
        $product = Product::query()
            ->where('company_id', $this->company()->id)
            ->where('slug', 'n8-casa')
            ->firstOrFail();

        $firstUrl = $this->actingAs($admin)
            ->postJson("/api/app/menu/products/{$product->id}/image", [
                'image' => $this->pngUpload('primeira.png'),
            ])
            ->assertOk()
            ->json('data.image_url');
        $firstPath = data_get($product->refresh()->metadata, 'catalog_image_path');

        $this->assertIsString($firstUrl);
        $this->assertIsString($firstPath);
        Storage::disk('public')->assertExists($firstPath);

        $secondUrl = $this->actingAs($admin)
            ->postJson("/api/app/menu/products/{$product->id}/image", [
                'image' => $this->pngUpload('segunda.png'),
            ])
            ->assertOk()
            ->json('data.image_url');
        $secondPath = data_get($product->refresh()->metadata, 'catalog_image_path');

        $this->assertIsString($secondUrl);
        $this->assertIsString($secondPath);
        $this->assertNotSame($firstUrl, $secondUrl);
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);

        $categories = $this->actingAs($admin)
            ->getJson('/api/app/menu/admin/products?date=2026-09-04')
            ->assertOk()
            ->json('data.categories');
        $reloaded = collect($categories)
            ->flatMap(fn (array $category): array => $category['products'])
            ->firstWhere('id', $product->id);

        $this->assertSame($secondUrl, $reloaded['image_url']);
        $this->get($secondUrl)->assertOk();

        $this->actingAs($admin)
            ->deleteJson("/api/app/menu/products/{$product->id}/image")
            ->assertOk()
            ->assertJsonPath('data.image_url', null);

        Storage::disk('public')->assertMissing($secondPath);
        $this->assertNull(data_get($product->refresh()->metadata, 'catalog_image_path'));
    }

    public function test_missing_catalog_image_returns_a_null_url_instead_of_a_broken_url(): void
    {
        Storage::fake('public');
        $this->seedStructuredMenu();
        $product = Product::query()->where('company_id', $this->company()->id)->firstOrFail();
        $path = "menu-products/{$this->company()->id}/{$product->id}/missing.jpg";
        $product->forceFill(['metadata' => ['catalog_image_path' => $path]])->save();

        $summary = app(StructuredProductConfigurationService::class)->productSummary($product->refresh(), $this->company(), now());

        $this->assertNull($summary['image_url']);
    }

    public function test_counter_product_endpoints_do_not_cross_company_boundaries(): void
    {
        $this->seedStructuredMenu();
        $admin = $this->admin();
        $otherCompany = Company::query()->create(['name' => 'Outra empresa', 'slug' => 'outra-empresa']);
        $category = ProductCategory::query()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Doces',
            'slug' => 'doces',
            'category_type' => ProductCategory::TYPE_DOCES,
        ]);
        $otherProduct = Product::query()->create([
            'company_id' => $otherCompany->id,
            'category_id' => $category->id,
            'name' => 'Produto externo',
            'slug' => 'produto-externo',
            'product_type' => Product::TYPE_COUNTER,
            'base_price_cents' => 500,
            'metadata' => ['counter_sale' => true],
        ]);

        $otherImagePath = "menu-products/{$otherCompany->id}/{$otherProduct->id}/private.png";
        Storage::disk('public')->put($otherImagePath, 'private-image');
        $otherProduct->forceFill(['metadata' => [
            'counter_sale' => true,
            'catalog_image_path' => $otherImagePath,
        ]])->save();

        $this->actingAs($admin)
            ->patchJson("/api/app/menu/products/{$otherProduct->id}", [
                ...$this->counterProductPayload('Produto externo'),
                'display_order' => 10,
            ])
            ->assertNotFound();

        $this->actingAs($admin)
            ->get("/api/app/menu/products/{$otherProduct->id}/image")
            ->assertNotFound();

        $this->actingAs($admin)
            ->postJson("/api/app/menu/products/{$otherProduct->id}/image", [
                'image' => $this->pngUpload('invasora.png'),
            ])
            ->assertNotFound();

        $this->actingAs($admin)
            ->deleteJson("/api/app/menu/products/{$otherProduct->id}/image")
            ->assertNotFound();

        Storage::disk('public')->assertExists($otherImagePath);
    }

    public function test_juice_flavors_keep_internal_slugs_and_expose_customer_facing_500ml_labels(): void
    {
        $this->seedStructuredMenu();
        $presentation = app(MenuComponentPresentation::class);

        foreach (['Goiaba', 'Acerola', 'Tamarindo', 'Abacaxi', 'Laranja'] as $name) {
            $component = MenuComponent::query()
                ->where('company_id', $this->company()->id)
                ->where('slug', str($name)->slug()->toString())
                ->firstOrFail();

            $summary = $presentation->summary($component);

            $this->assertSame("Suco de {$name} 500ml", $summary['display_name']);
            $this->assertSame('Sabor de suco', $summary['supporting_name']);
            $this->assertContains($name, $summary['search_aliases']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function counterProductPayload(string $name): array
    {
        return [
            'name' => $name,
            'description' => 'Item para venda rapida no balcao.',
            'price_cents' => 350,
            'is_active' => true,
            'is_available_by_default' => true,
            'category_id' => ProductCategory::query()
                ->where('company_id', $this->company()->id)
                ->where('slug', 'bebidas')
                ->value('id'),
            'is_counter_product' => true,
            'service_days' => [ProductServiceDay::Monday->value, ProductServiceDay::Tuesday->value],
        ];
    }

    private function pngUpload(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Jj2QAAAAASUVORK5CYII='),
        );
    }

    private function seedStructuredMenu(): void
    {
        $this->seed([RoleAndPermissionSeeder::class, SolRestaurantStructuredMenuSeeder::class]);
    }

    private function company(): Company
    {
        return Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['company_id' => $this->company()->id]);
        $user->assignRole(Role::SUPER_ADMIN);

        return $user;
    }
}
