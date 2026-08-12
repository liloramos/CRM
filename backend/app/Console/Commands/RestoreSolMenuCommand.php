<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\MenuComponent;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\WeeklyMenu;
use Database\Seeders\SolRestaurantMenuAdminBaselineSeeder;
use Database\Seeders\SolRestaurantProductCatalogSeeder;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RestoreSolMenuCommand extends Command
{
    public const CATALOG_VERSION = '2026-08-07.1';

    protected $signature = 'sol:restore-menu
        {--company=restaurante-sol : Slug exato da empresa}
        {--dry-run : Apenas apresenta o plano, sem persistir}
        {--force-official : Atualiza registros oficiais existentes de forma explícita}';

    protected $description = 'Restaura o catálogo oficial do Restaurante Sol sem remover registros desconhecidos.';

    public function handle(): int
    {
        $companySlug = trim((string) $this->option('company'));
        $company = Company::query()->where('slug', $companySlug)->first();
        $catalog = collect(SolRestaurantProductCatalogSeeder::catalog());
        $officialSlugs = $catalog->pluck('slug')->all();
        $existingOfficial = $company
            ? Product::query()->where('company_id', $company->id)->whereIn('slug', $officialSlugs)->pluck('slug')
            : collect();
        $missing = $catalog->pluck('slug')->diff($existingOfficial)->values();
        $unknownProducts = $company
            ? Product::query()->where('company_id', $company->id)->whereNotIn('slug', $officialSlugs)->count()
            : 0;
        $isPartial = $existingOfficial->isNotEmpty() && $missing->isNotEmpty();
        $force = (bool) $this->option('force-official');

        $this->table(['Item', 'Resultado'], [
            ['Empresa', $company?->slug ?? "{$companySlug} (será criada)"],
            ['Versão do catálogo', self::CATALOG_VERSION],
            ['Produtos oficiais existentes', (string) $existingOfficial->count()],
            ['Produtos oficiais a criar', (string) $missing->count()],
            ['Produtos oficiais a atualizar', (string) ($force ? $existingOfficial->count() : 0)],
            ['Produtos desconhecidos mantidos', (string) $unknownProducts],
            ['Modo', $this->option('dry-run') ? 'dry-run' : 'persistência'],
        ]);

        if ($missing->isNotEmpty()) {
            $this->line('Ausentes: '.$missing->join(', '));
        }

        if ($this->option('dry-run')) {
            if ($isPartial && ! $force) {
                $this->warn('O catálogo está parcial. Revise os itens e use --force-official somente se a atualização oficial for intencional.');
            }

            return self::SUCCESS;
        }

        if ($isPartial && ! $force) {
            $this->error('Restauração bloqueada: catálogo parcial não será sobrescrito sem --force-official.');

            return self::FAILURE;
        }

        if ($missing->isEmpty() && ! $force) {
            $this->info('Catálogo oficial completo. Nenhuma alteração manual foi sobrescrita.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($company, $companySlug): void {
            $resolvedCompany = $company ?? Company::query()->create([
                'slug' => $companySlug,
                'name' => 'Restaurante Sol',
            ]);

            $this->runSeeder(SolRestaurantStructuredMenuSeeder::class);
            $this->runSeeder(SolRestaurantMenuAdminBaselineSeeder::class);
            $this->recordCatalogVersion($resolvedCompany);
        });

        $company = Company::query()->where('slug', $companySlug)->firstOrFail();

        $this->newLine();
        $this->table(['Registro', 'Total atual'], [
            ['Categorias', (string) ProductCategory::query()->where('company_id', $company->id)->count()],
            ['Produtos', (string) Product::query()->where('company_id', $company->id)->count()],
            ['Componentes', (string) MenuComponent::query()->where('company_id', $company->id)->count()],
            ['Cardápios semanais', (string) WeeklyMenu::query()->where('company_id', $company->id)->count()],
        ]);
        $this->info('Catálogo oficial restaurado sem exclusão de registros desconhecidos.');

        return self::SUCCESS;
    }

    /** @param class-string<Seeder> $seederClass */
    private function runSeeder(string $seederClass): void
    {
        $seeder = app($seederClass);
        $seeder->setContainer(app());
        $seeder();
    }

    private function recordCatalogVersion(Company $company): void
    {
        $setting = CompanySetting::query()->firstOrNew(['company_id' => $company->id]);
        $settings = $setting->settings ?? [];
        $settings['menu_catalog'] = [
            'version' => self::CATALOG_VERSION,
            'source' => 'official_seeders_and_cardapios_marmitex_semana_e_casa',
            'restored_at' => now()->toIso8601String(),
        ];

        $setting->fill([
            'status' => $setting->status ?: 'active',
            'timezone' => $setting->timezone ?: 'America/Sao_Paulo',
            'locale' => $setting->locale ?: 'pt_BR',
            'currency' => $setting->currency ?: 'BRL',
            'default_attendance_mode' => $setting->default_attendance_mode ?: 'manual',
            'settings' => $settings,
        ])->save();
    }
}
