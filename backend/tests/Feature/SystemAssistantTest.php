<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SystemAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_labia_identifies_itself_and_guides_common_system_tasks_without_provider(): void
    {
        [$manager, $company] = $this->manager();
        config(['chatbotcrm.ai.openai.api_key' => '']);

        $this->actingAs($manager)->postJson('/api/app/assistant', ['message' => 'Quem é você?'])
            ->assertOk()
            ->assertJsonPath('data.answer', 'Sou a Labia, assistente do CRM do '.$company->name.'. Posso orientar você sobre o sistema e abrir páginas permitidas ao seu perfil.')
            ->assertJsonPath('data.action', null);

        $cases = [
            ['Onde vejo os preços?', 'cardapio', 'Produtos e preços'],
            ['Como que eu mudo o valor de uma coca?', 'cardapio', 'Produtos e preços'],
            ['Como abro uma comanda?', 'caixa', 'comandas'],
            ['Como confirmo um Pix?', 'pagamentos', 'confirmação'],
            ['Como altero meu email?', 'perfil', 'e-mail'],
            ['Onde troco minha foto?', 'perfil', 'foto'],
            ['Como crio outro usuário?', 'configuracoes-usuarios', 'criar usuários'],
            ['Como deixo a Larissa como responsável por vendas?', 'configuracoes-usuarios', 'responsável por vendas'],
            ['Como cancelo uma venda?', 'pedidos', 'cancelamentos'],
        ];

        foreach ($cases as [$message, $target, $answerFragment]) {
            $response = $this->actingAs($manager)->postJson('/api/app/assistant', ['message' => $message])
                ->assertOk()
                ->assertJsonPath('data.action.target', $target);
            $this->assertStringContainsString($answerFragment, (string) $response->json('data.answer'));
        }
    }

    public function test_labia_keeps_recent_context_and_never_offers_forbidden_cta(): void
    {
        [$manager, $company] = $this->manager();
        config(['chatbotcrm.ai.openai.api_key' => '']);

        $this->actingAs($manager)->postJson('/api/app/assistant', [
            'message' => 'E categoria?',
            'recent_history' => [
                ['role' => 'user', 'text' => 'Onde adiciono produto?'],
                ['role' => 'assistant', 'text' => 'Abra Cardápio > Produtos e preços.'],
            ],
        ])->assertOk()->assertJsonPath('data.action.target', 'cardapio');

        $this->actingAs($manager)->postJson('/api/app/assistant', [
            'message' => 'E se eu quiser tirar ela depois?',
            'recent_history' => [
                ['role' => 'user', 'text' => 'Como deixo a Larissa responsável por vendas?'],
                ['role' => 'assistant', 'text' => 'Abra Configurações > Usuários e permissões.'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.action.target', 'configuracoes-usuarios')
            ->assertJsonFragment(['answer' => 'Em Configurações > Usuários e permissões, abra a usuária e desmarque a elegibilidade de responsável por vendas.']);

        $restricted = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($restricted)->postJson('/api/app/assistant', ['message' => 'Onde vejo os preços?'])
            ->assertOk()
            ->assertJsonPath('data.action', null)
            ->assertJsonFragment(['answer' => 'Essa área não está disponível para o perfil atual. Se precisar, peça orientação à gerência.']);
    }

    public function test_authenticated_user_receives_safe_navigation_and_unauthenticated_request_is_rejected(): void
    {
        [$manager] = $this->manager();
        $this->postJson('/api/app/assistant', ['message' => 'Onde confirmo um Pix?'])->assertUnauthorized();

        $this->actingAs($manager)->postJson('/api/app/assistant', ['message' => 'Onde confirmo um Pix?'])
            ->assertOk()
            ->assertJsonPath('data.action.type', 'show_pending_payments')
            ->assertJsonPath('data.action.target', 'pagamentos');
    }

    public function test_labia_gives_task_specific_menu_guidance_with_the_canonical_cta(): void
    {
        [$manager] = $this->manager();
        config(['chatbotcrm.ai.openai.api_key' => '']);

        $this->actingAs($manager)->postJson('/api/app/assistant', ['message' => 'Como mudo o valor de uma coca?'])
            ->assertOk()->assertJsonPath('data.action.target', 'cardapio')->assertJsonFragment(['answer' => 'Abra Cardápio > Produtos e preços, localize o produto e use Editar para alterar o preço.']);
        $this->actingAs($manager)->postJson('/api/app/assistant', ['message' => 'Como adiciono um produto novo?'])
            ->assertOk()->assertJsonPath('data.action.target', 'cardapio')->assertJsonFragment(['answer' => 'Abra Cardápio > Produtos e preços e use Novo produto para cadastrar o item.']);
        $this->actingAs($manager)->postJson('/api/app/assistant', [
            'message' => 'E uma categoria?',
            'recent_history' => [['role' => 'assistant', 'text' => 'Abra Cardápio > Produtos e preços.']],
        ])
            ->assertOk()->assertJsonPath('data.action.target', 'cardapio')->assertJsonFragment(['answer' => 'Abra Cardápio > Produtos e preços e use Nova categoria para cadastrar a categoria.']);
    }

    public function test_assistant_route_is_valid_and_common_questions_return_canonical_actions(): void
    {
        [$manager] = $this->manager();

        $cases = [
            ['Como encontro um cliente?', 'navigate_to_page', 'clientes'],
            ['Como altero o cardápio de amanhã?', 'open_menu', 'cardapio'],
            ['Onde confirmo um Pix?', 'show_pending_payments', 'pagamentos'],
            ['Como vejo as entregas de hoje?', 'show_deliveries', 'entregas'],
        ];

        foreach ($cases as [$message, $type, $target]) {
            $this->actingAs($manager)->postJson('/api/app/assistant', [
                'message' => $message,
                'current_route' => 'assistente',
            ])->assertOk()
                ->assertJsonPath('data.action.type', $type)
                ->assertJsonPath('data.action.target', $target);
        }
    }

    public function test_rbac_and_tenant_scoped_entity_actions_are_enforced(): void
    {
        [$manager, $company] = $this->manager();
        $attendant = User::factory()->create(['company_id' => $company->id]);
        $attendant->assignRole(Role::ATENDENTE);
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente local']);
        $order = $this->order($company, $customer, '20260831-0001', 1);
        $other = Company::query()->create(['name' => 'Outra empresa', 'slug' => 'outra-empresa']);
        $otherCustomer = Customer::query()->create(['company_id' => $other->id, 'name' => 'Cliente externo']);
        $this->order($other, $otherCustomer, '20260831-0002', 1);

        $this->actingAs($attendant)->postJson('/api/app/assistant', ['message' => 'Me leve ao Financeiro'])
            ->assertOk()->assertJsonPath('data.action', null);
        $this->actingAs($manager)->postJson('/api/app/assistant', ['message' => 'Abra o pedido 20260831-0001'])
            ->assertOk()->assertJsonPath('data.action.parameters.order_id', (string) $order->id);
        $this->actingAs($manager)->postJson('/api/app/assistant', ['message' => 'Abra o pedido 20260831-0002'])
            ->assertOk()->assertJsonPath('data.action', null);
        $this->actingAs($manager)->postJson('/api/app/assistant', ['message' => 'Abra este cliente', 'selected_context' => ['customer_id' => $customer->id]])
            ->assertOk()->assertJsonPath('data.action.parameters.customer_id', (string) $customer->id);
        $this->actingAs($manager)->postJson('/api/app/assistant', ['message' => 'Abra este cliente', 'selected_context' => ['customer_id' => $otherCustomer->id]])
            ->assertOk()->assertJsonPath('data.action', null);
    }

    public function test_provider_response_is_validated_and_failures_fall_back_without_side_effects_or_secrets(): void
    {
        [$manager] = $this->manager();
        config(['chatbotcrm.ai.openai.api_key' => 'secret-key', 'chatbotcrm.ai.openai.model' => 'gpt-test']);
        Http::fake(['https://api.openai.com/v1/responses' => Http::sequence()
            ->push(['output' => [['content' => [['text' => '{"answer":"Abra os relatórios.","action":{"type":"open_reports","target":"relatorios","parameters":{}}}']]]]])
            ->push(['output' => [['content' => [['text' => '{"answer":"Tente abrir.","action":{"type":"navigate_to_page","target":"https://example.com","parameters":{}}}']]]]])
            ->push([], 500)]);

        $this->actingAs($manager)->postJson('/api/app/assistant', ['message' => 'Como acompanho indicadores?'])
            ->assertOk()->assertJsonPath('data.action.target', 'relatorios')->assertJsonMissingPath('data.api_key');
        $this->actingAs($manager)->postJson('/api/app/assistant', ['message' => 'Onde encontro uma ferramenta diferente?'])
            ->assertOk()->assertJsonPath('data.action', null);
        Http::assertSentCount(2);

        $this->actingAs($manager)->postJson('/api/app/assistant', [
            'message' => 'Preciso de outra orientação',
            'current_route' => 'cardapio',
        ])->assertOk()
            ->assertJsonPath('data.action.target', 'cardapio')
            ->assertJsonFragment(['answer' => 'Os produtos, preços, categorias e disponibilidade ficam em Cardápio > Produtos e preços. A edição aparece somente para quem possui permissão de gestão.']);
        $this->actingAs($manager)->postJson('/api/app/assistant', ['message' => 'Confirme o Pix do pedido 10'])->assertOk()
            ->assertJsonPath('data.action.target', 'pagamentos');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('messages', 0);
        Http::assertSentCount(3);
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

    private function order(Company $company, Customer $customer, string $code, int $sequence): Order
    {
        return Order::query()->create(['company_id' => $company->id, 'payer_customer_id' => $customer->id, 'order_date' => '2026-08-31', 'daily_sequence' => $sequence, 'code' => $code]);
    }
}
