<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\DailyMenuOptionOverride;
use App\Models\DailyMenuOverride;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\WeeklyMenu;
use App\Models\WeeklyMenuItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MenuAuditCommand extends Command
{
    protected $signature = 'menu:audit
        {--restaurant=restaurante-sol : Company id or slug to audit}
        {--format=table : Output format: table or json}';

    protected $description = 'Audit Sol Restaurante menu catalog data without changing records.';

    public function handle(): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('Invalid format. Use table or json.');

            return self::FAILURE;
        }

        $company = $this->resolveCompany((string) $this->option('restaurant'));

        if (! $company instanceof Company) {
            $message = 'Restaurant/company not found for the provided id or slug.';

            if ($format === 'json') {
                $this->line(json_encode([
                    'ok' => false,
                    'error' => $message,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $audit = $this->buildAudit($company);

        if ($format === 'json') {
            $this->line(json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderTableOutput($audit);
        }

        return $audit['summary']['critical_count'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveCompany(string $identifier): ?Company
    {
        $query = Company::query();

        if (ctype_digit($identifier)) {
            return $query->whereKey((int) $identifier)->first();
        }

        return $query->where('slug', $identifier)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAudit(Company $company): array
    {
        $categories = ProductCategory::query()
            ->where('company_id', $company->id)
            ->withCount('products')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $products = Product::query()
            ->where('company_id', $company->id)
            ->with(['category', 'options'])
            ->withCount(['options', 'weeklyMenuItems'])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $options = ProductOption::query()
            ->where('company_id', $company->id)
            ->with('product')
            ->orderBy('product_id')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $weeklyMenus = WeeklyMenu::query()
            ->where('company_id', $company->id)
            ->withCount('items')
            ->orderBy('name')
            ->get();

        $comparison = $this->compareOfficialMenu($products);
        $issues = $comparison['issues'];
        $this->appendStructuralIssues($company, $products, $options, $weeklyMenus, $issues);

        $criticalCount = collect($issues)->where('severity', 'critical')->count();

        return [
            'ok' => $criticalCount === 0,
            'metadata' => [
                'company' => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'slug' => $company->slug,
                ],
                'official_sources' => [
                    'docs/backlog/00_DOCUMENTO_MESTRE_CHATBOTCRM_SOL_RESTAURANTE_V1_8.md',
                    'docs/backlog/modules/M03_CARDAPIO_PRODUTOS_DISPONIBILIDADE.md',
                ],
                'read_only' => true,
            ],
            'summary' => [
                'critical_count' => $criticalCount,
                'warning_count' => collect($issues)->where('severity', 'warning')->count(),
                'needs_confirmation_count' => collect($issues)->where('severity', 'needs_confirmation')->count(),
            ],
            'counts' => $this->counts($company),
            'categories' => $categories->map(fn (ProductCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'type' => $category->category_type,
                'active' => $category->is_active,
                'products_count' => $category->products_count,
            ])->values()->all(),
            'products' => $products->map(fn (Product $product): array => $this->productRow($product))->values()->all(),
            'options_by_group' => $this->optionsByGroup($options),
            'comparison' => $comparison['rows'],
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function counts(Company $company): array
    {
        return [
            'categories' => ProductCategory::query()->where('company_id', $company->id)->count(),
            'products_total' => Product::query()->where('company_id', $company->id)->count(),
            'products_active' => Product::query()->where('company_id', $company->id)->where('is_active', true)->count(),
            'products_available_by_default' => Product::query()->where('company_id', $company->id)->where('is_available_by_default', true)->count(),
            'marmitas' => Product::query()->where('company_id', $company->id)->where('product_type', Product::TYPE_MARMITA)->count(),
            'combos' => Product::query()->where('company_id', $company->id)->where('product_type', Product::TYPE_COMBO)->count(),
            'beverages' => Product::query()->where('company_id', $company->id)->where('product_type', Product::TYPE_BEVERAGE)->count(),
            'juices' => Product::query()->where('company_id', $company->id)->where('product_type', Product::TYPE_JUICE)->count(),
            'feijoadas' => Product::query()->where('company_id', $company->id)->where('product_type', Product::TYPE_FEIJOADA)->count(),
            'addon_products' => Product::query()->where('company_id', $company->id)->where('product_type', Product::TYPE_ADDON)->count(),
            'options_total' => ProductOption::query()->where('company_id', $company->id)->count(),
            'option_links' => ProductOption::query()->where('company_id', $company->id)->whereNotNull('product_id')->count(),
            'global_options' => ProductOption::query()->where('company_id', $company->id)->whereNull('product_id')->count(),
            'additional_options' => ProductOption::query()->where('company_id', $company->id)->where('option_type', ProductOption::TYPE_ADDON)->count(),
            'weekly_menus' => WeeklyMenu::query()->where('company_id', $company->id)->count(),
            'weekly_menu_items' => WeeklyMenuItem::query()
                ->whereHas('weeklyMenu', fn ($query) => $query->where('company_id', $company->id))
                ->count(),
            'weekly_menu_day_specific_items' => WeeklyMenuItem::query()
                ->whereHas('weeklyMenu', fn ($query) => $query->where('company_id', $company->id))
                ->where('service_day', '!=', WeeklyMenuItem::DAY_EVERYDAY)
                ->count(),
            'daily_product_overrides' => DailyMenuOverride::query()->where('company_id', $company->id)->count(),
            'daily_option_overrides' => DailyMenuOptionOverride::query()->where('company_id', $company->id)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productRow(Product $product): array
    {
        $groups = $product->options
            ->pluck('group_code')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'category' => $product->category?->slug,
            'price_cents' => $product->base_price_cents,
            'active' => $product->is_active,
            'available_by_default' => $product->is_available_by_default,
            'type' => $product->product_type,
            'rule' => $product->menu_rule_code,
            'groups_count' => count($groups),
            'groups' => $groups,
            'options_count' => $product->options_count,
            'weekly_menu_items_count' => $product->weekly_menu_items_count,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function optionsByGroup($options): array
    {
        return $options
            ->groupBy(fn (ProductOption $option): string => ($option->product?->slug ?? 'global').':'.($option->group_code ?? 'sem-grupo'))
            ->map(function ($rows, string $key): array {
                [$productSlug, $groupCode] = explode(':', $key, 2);

                return [
                    'product' => $productSlug,
                    'group' => $groupCode,
                    'count' => $rows->count(),
                    'required_count' => $rows->where('is_required', true)->count(),
                    'active_count' => $rows->where('is_active', true)->count(),
                    'options' => $rows->pluck('name')->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, issues: array<int, array<string, mixed>>}
     */
    private function compareOfficialMenu($products): array
    {
        $issues = [];
        $rows = [];
        $productsBySlug = $products->keyBy('slug');

        foreach ($this->officialProducts() as $slug => $expected) {
            /** @var Product|null $product */
            $product = $productsBySlug->get($slug);
            $status = 'OK';
            $notes = [];

            if (! $product instanceof Product) {
                $status = 'ausente';
                $notes[] = 'Produto esperado pela documentação não existe no banco.';
                $this->addIssue($issues, 'critical', 'missing_product', $slug, implode(' ', $notes));
            } else {
                if ($expected['price_cents'] !== null && (int) $product->base_price_cents !== (int) $expected['price_cents']) {
                    $status = 'preço divergente';
                    $notes[] = 'Preço no banco diverge da fonte oficial.';
                    $this->addIssue($issues, 'critical', 'price_mismatch', $slug, 'Preço esperado '.$expected['price_cents'].' centavos; encontrado '.$product->base_price_cents.' centavos.');
                }

                if (($product->category?->slug ?? null) !== $expected['category']) {
                    $status = $status === 'OK' ? 'categoria divergente' : $status;
                    $notes[] = 'Categoria no banco diverge da fonte oficial.';
                    $this->addIssue($issues, 'critical', 'category_mismatch', $slug, 'Categoria esperada '.$expected['category'].'; encontrada '.($product->category?->slug ?? 'sem-categoria').'.');
                }

                if ($expected['active'] === true && ! $product->is_active) {
                    $status = $status === 'OK' ? 'inativo' : $status;
                    $notes[] = 'Produto esperado como operacional está inativo.';
                    $this->addIssue($issues, 'critical', 'inactive_expected_product', $slug, 'Produto operacional está inativo.');
                }

                $groups = $product->options->pluck('group_code')->filter()->unique()->values();
                foreach ($expected['required_groups'] as $groupCode) {
                    if (! $groups->contains($groupCode)) {
                        $status = $status === 'OK' ? 'regra incompleta' : $status;
                        $notes[] = 'Grupo esperado ausente: '.$groupCode.'.';
                        $this->addIssue($issues, 'critical', 'missing_option_group', $slug, 'Grupo de opções esperado ausente: '.$groupCode.'.');
                    }
                }
            }

            if (($expected['needs_confirmation'] ?? false) === true) {
                $status = $status === 'OK' ? 'precisa de confirmação' : $status;
                $notes[] = 'A documentação cita a categoria/item, mas não detalha preço ou variações suficientes.';
                $this->addIssue($issues, 'needs_confirmation', 'official_detail_missing', $slug, 'Fonte oficial não detalha preço/variações suficientes para validação automática completa.');
            }

            $rows[] = [
                'expected_item' => $expected['name'],
                'expected_category' => $expected['category'],
                'expected_price_cents' => $expected['price_cents'],
                'exists_in_database' => $product instanceof Product,
                'found_price_cents' => $product?->base_price_cents,
                'found_category' => $product?->category?->slug,
                'found_groups' => $product?->options->pluck('group_code')->filter()->unique()->values()->all() ?? [],
                'expected_rules' => $expected['rules'],
                'situation' => $status,
                'observation' => implode(' ', $notes),
            ];
        }

        return ['rows' => $rows, 'issues' => $issues];
    }

    /**
     * @param  array<int, array<string, mixed>>  $issues
     */
    private function appendStructuralIssues(Company $company, $products, $options, $weeklyMenus, array &$issues): void
    {
        $products->whereNull('category_id')->each(fn (Product $product) => $this->addIssue(
            $issues,
            'critical',
            'product_without_category',
            $product->slug,
            'Produto sem categoria.',
        ));

        $products
            ->where('is_active', true)
            ->whereNull('base_price_cents')
            ->each(fn (Product $product) => $this->addIssue(
                $issues,
                'critical',
                'active_product_without_price',
                $product->slug,
                'Produto ativo sem preço.',
            ));

        $products
            ->groupBy('slug')
            ->filter(fn ($rows): bool => $rows->count() > 1)
            ->each(fn ($rows, string $slug) => $this->addIssue(
                $issues,
                'critical',
                'duplicate_product_slug',
                $slug,
                'Slug duplicado no mesmo restaurante.',
            ));

        $products
            ->groupBy(fn (Product $product): string => mb_strtolower($product->name))
            ->filter(fn ($rows): bool => $rows->pluck('base_price_cents')->unique()->count() > 1)
            ->each(fn ($rows, string $name) => $this->addIssue(
                $issues,
                'warning',
                'duplicate_name_with_price_variation',
                $name,
                'Nome duplicado com preços diferentes.',
            ));

        $options
            ->whereNull('group_code')
            ->each(fn (ProductOption $option) => $this->addIssue(
                $issues,
                'warning',
                'option_without_group',
                $option->slug,
                'Opção sem group_code.',
            ));

        if ($weeklyMenus->isEmpty()) {
            $this->addIssue($issues, 'critical', 'missing_weekly_menu', 'weekly_menus', 'Nenhum cardápio semanal cadastrado.');
        }

        $daySpecificItems = WeeklyMenuItem::query()
            ->whereHas('weeklyMenu', fn ($query) => $query->where('company_id', $company->id))
            ->where('service_day', '!=', WeeklyMenuItem::DAY_EVERYDAY)
            ->count();

        if ($daySpecificItems === 0) {
            $this->addIssue(
                $issues,
                'critical',
                'weekly_menu_without_day_specific_items',
                'weekly_menu_items',
                'Cardápio semanal não possui itens por dia; só há itens todos os dias ou nenhum vínculo.',
            );
        }

        $n5 = $products->firstWhere('slug', 'n5-casa');
        if ($n5 instanceof Product) {
            $n5SaladSlugs = $n5->options
                ->where('group_code', 'salada')
                ->pluck('slug')
                ->values();

            foreach (['repolho-com-tomate', 'vinagrete'] as $unexpectedSlug) {
                if ($n5SaladSlugs->contains($unexpectedSlug)) {
                    $this->addIssue(
                        $issues,
                        'critical',
                        'n5_casa_customer_salad_overmodeled',
                        'n5-casa',
                        'N5 Casa deveria ter salada escolhida pela casa entre beterraba/cenoura, mas possui opção de cliente '.$unexpectedSlug.'.',
                    );
                }
            }
        }

        if ($products->whereIn('slug', ['latinha', 'latinha-zero', 'suco'])->count() > 0) {
            $this->addIssue(
                $issues,
                'needs_confirmation',
                'generic_beverage_catalog',
                'bebidas',
                'Banco possui bebidas genéricas; documento oficial menciona latas, versões zero e sucos, mas não detalha todos os sabores/volumes.',
            );
        }

        $orphanOptionLinks = DB::table('product_options')
            ->leftJoin('products', 'products.id', '=', 'product_options.product_id')
            ->where('product_options.company_id', $company->id)
            ->whereNotNull('product_options.product_id')
            ->whereNull('products.id')
            ->count();

        if ($orphanOptionLinks > 0) {
            $this->addIssue($issues, 'critical', 'orphan_product_options', 'product_options', $orphanOptionLinks.' opções apontam para produto inexistente.');
        }

        $orphanWeeklyItems = DB::table('weekly_menu_items')
            ->leftJoin('weekly_menus', 'weekly_menus.id', '=', 'weekly_menu_items.weekly_menu_id')
            ->leftJoin('products', 'products.id', '=', 'weekly_menu_items.product_id')
            ->where(function ($query) use ($company): void {
                $query->where('weekly_menus.company_id', $company->id)
                    ->orWhere('products.company_id', $company->id);
            })
            ->where(function ($query): void {
                $query->whereNull('weekly_menus.id')->orWhereNull('products.id');
            })
            ->count();

        if ($orphanWeeklyItems > 0) {
            $this->addIssue($issues, 'critical', 'orphan_weekly_menu_items', 'weekly_menu_items', $orphanWeeklyItems.' vínculos semanais órfãos.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $issues
     */
    private function addIssue(array &$issues, string $severity, string $type, string $subject, string $message): void
    {
        $issues[] = [
            'severity' => $severity,
            'type' => $type,
            'subject' => $subject,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function officialProducts(): array
    {
        return [
            'n5-casa' => [
                'name' => 'N5 Casa',
                'category' => 'marmitas',
                'price_cents' => 800,
                'active' => true,
                'required_groups' => ['guarnicoes', 'salada', 'carne'],
                'rules' => 'Arroz, feijão, macarrão, mandioca, salada da casa entre beterraba/cenoura e 1 pedaço de uma única carne.',
            ],
            'n8-casa' => [
                'name' => 'N8 Casa',
                'category' => 'marmitas',
                'price_cents' => 1300,
                'active' => true,
                'required_groups' => ['guarnicoes', 'salada', 'carne'],
                'rules' => 'Arroz, feijão, macarrão, mandioca, salada escolhida pelo cliente e 2 pedaços de uma única carne.',
            ],
            'n8-tradicional' => [
                'name' => 'N8 Tradicional',
                'category' => 'marmitas',
                'price_cents' => 1600,
                'active' => true,
                'required_groups' => [],
                'rules' => 'Usa cardápio semanal completo do dia; variação com bife possui preço próprio.',
            ],
            'n9-tradicional' => [
                'name' => 'N9 Tradicional',
                'category' => 'marmitas',
                'price_cents' => 1800,
                'active' => true,
                'required_groups' => [],
                'rules' => 'Usa cardápio semanal completo do dia.',
            ],
            'combo-n8-casa-baby' => [
                'name' => 'Combo N8 Casa Baby',
                'category' => 'combos',
                'price_cents' => 1500,
                'active' => true,
                'required_groups' => [],
                'rules' => 'Combo oficial documentado.',
            ],
            'combo-n8-com-latinha' => [
                'name' => 'Combo N8 com latinha',
                'category' => 'combos',
                'price_cents' => 2000,
                'active' => true,
                'required_groups' => ['bebidas'],
                'rules' => 'Combo oficial com bebida lata.',
            ],
            'latinha' => [
                'name' => 'Latinha',
                'category' => 'bebidas',
                'price_cents' => 500,
                'active' => true,
                'required_groups' => [],
                'rules' => 'Todas as latas por R$ 5,00; sabores precisam de confirmação documental.',
                'needs_confirmation' => true,
            ],
            'latinha-zero' => [
                'name' => 'Latinha Zero',
                'category' => 'bebidas',
                'price_cents' => 500,
                'active' => true,
                'required_groups' => [],
                'rules' => 'Versões zero com mesmo preço das normais; sabores precisam de confirmação documental.',
                'needs_confirmation' => true,
            ],
            'suco' => [
                'name' => 'Suco',
                'category' => 'sucos',
                'price_cents' => 700,
                'active' => true,
                'required_groups' => [],
                'rules' => 'Sucos por R$ 7,00; sabores precisam de confirmação documental.',
                'needs_confirmation' => true,
            ],
            'ovo-frito-adicional' => [
                'name' => 'Ovo frito adicional',
                'category' => 'adicionais',
                'price_cents' => 200,
                'active' => true,
                'required_groups' => [],
                'rules' => 'Adicional oficial de ovo frito por R$ 2,00.',
            ],
            'feijoada' => [
                'name' => 'Feijoada',
                'category' => 'feijoadas',
                'price_cents' => null,
                'active' => null,
                'required_groups' => [],
                'rules' => 'Módulo M03 cita feijoadas, mas o documento mestre não fixa preço nesta auditoria.',
                'needs_confirmation' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $audit
     */
    private function renderTableOutput(array $audit): void
    {
        $this->info('Menu audit: '.$audit['metadata']['company']['name'].' ('.$audit['metadata']['company']['slug'].')');
        $this->line('Read-only: yes');

        $this->newLine();
        $this->info('Counts');
        $this->table(
            ['entity', 'count'],
            collect($audit['counts'])->map(fn (int $count, string $entity): array => [$entity, $count])->values()->all(),
        );

        $this->newLine();
        $this->info('Official comparison');
        $this->table(
            ['item', 'expected category', 'expected price', 'exists', 'found price', 'found category', 'situation'],
            collect($audit['comparison'])->map(fn (array $row): array => [
                $row['expected_item'],
                $row['expected_category'],
                $this->price($row['expected_price_cents']),
                $row['exists_in_database'] ? 'yes' : 'no',
                $this->price($row['found_price_cents']),
                $row['found_category'] ?? '-',
                $row['situation'],
            ])->all(),
        );

        $this->newLine();
        $this->info('Products');
        $this->table(
            ['id', 'name', 'slug', 'category', 'price', 'active', 'type', 'groups', 'options'],
            collect($audit['products'])->map(fn (array $row): array => [
                $row['id'],
                $row['name'],
                $row['slug'],
                $row['category'] ?? '-',
                $this->price($row['price_cents']),
                $row['active'] ? 'yes' : 'no',
                $row['type'],
                implode(', ', $row['groups']),
                $row['options_count'],
            ])->all(),
        );

        $this->newLine();
        $this->info('Issues');
        $this->table(
            ['severity', 'type', 'subject', 'message'],
            collect($audit['issues'])->map(fn (array $issue): array => [
                $issue['severity'],
                $issue['type'],
                $issue['subject'],
                $issue['message'],
            ])->all(),
        );
    }

    private function price(?int $cents): string
    {
        if ($cents === null) {
            return '-';
        }

        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}
