<?php

namespace App\Console\Commands;

use App\Enums\MenuComponentType;
use App\Models\Company;
use App\Models\MenuComponent;
use App\Models\Product;
use App\Models\ProductGroupComponent;
use App\Models\ProductOptionGroup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AddJuiceFlavorCommand extends Command
{
    protected $signature = 'menu:add-juice-flavor
        {flavor : Customer-facing flavor name, such as Laranja}
        {--company=restaurante-sol : Company id or slug}
        {--product=suco : Product id or slug}
        {--group=sabor : Product option group code}
        {--dry-run : Resolve and report without persisting changes}';

    protected $description = 'Add or update one juice flavor in a company catalog without touching other flavors.';

    public function handle(): int
    {
        $company = $this->resolveCompany((string) $this->option('company'));
        if (! $company instanceof Company) {
            $this->error('Company was not found or is ambiguous.');

            return self::FAILURE;
        }

        $product = $this->resolveProduct($company, (string) $this->option('product'));
        if (! $product instanceof Product) {
            $this->error('Juice product was not found or is ambiguous.');

            return self::FAILURE;
        }

        $group = ProductOptionGroup::query()
            ->where('company_id', $company->id)
            ->where('product_id', $product->id)
            ->where('code', (string) $this->option('group'))
            ->get();

        if ($group->count() !== 1) {
            $this->error('Juice flavor group was not found or is ambiguous.');

            return self::FAILURE;
        }

        $flavor = trim((string) $this->argument('flavor'));
        $slug = Str::slug($flavor);
        if ($flavor === '' || $slug === '') {
            $this->error('Flavor must contain a valid name.');

            return self::FAILURE;
        }

        $component = MenuComponent::query()
            ->where('company_id', $company->id)
            ->where('slug', $slug)
            ->where('component_type', MenuComponentType::JuiceFlavor->value)
            ->first();

        $link = ProductGroupComponent::query()
            ->where('product_option_group_id', $group->first()->id)
            ->whereHas('component', fn ($query) => $query->where('slug', $slug))
            ->first();

        $plan = [
            'company' => $company->slug,
            'product' => $product->slug,
            'group' => $group->first()->code,
            'flavor' => $flavor,
            'component_action' => $component ? 'update' : 'create',
            'link_action' => $link ? 'update' : 'create',
            'dry_run' => (bool) $this->option('dry-run'),
        ];

        if ($this->option('dry-run')) {
            $this->line(json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        DB::transaction(function () use ($company, $group, $flavor, $slug, $component, $link): void {
            $component ??= MenuComponent::query()->create([
                'company_id' => $company->id,
                'slug' => $slug,
                'component_type' => MenuComponentType::JuiceFlavor->value,
                'name' => $flavor,
                'default_price_delta_cents' => 0,
                'is_active' => true,
                'display_order' => ((int) MenuComponent::query()
                    ->where('company_id', $company->id)
                    ->where('component_type', MenuComponentType::JuiceFlavor->value)
                    ->max('display_order')) + 10,
            ]);

            $link ??= new ProductGroupComponent([
                'product_option_group_id' => $group->first()->id,
                'menu_component_id' => $component->id,
            ]);
            $link->fill([
                'price_delta_cents' => 0,
                'final_price_cents' => null,
                'included_quantity' => null,
                'is_default' => false,
                'is_active' => true,
                'requires_confirmation' => false,
                'display_order' => $link->exists
                    ? $link->display_order
                    : ((int) ProductGroupComponent::query()
                        ->where('product_option_group_id', $group->first()->id)
                        ->max('display_order')) + 10,
            ]);
            $link->save();
        });

        $this->info('Juice flavor configured without changing other flavors.');

        return self::SUCCESS;
    }

    private function resolveCompany(string $identifier): ?Company
    {
        $query = Company::query();
        if (ctype_digit($identifier)) {
            return $query->whereKey((int) $identifier)->first();
        }

        return $query->where('slug', $identifier)->first();
    }

    private function resolveProduct(Company $company, string $identifier): ?Product
    {
        $query = Product::query()->where('company_id', $company->id);
        if (ctype_digit($identifier)) {
            return $query->whereKey((int) $identifier)->first();
        }

        $matches = $query->where(function ($products) use ($identifier): void {
            $products->where('slug', $identifier)->orWhere('name', $identifier);
        })->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
