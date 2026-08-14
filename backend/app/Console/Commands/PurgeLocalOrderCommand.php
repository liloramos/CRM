<?php

namespace App\Console\Commands;

use App\Models\AutomationEvent;
use App\Models\Conversation;
use App\Models\ConversationAlert;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeLocalOrderCommand extends Command
{
    protected $signature = 'orders:purge-local {code : Código exato do pedido} {--confirm : Remove o único pedido informado}';

    protected $description = 'Remove um pedido específico somente em local/testing, preservando cliente, conversa e mensagens.';

    public function handle(): int
    {
        if (! in_array((string) config('app.env'), ['local', 'testing'], true)) {
            $this->error('O purge de pedido é permitido somente nos ambientes local ou testing.');

            return self::FAILURE;
        }

        $code = trim((string) $this->argument('code'));
        $order = Order::query()
            ->where('code', $code)
            ->withCount([
                'items',
                'payments',
                'paymentProofs',
                'creditMovements',
                'deliveryQuotes',
                'fragments',
                'printJobs',
                'printJobEvents',
            ])
            ->first();

        if (! $order instanceof Order) {
            $this->error('Pedido não encontrado para o código informado.');

            return self::FAILURE;
        }

        $summary = $this->summary($order);
        $this->table(['Registro', 'Quantidade'], collect($summary)->map(
            fn (int|string $value, string $key): array => [$key, $value],
        )->values()->all());
        $this->line('Cliente, conversa e mensagens do WhatsApp não serão removidos.');

        if (! $this->option('confirm')) {
            $this->warn('Dry-run concluído. Execute novamente com --confirm para remover somente este pedido.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($order): void {
            Conversation::query()
                ->where('active_order_id', $order->id)
                ->update(['active_order_id' => null]);

            $order->forceFill(['latest_print_job_id' => null])->save();
            $order->printJobs()->update(['parent_print_job_id' => null]);
            $order->printJobEvents()->delete();
            $order->printJobs()->delete();
            $order->delete();
        });

        $this->info('Pedido local removido com suas dependências transacionais. Cliente, conversa e mensagens foram preservados.');

        return self::SUCCESS;
    }

    /** @return array<string, int|string> */
    private function summary(Order $order): array
    {
        return [
            'pedido' => $order->code,
            'status' => $order->status,
            'itens' => (int) $order->items_count,
            'pagamentos' => (int) $order->payments_count,
            'comprovantes' => (int) $order->payment_proofs_count,
            'movimentos_de_credito' => (int) $order->credit_movements_count,
            'entregas' => (int) $order->delivery_quotes_count,
            'fragments' => (int) $order->fragments_count,
            'comandas' => (int) $order->print_jobs_count,
            'eventos_de_impressao' => (int) $order->print_job_events_count,
            'alertas_desvinculados' => ConversationAlert::query()->where('order_id', $order->id)->count(),
            'eventos_operacionais_desvinculados' => AutomationEvent::query()->where('order_id', $order->id)->count(),
        ];
    }
}
