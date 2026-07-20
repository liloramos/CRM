<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\MenuSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class DashboardDemoCommand extends Command
{
    protected $signature = 'dashboard:demo
        {action=seed : seed or clear}
        {--company=restaurante-sol : Company slug used for local demo data}';

    protected $description = 'Create or clear local dashboard demo data using real operational models';

    private const ORDER_PREFIX = 'DASH-DEMO-';

    private const CUSTOMER_EMAIL_PREFIX = 'dashboard.demo.';

    public function handle(): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('Dashboard demo data is blocked in production.');

            return self::FAILURE;
        }

        $action = (string) $this->argument('action');
        $company = Company::query()->firstOrCreate(
            ['slug' => (string) $this->option('company')],
            ['name' => 'Restaurante Sol'],
        );

        return match ($action) {
            'seed' => $this->seedDemoData($company),
            'clear' => $this->clearDemoData($company),
            default => throw new InvalidArgumentException('Use action seed or clear.'),
        };
    }

    private function seedDemoData(Company $company): int
    {
        DB::transaction(function () use ($company): void {
            $this->deleteDemoRows($company);
            $this->ensureMenu($company);

            $user = $this->ensureOperator($company);
            $products = Product::query()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->orderBy('display_order')
                ->limit(4)
                ->get();

            $customers = collect([
                $this->customer($company, 'balcao', 'Cliente Demo Balcao'),
                $this->customer($company, 'retirada', 'Cliente Demo Retirada'),
                $this->customer($company, 'entrega', 'Cliente Demo Entrega'),
                $this->customer($company, 'revisao', 'Cliente Demo Revisao'),
            ]);

            $conversations = [
                'active' => $this->conversation($company, $customers[0], [
                    'status' => 'open',
                    'automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC,
                    'automation_status' => Conversation::AUTOMATION_STATUS_ACTIVE,
                    'human_review_required' => false,
                    'started_at' => now()->subMinutes(42),
                ], 'Pedido iniciado pelo atendimento local.'),
                'manual' => $this->conversation($company, $customers[3], [
                    'status' => 'open',
                    'automation_mode' => Conversation::AUTOMATION_MODE_MANUAL,
                    'automation_status' => Conversation::AUTOMATION_STATUS_FALLBACK_REQUIRED,
                    'human_review_required' => true,
                    'manual_takeover_at' => now()->subMinutes(18),
                    'manual_takeover_by_user_id' => $user->id,
                    'started_at' => now()->subMinutes(26),
                ], 'Mensagem ambigua aguardando decisao humana.'),
                'closed' => $this->conversation($company, $customers[1], [
                    'status' => 'closed',
                    'automation_mode' => Conversation::AUTOMATION_MODE_ASSISTED,
                    'automation_status' => Conversation::AUTOMATION_STATUS_PAUSED,
                    'human_review_required' => false,
                    'started_at' => now()->subDay()->subHours(2),
                    'closed_at' => now()->subDay(),
                ], 'Atendimento demo encerrado no dia anterior.'),
            ];

            $this->order($company, $user, $customers[0], $conversations['active'], $products->get(0), [
                'code' => self::ORDER_PREFIX.'001',
                'daily_sequence' => 9101,
                'status' => Order::STATUS_READY_TO_PRINT,
                'origin_channel' => Order::CHANNEL_WHATSAPP,
                'fulfillment_type' => Order::FULFILLMENT_PICKUP,
                'fulfillment_status' => Order::FULFILLMENT_STATUS_PICKUP_PENDING,
                'print_status' => Order::PRINT_STATUS_PENDING,
                'payment_method' => Payment::METHOD_PIX,
                'payment_status' => Payment::ORDER_STATUS_PAID,
                'total_cents' => 3200,
                'amount_paid_cents' => 3200,
                'amount_due_cents' => 0,
                'payment_confirmed_at' => now()->subMinutes(12),
                'payment' => Payment::STATUS_CONFIRMED,
            ]);

            $this->order($company, $user, $customers[1], null, $products->get(1), [
                'code' => self::ORDER_PREFIX.'002',
                'daily_sequence' => 9102,
                'status' => Order::STATUS_IN_PREPARATION,
                'origin_channel' => Order::CHANNEL_COUNTER,
                'fulfillment_type' => Order::FULFILLMENT_COUNTER,
                'fulfillment_status' => Order::FULFILLMENT_STATUS_PICKUP_PENDING,
                'print_status' => Order::PRINT_STATUS_PRINTED,
                'printed_at' => now()->subMinutes(22),
                'payment_method' => Payment::METHOD_CASH,
                'payment_status' => Payment::ORDER_STATUS_PAID,
                'total_cents' => 1800,
                'amount_paid_cents' => 1800,
                'amount_due_cents' => 0,
                'payment_confirmed_at' => now()->subMinutes(25),
                'payment' => Payment::STATUS_CONFIRMED,
                'print_job' => PrintJob::STATUS_PRINTED,
            ]);

            $this->order($company, $user, $customers[2], null, $products->get(2), [
                'code' => self::ORDER_PREFIX.'003',
                'daily_sequence' => 9103,
                'status' => Order::STATUS_AWAITING_PAYMENT,
                'origin_channel' => Order::CHANNEL_MANUAL,
                'fulfillment_type' => Order::FULFILLMENT_DELIVERY,
                'fulfillment_status' => Order::FULFILLMENT_STATUS_PENDING,
                'delivery_status' => Order::DELIVERY_STATUS_ADDRESS_PENDING,
                'delivery_reference' => 'Endereco ficticio sanitizado para demo local.',
                'delivery_fee_cents' => 600,
                'print_status' => Order::PRINT_STATUS_PENDING,
                'payment_method' => null,
                'payment_status' => Payment::ORDER_STATUS_PENDING,
                'total_cents' => 2500,
                'amount_paid_cents' => 0,
                'amount_due_cents' => 2500,
                'payment' => Payment::STATUS_PENDING,
            ]);

            $this->order($company, $user, $customers[3], $conversations['manual'], $products->get(3), [
                'code' => self::ORDER_PREFIX.'004',
                'daily_sequence' => 9104,
                'status' => Order::STATUS_PAYMENT_PROOF_RECEIVED,
                'origin_channel' => Order::CHANNEL_WHATSAPP,
                'fulfillment_type' => Order::FULFILLMENT_PICKUP,
                'fulfillment_status' => Order::FULFILLMENT_STATUS_PICKUP_PENDING,
                'human_review_required' => true,
                'print_status' => Order::PRINT_STATUS_FAILED,
                'print_error_message' => 'Falha ficticia de impressao para validacao local.',
                'payment_method' => Payment::METHOD_PIX,
                'payment_status' => Payment::STATUS_PROOF_RECEIVED,
                'total_cents' => 4000,
                'amount_paid_cents' => 4000,
                'amount_due_cents' => 0,
                'last_payment_at' => now()->subMinutes(8),
                'payment' => Payment::STATUS_PROOF_RECEIVED,
                'print_job' => PrintJob::STATUS_FAILED,
            ]);

            $this->order($company, $user, $customers[1], $conversations['closed'], $products->get(0), [
                'code' => self::ORDER_PREFIX.'ANT-001',
                'daily_sequence' => 9105,
                'order_date' => now()->subDay()->toDateString(),
                'status' => Order::STATUS_CANCELLED,
                'origin_channel' => Order::CHANNEL_COUNTER,
                'fulfillment_type' => Order::FULFILLMENT_COUNTER,
                'print_status' => Order::PRINT_STATUS_WAIVED,
                'payment_method' => null,
                'payment_status' => Payment::ORDER_STATUS_UNPAID,
                'total_cents' => 0,
                'amount_paid_cents' => 0,
                'amount_due_cents' => 0,
                'cancelled_at' => now()->subDay(),
            ]);
        });

        $this->info('Dashboard demo data created for local use.');

        return self::SUCCESS;
    }

    private function clearDemoData(Company $company): int
    {
        $deleted = DB::transaction(fn (): int => $this->deleteDemoRows($company));

        $this->info("Dashboard demo data removed ({$deleted} order(s)).");

        return self::SUCCESS;
    }

    private function deleteDemoRows(Company $company): int
    {
        $orders = Order::query()
            ->where('company_id', $company->id)
            ->where('code', 'like', self::ORDER_PREFIX.'%')
            ->pluck('id');

        if ($orders->isNotEmpty()) {
            Order::query()->whereIn('id', $orders)->update(['latest_print_job_id' => null]);
            PrintJob::query()->whereIn('order_id', $orders)->delete();
            Payment::query()->whereIn('order_id', $orders)->delete();
            OrderStatusHistory::query()->whereIn('order_id', $orders)->delete();
            OrderItem::query()->whereIn('order_id', $orders)->delete();
            Order::query()->whereIn('id', $orders)->delete();
        }

        $customers = Customer::query()
            ->where('company_id', $company->id)
            ->where('email', 'like', self::CUSTOMER_EMAIL_PREFIX.'%@example.test')
            ->pluck('id');

        if ($customers->isNotEmpty()) {
            Conversation::query()->whereIn('customer_id', $customers)->delete();
            Customer::query()->whereIn('id', $customers)->delete();
        }

        return $orders->count();
    }

    private function ensureMenu(Company $company): void
    {
        if (Product::query()->where('company_id', $company->id)->exists()) {
            return;
        }

        app(MenuSeeder::class)->run();
    }

    private function ensureOperator(Company $company): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'atendente@example.test'],
            [
                'name' => 'Atendente Demo',
                'company_id' => $company->id,
                'password' => Hash::make('password'),
            ],
        );

        $role = Role::query()->where('name', Role::ATENDENTE)->first();

        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function customer(Company $company, string $key, string $name): Customer
    {
        return Customer::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'email' => self::CUSTOMER_EMAIL_PREFIX.$key.'@example.test',
            ],
            [
                'name' => $name,
                'phone' => null,
                'notes' => 'Registro ficticio criado pelo comando dashboard:demo.',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function conversation(Company $company, Customer $customer, array $attributes, string $message): Conversation
    {
        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            ...$attributes,
        ]);

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'content' => $message,
            'type' => 'text',
            'received_at' => $attributes['started_at'] ?? now(),
        ]);

        return $conversation;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(
        Company $company,
        User $user,
        Customer $customer,
        ?Conversation $conversation,
        ?Product $product,
        array $attributes,
    ): Order {
        $total = (int) $attributes['total_cents'];
        $createdAt = $attributes['created_at'] ?? Carbon::parse($attributes['order_date'] ?? now());

        $order = Order::query()->create([
            'company_id' => $company->id,
            'payer_customer_id' => $customer->id,
            'conversation_id' => $conversation?->id,
            'created_by_user_id' => $user->id,
            'order_date' => $attributes['order_date'] ?? now()->toDateString(),
            'daily_sequence' => $attributes['daily_sequence'],
            'code' => $attributes['code'],
            'status' => $attributes['status'],
            'origin_channel' => $attributes['origin_channel'],
            'entry_mode' => 'manual',
            'fulfillment_type' => $attributes['fulfillment_type'],
            'fulfillment_status' => $attributes['fulfillment_status'] ?? Order::FULFILLMENT_STATUS_PENDING,
            'delivery_status' => $attributes['delivery_status'] ?? null,
            'delivery_reference' => $attributes['delivery_reference'] ?? null,
            'delivery_fee_cents' => $attributes['delivery_fee_cents'] ?? 0,
            'priority' => Order::PRIORITY_NORMAL,
            'is_manual' => true,
            'is_fragmented' => false,
            'customer_confirmation_required' => false,
            'human_review_required' => $attributes['human_review_required'] ?? false,
            'general_notes' => 'Pedido demo local para validar dashboard operacional.',
            'kitchen_notes' => 'Observacao ficticia sanitizada.',
            'pickup_person_name' => 'Pessoa autorizada demo',
            'print_required' => true,
            'print_status' => $attributes['print_status'],
            'printed_at' => $attributes['printed_at'] ?? null,
            'print_error_message' => $attributes['print_error_message'] ?? null,
            'payment_method' => $attributes['payment_method'],
            'payment_status' => $attributes['payment_status'],
            'subtotal_cents' => $total,
            'adjustments_cents' => 0,
            'total_cents' => $total,
            'amount_paid_cents' => $attributes['amount_paid_cents'],
            'amount_due_cents' => $attributes['amount_due_cents'],
            'credit_used_cents' => 0,
            'credit_generated_cents' => 0,
            'currency' => 'BRL',
            'last_payment_at' => $attributes['last_payment_at'] ?? $attributes['payment_confirmed_at'] ?? null,
            'payment_confirmed_at' => $attributes['payment_confirmed_at'] ?? null,
            'confirmed_at' => $attributes['confirmed_at'] ?? now()->subMinutes(30),
            'cancelled_at' => $attributes['cancelled_at'] ?? null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product?->id,
            'product_name' => $product?->name ?? 'Marmita demo',
            'product_type' => $product?->product_type,
            'menu_rule_code' => $product?->menu_rule_code,
            'quantity' => 1,
            'unit_price_cents' => $total,
            'options_total_cents' => 0,
            'total_price_cents' => $total,
            'currency' => 'BRL',
            'item_notes' => 'Item ficticio para validacao local.',
            'beneficiary_name' => $customer->name,
            'sort_order' => 1,
        ]);

        if (isset($attributes['payment'])) {
            Payment::query()->create([
                'company_id' => $company->id,
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'created_by_user_id' => $user->id,
                'confirmed_by_user_id' => $attributes['payment'] === Payment::STATUS_CONFIRMED ? $user->id : null,
                'method' => $attributes['payment_method'] ?? Payment::METHOD_PIX,
                'provider' => Payment::PROVIDER_MANUAL,
                'status' => $attributes['payment'],
                'amount_cents' => $attributes['amount_paid_cents'] ?: $total,
                'confirmed_amount_cents' => $attributes['payment'] === Payment::STATUS_CONFIRMED ? $attributes['amount_paid_cents'] : 0,
                'amount_due_after_payment_cents' => $attributes['amount_due_cents'],
                'currency' => 'BRL',
                'paid_at' => $attributes['last_payment_at'] ?? $attributes['payment_confirmed_at'] ?? null,
                'confirmed_at' => $attributes['payment_confirmed_at'] ?? null,
                'notes' => 'Pagamento ficticio criado pelo comando dashboard:demo.',
                'metadata' => ['demo_dashboard' => true],
            ]);
        }

        if (isset($attributes['print_job'])) {
            $printJob = PrintJob::query()->create([
                'company_id' => $company->id,
                'order_id' => $order->id,
                'requested_by_user_id' => $user->id,
                'printed_by_user_id' => $attributes['print_job'] === PrintJob::STATUS_PRINTED ? $user->id : null,
                'job_type' => PrintJob::TYPE_ORDER_TICKET,
                'target_audience' => 'kitchen',
                'status' => $attributes['print_job'],
                'copy_number' => 1,
                'is_reprint' => false,
                'preview_url' => null,
                'html_content' => '<p>Comanda demo local sanitizada</p>',
                'text_content' => 'Comanda demo local sanitizada',
                'rendered_payload' => ['demo_dashboard' => true],
                'error_message' => $attributes['print_job'] === PrintJob::STATUS_FAILED ? 'Falha ficticia de impressao.' : null,
                'requested_at' => now()->subMinutes(20),
                'previewed_at' => now()->subMinutes(19),
                'printed_at' => $attributes['print_job'] === PrintJob::STATUS_PRINTED ? now()->subMinutes(18) : null,
                'failed_at' => $attributes['print_job'] === PrintJob::STATUS_FAILED ? now()->subMinutes(5) : null,
            ]);

            $order->update(['latest_print_job_id' => $printJob->id]);
        }

        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'from_status' => null,
            'to_status' => $attributes['status'],
            'reason' => 'demo_dashboard',
            'notes' => 'Status ficticio para validacao local da dashboard.',
            'metadata' => ['demo_dashboard' => true],
        ]);

        return $order;
    }
}
