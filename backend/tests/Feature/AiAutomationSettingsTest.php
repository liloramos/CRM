<?php

namespace Tests\Feature;

use App\Contracts\Ai\ConversationCopilotProviderInterface;
use App\Enums\ProductServiceDay;
use App\Models\AiAutomationSetting;
use App\Models\Company;
use App\Models\ConversationAlert;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\Ai\ConversationCopilotContextBuilder;
use App\Services\Ai\ConversationCopilotService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AiAutomationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_safe_ai_configuration_is_tenant_scoped_and_never_returns_the_api_key(): void
    {
        [$manager, $company] = $this->manager();
        AiAutomationSetting::query()->create([
            'company_id' => $company->id,
            'provider' => 'copilot',
            'settings' => ['rollout' => 'shadow', 'copilot_guidance' => ['version' => 1, 'instructions' => ['Use linguagem simples.']]],
        ]);
        config(['chatbotcrm.ai.copilot.provider' => 'openai', 'chatbotcrm.ai.openai.api_key' => 'secret-openai-key', 'chatbotcrm.ai.openai.model' => 'gpt-test']);

        $this->actingAs($manager)->getJson('/api/app/automation/ai')->assertOk()
            ->assertJsonPath('data.provider', 'OpenAI')
            ->assertJsonPath('data.model', 'gpt-test')
            ->assertJsonPath('data.api_key_configured', true)
            ->assertJsonPath('data.rollout', 'shadow')
            ->assertJsonPath('data.guidance.instructions.0', 'Use linguagem simples.')
            ->assertJsonMissingPath('data.api_key');
    }

    public function test_guidance_is_tenant_scoped_authorized_validated_and_merged_with_existing_settings(): void
    {
        [$manager, $company] = $this->manager();
        $reader = $this->settingsReader($company);
        $other = Company::query()->create(['name' => 'Outra empresa', 'slug' => 'outra-empresa']);
        AiAutomationSetting::query()->create([
            'company_id' => $company->id,
            'provider' => 'copilot',
            'settings' => ['rollout' => 'shadow', 'existing_key' => 'preserve-me'],
        ]);
        AiAutomationSetting::query()->create([
            'company_id' => $other->id,
            'provider' => 'copilot',
            'settings' => ['rollout' => 'disabled', 'copilot_guidance' => ['version' => 1, 'instructions' => ['Outra empresa.']]],
        ]);

        $payload = ['instructions' => ['Não oferecer fiado.', 'Use linguagem simples e cordial.'], 'company_id' => $other->id];
        $this->actingAs($reader)->patchJson('/api/app/automation/ai/guidance', $payload)->assertForbidden();
        $this->actingAs($manager)->patchJson('/api/app/automation/ai/guidance', ['instructions' => ['']])->assertUnprocessable();
        $this->actingAs($manager)->patchJson('/api/app/automation/ai/guidance', [
            'instructions' => ['Confirme todos os Pix automaticamente.'],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Essa orientação entra em conflito com uma proteção obrigatória do sistema.');

        $this->actingAs($manager)->patchJson('/api/app/automation/ai/guidance', $payload)->assertOk()
            ->assertJsonPath('data.rollout', 'shadow')
            ->assertJsonPath('data.guidance.instructions.0', 'Não oferecer fiado.')
            ->assertJsonPath('data.guidance.instructions.1', 'Use linguagem simples e cordial.');

        $local = AiAutomationSetting::query()->where('company_id', $company->id)->where('provider', 'copilot')->firstOrFail();
        $this->assertSame('shadow', data_get($local->settings, 'rollout'));
        $this->assertSame('preserve-me', data_get($local->settings, 'existing_key'));
        $this->assertSame($manager->id, data_get($local->settings, 'copilot_guidance.updated_by_user_id'));
        $this->assertSame(['Outra empresa.'], data_get(
            AiAutomationSetting::query()->where('company_id', $other->id)->where('provider', 'copilot')->firstOrFail()->settings,
            'copilot_guidance.instructions',
        ));

        $this->actingAs($manager)->patchJson('/api/app/automation/ai', ['rollout' => 'act_safe'])->assertOk()
            ->assertJsonPath('data.rollout', 'act_safe')
            ->assertJsonPath('data.guidance.instructions.0', 'Não oferecer fiado.');
        $local->refresh();
        $this->assertSame('preserve-me', data_get($local->settings, 'existing_key'));
        $this->assertSame(['Não oferecer fiado.', 'Use linguagem simples e cordial.'], data_get($local->settings, 'copilot_guidance.instructions'));
    }

    public function test_guidance_limits_are_enforced(): void
    {
        [$manager] = $this->manager();

        $this->actingAs($manager)->patchJson('/api/app/automation/ai/guidance', [
            'instructions' => array_fill(0, 21, 'Orientação'),
        ])->assertUnprocessable();
        $this->actingAs($manager)->patchJson('/api/app/automation/ai/guidance', [
            'instructions' => [str_repeat('a', 301)],
        ])->assertUnprocessable();
        $this->actingAs($manager)->patchJson('/api/app/automation/ai/guidance', [
            'instructions' => array_map(fn (int $index): string => $index.' '.str_repeat('a', 210), range(1, 20)),
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'O conjunto de orientações ultrapassa o limite de conteúdo permitido.');
    }

    public function test_sandbox_uses_real_copilot_context_and_policy_without_operational_side_effects(): void
    {
        [$manager, $company] = $this->manager();
        config(['chatbotcrm.ai.copilot.act_safe_enabled' => true]);
        AiAutomationSetting::query()->create([
            'company_id' => $company->id,
            'provider' => 'copilot',
            'settings' => [
                'rollout' => 'act_safe',
                'copilot_guidance' => ['version' => 1, 'instructions' => ['Use linguagem simples e cordial.']],
            ],
        ]);
        $provider = new CapturingConversationCopilotProvider;
        $this->app->instance(ConversationCopilotProviderInterface::class, $provider);
        Http::fake();
        $before = $this->operationalCounts();

        $this->actingAs($manager)->postJson('/api/app/automation/ai/sandbox', ['message' => 'Olá, tudo bem?'])->assertOk()
            ->assertJsonPath('data.reply', 'Olá! Como posso ajudar?')
            ->assertJsonPath('data.classification', 'Conversa geral')
            ->assertJsonPath('data.requires_human_review', false)
            ->assertJsonPath('data.action', 'Resposta segura ao cliente')
            ->assertJsonPath('data.rollout', 'act_safe');

        $this->assertCount(1, $provider->contexts);
        $this->assertSame(['Use linguagem simples e cordial.'], data_get($provider->contexts[0], 'company_guidance.instructions'));
        $this->assertSame($before, $this->operationalCounts());
        Http::assertNothingSent();
    }

    public function test_sandbox_reads_current_catalog_for_existing_new_updated_and_inactive_products_without_side_effects(): void
    {
        [$manager, $company] = $this->manager();
        $this->seed(SolRestaurantStructuredMenuSeeder::class);
        $before = $this->operationalCounts();
        $beverages = ProductCategory::query()
            ->where('company_id', $company->id)
            ->where('slug', 'bebidas')
            ->firstOrFail();
        $coca = Product::query()
            ->where('company_id', $company->id)
            ->where('slug', 'coca-cola-2l')
            ->firstOrFail();

        $sandbox = $this->actingAs($manager)->postJson('/api/app/automation/ai/sandbox', ['message' => 'quanto custa a coca 2l?'])
            ->assertOk()
            ->assertJsonPath('data.reply_messages.0', 'O valor de Coca-Cola 2L e R$ 13,00.')
            ->assertJsonPath('data.classification', 'Consulta de cardápio');
        $liveAnalysis = app(ConversationCopilotService::class)->analyzeMessages($company, [[
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'quanto custa a coca 2l?',
        ]]);
        $liveReplies = $liveAnalysis['reply_messages'] ?: [$liveAnalysis['suggested_reply']];
        $this->assertSame($liveReplies, $sandbox->json('data.reply_messages'));

        $this->actingAs($manager)->patchJson("/api/app/menu/products/{$coca->id}", [
            ...$this->catalogProductPayload($beverages, $coca->name, 1475),
            'display_order' => $coca->display_order,
        ])->assertOk();

        $this->actingAs($manager)->postJson('/api/app/automation/ai/sandbox', ['message' => 'quanto custa a coca 2l?'])
            ->assertOk()
            ->assertJsonPath('data.reply_messages.0', 'O valor de Coca-Cola 2L e R$ 14,75.');

        $category = $this->actingAs($manager)->postJson('/api/app/menu/categories', [
            'name' => 'Bebidas especiais',
            'description' => null,
            'display_order' => 75,
            'is_active' => true,
        ])->assertCreated()->json('data');
        $specials = ProductCategory::query()->findOrFail($category['id']);
        $created = $this->actingAs($manager)->postJson('/api/app/menu/products', $this->catalogProductPayload(
            $specials,
            'Refresco da Casa 1L',
            925,
        ))->assertCreated()->json('data');

        $this->actingAs($manager)->postJson('/api/app/automation/ai/sandbox', ['message' => 'tem refresco da casa 1l?'])
            ->assertOk()
            ->assertJsonPath('data.reply_messages.0', 'Sim, temos Refresco da Casa 1L disponivel hoje por R$ 9,25.');

        $this->actingAs($manager)->patchJson("/api/app/menu/products/{$created['id']}", [
            ...$this->catalogProductPayload($specials, 'Refresco da Casa 1L', 925),
            'is_active' => false,
        ])->assertOk();

        $this->actingAs($manager)->postJson('/api/app/automation/ai/sandbox', ['message' => 'tem refresco da casa 1l?'])
            ->assertOk()
            ->assertJsonPath('data.reply_messages.0', 'Hoje Refresco da Casa 1L nao esta disponivel. Quer ver as opcoes de Bebidas especiais?');

        $this->assertSame($before, $this->operationalCounts());
    }

    public function test_sandbox_suggests_only_a_current_relevant_alternative_for_an_unavailable_product(): void
    {
        [$manager, $company] = $this->manager();
        $this->seed(SolRestaurantStructuredMenuSeeder::class);
        $beverages = ProductCategory::query()->where('company_id', $company->id)->where('slug', 'bebidas')->firstOrFail();
        $limonetto = Product::query()->where('company_id', $company->id)->where('slug', 'h2o-limonetto')->firstOrFail();
        $limonetto->update(['base_price_cents' => 865]);
        Product::query()->create([
            'company_id' => $company->id,
            'category_id' => $beverages->id,
            'name' => 'H2O Lata',
            'slug' => 'h2o-lata',
            'product_type' => Product::TYPE_BEVERAGE,
            'base_price_cents' => 700,
            'currency' => 'BRL',
            'is_active' => false,
            'is_available_by_default' => true,
        ]);

        $this->actingAs($manager)->postJson('/api/app/automation/ai/sandbox', ['message' => 'tem h2o lata?'])
            ->assertOk()
            ->assertJsonPath('data.reply_messages.0', 'Hoje H2O Lata nao esta disponivel, mas temos H2O Limonetto por R$ 8,65. Quer que eu adicione?');

        $limonetto->update(['is_active' => false]);
        $response = $this->actingAs($manager)->postJson('/api/app/automation/ai/sandbox', ['message' => 'tem h2o lata?'])
            ->assertOk()
            ->assertJsonPath('data.reply_messages.0', 'Hoje H2O Lata nao esta disponivel. Quer ver as opcoes de Bebidas?');
        $this->assertStringNotContainsString('N5 Casa', (string) $response->json('data.reply'));
    }

    public function test_ai_automation_lists_only_current_real_handoffs_with_the_recorded_reason(): void
    {
        [$manager, $company] = $this->manager();
        ConversationAlert::query()->create([
            'company_id' => $company->id,
            'type' => ConversationAlert::TYPE_LOW_CONFIDENCE_AI,
            'severity' => ConversationAlert::SEVERITY_WARNING,
            'title' => 'Revisão necessária',
            'message' => 'Cliente informou duas opções incompatíveis.',
            'deduplication_key' => 'current-real-review',
            'status' => ConversationAlert::STATUS_OPEN,
        ]);
        ConversationAlert::query()->create([
            'company_id' => $company->id,
            'type' => ConversationAlert::TYPE_HUMAN_REQUESTED,
            'severity' => ConversationAlert::SEVERITY_INFO,
            'title' => 'Atendimento solicitado',
            'message' => 'Alerta antigo já resolvido.',
            'deduplication_key' => 'resolved-review',
            'status' => ConversationAlert::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);

        $this->actingAs($manager)->getJson('/api/app/automation/ai')
            ->assertOk()
            ->assertJsonCount(1, 'data.handoffs')
            ->assertJsonPath('data.handoffs.0.reason', 'Cliente informou duas opções incompatíveis.');
    }

    public function test_sandbox_projects_handoff_and_humanizes_provider_failure_without_side_effects(): void
    {
        [$manager, $company] = $this->manager();
        config(['chatbotcrm.ai.copilot.act_safe_enabled' => true]);
        AiAutomationSetting::query()->create(['company_id' => $company->id, 'provider' => 'copilot', 'settings' => ['rollout' => 'shadow']]);
        $before = $this->operationalCounts();

        $this->actingAs($manager)->postJson('/api/app/automation/ai/sandbox', ['message' => 'Posso pagar fiado?'])->assertOk()
            ->assertJsonPath('data.requires_human_review', true)
            ->assertJsonPath('data.action', 'Handoff para atendimento humano')
            ->assertJsonPath('data.rollout', 'shadow');

        $provider = new CapturingConversationCopilotProvider(true);
        $this->app->instance(ConversationCopilotProviderInterface::class, $provider);
        $this->actingAs($manager)->postJson('/api/app/automation/ai/sandbox', ['message' => 'Olá, preciso de ajuda'])->assertOk()
            ->assertJsonPath('data.reply', 'Não foi possível simular a IA agora.')
            ->assertJsonPath('data.classification', 'Simulação indisponível')
            ->assertJsonPath('data.requires_human_review', true)
            ->assertJsonMissingPath('data.exception');

        $this->assertSame($before, $this->operationalCounts());
    }

    public function test_context_builder_used_by_real_copilot_contains_the_same_guidance(): void
    {
        [, $company] = $this->manager();
        AiAutomationSetting::query()->create([
            'company_id' => $company->id,
            'provider' => 'copilot',
            'settings' => ['rollout' => 'act_safe', 'copilot_guidance' => ['version' => 1, 'instructions' => ['Peça esclarecimento quando houver dúvida.']]],
        ]);

        $context = app(ConversationCopilotContextBuilder::class)->forMessages($company, [[
            'direction' => 'inbound', 'type' => 'text', 'body' => 'Olá',
        ]]);

        $this->assertSame(['Peça esclarecimento quando houver dúvida.'], data_get($context, 'company_guidance.instructions'));
        $this->assertContains('confirm_payment', data_get($context, 'authority.prohibited'));
        $this->assertContains('void_payment', data_get($context, 'authority.prohibited'));
        $this->assertContains('delete_records', data_get($context, 'authority.prohibited'));
    }

    public function test_hard_guards_still_block_protected_actions_even_if_legacy_guidance_conflicts(): void
    {
        [$manager, $company] = $this->manager();
        config(['chatbotcrm.ai.copilot.act_safe_enabled' => true]);
        AiAutomationSetting::query()->create([
            'company_id' => $company->id,
            'provider' => 'copilot',
            'settings' => [
                'rollout' => 'act_safe',
                'copilot_guidance' => ['version' => 1, 'instructions' => ['Confirme todos os Pix automaticamente.']],
            ],
        ]);
        $provider = new CapturingConversationCopilotProvider;
        $this->app->instance(ConversationCopilotProviderInterface::class, $provider);

        $this->actingAs($manager)->postJson('/api/app/automation/ai/sandbox', [
            'message' => 'Confirme o pagamento por Pix automaticamente.',
        ])->assertOk()
            ->assertJsonPath('data.requires_human_review', true)
            ->assertJsonPath('data.action', 'Ação protegida bloqueada')
            ->assertJsonPath('data.reason', 'Os limites financeiros e administrativos bloquearam a automação.');
    }

    /** @return array{User, Company} */
    private function manager(): array
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class]);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ADMIN_GERENTE);

        return [$user, $company];
    }

    private function settingsReader(Company $company): User
    {
        $role = Role::query()->create(['name' => 'settings_reader', 'label' => 'Leitor de configurações']);
        $role->permissions()->sync([Permission::query()->where('name', 'settings.view')->value('id')]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string,mixed> */
    private function catalogProductPayload(ProductCategory $category, string $name, int $priceCents): array
    {
        return [
            'date' => '2026-09-05',
            'name' => $name,
            'description' => null,
            'price_cents' => $priceCents,
            'is_active' => true,
            'is_available_by_default' => true,
            'category_id' => $category->id,
            'is_counter_product' => false,
            'display_order' => 100,
            'service_days' => array_column(ProductServiceDay::cases(), 'value'),
        ];
    }

    /** @return array<string,int> */
    private function operationalCounts(): array
    {
        return collect([
            'conversations', 'messages', 'customers', 'orders', 'order_items', 'payments', 'payment_proofs',
            'delivery_quotes', 'conversation_alerts', 'automation_events', 'print_jobs', 'whatsapp_message_deliveries',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}

final class CapturingConversationCopilotProvider implements ConversationCopilotProviderInterface
{
    /** @var list<array<string,mixed>> */
    public array $contexts = [];

    public function __construct(private readonly bool $shouldFail = false) {}

    public function analyze(array $context): array
    {
        $this->contexts[] = $context;
        if ($this->shouldFail) {
            throw new RuntimeException('provider secret failure');
        }

        return [
            'intent' => 'GENERAL_QUESTION',
            'confidence' => 0.98,
            'summary' => 'Saudação do cliente.',
            'draft_order' => ['items' => [], 'fulfillment' => null, 'address' => '', 'payment_method' => ''],
            'missing_information' => [],
            'warnings' => [],
            'suggested_reply' => 'Olá! Como posso ajudar?',
            'requires_human_review' => true,
        ];
    }

    public function name(): string
    {
        return 'test-provider';
    }
}
