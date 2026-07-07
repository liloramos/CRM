<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $ticket['title'] }} {{ $ticket['order']['code'] ?? $ticket['order']['id'] }}</title>
    <style>
        @page {
            margin: 0;
            size: 80mm auto;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f2f2f2;
            color: #111;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
            font-size: 12px;
            line-height: 1.35;
        }

        .screen-actions {
            display: flex;
            gap: 8px;
            justify-content: center;
            padding: 12px;
        }

        .screen-actions button {
            border: 1px solid #222;
            background: #fff;
            color: #111;
            cursor: pointer;
            font: inherit;
            padding: 8px 12px;
        }

        .ticket {
            width: 80mm;
            min-height: 100vh;
            margin: 0 auto;
            padding: 5mm 4mm;
            background: #fff;
        }

        .center {
            text-align: center;
        }

        .strong {
            font-weight: 700;
        }

        .separator {
            border-top: 1px dashed #111;
            margin: 8px 0;
        }

        .row {
            display: flex;
            justify-content: space-between;
            gap: 8px;
        }

        .muted {
            color: #444;
        }

        .section-title {
            font-weight: 700;
            margin-bottom: 4px;
        }

        .item {
            margin-bottom: 8px;
        }

        .details {
            margin: 2px 0 0 10px;
            padding: 0;
        }

        .details li {
            margin: 1px 0;
        }

        @media print {
            body {
                background: #fff;
            }

            .screen-actions {
                display: none;
            }

            .ticket {
                margin: 0;
                min-height: auto;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="screen-actions">
        <button type="button" onclick="window.print()">Imprimir</button>
    </div>

    <main class="ticket">
        <header class="center">
            <div class="strong">{{ $ticket['restaurant']['name'] }}</div>
            <div class="strong">{{ $ticket['title'] }}</div>
            <div>{{ $ticket['printed_label'] }}</div>
        </header>

        <div class="separator"></div>

        <section>
            <div class="row">
                <span>Pedido</span>
                <strong>{{ $ticket['order']['code'] ?? $ticket['order']['id'] }}</strong>
            </div>
            <div class="row">
                <span>Origem</span>
                <span>{{ $ticket['order']['origin_channel'] ?? '-' }}</span>
            </div>
            <div class="row">
                <span>Tipo</span>
                <span>{{ $ticket['order']['fulfillment_type'] ?? '-' }}</span>
            </div>
            <div class="row">
                <span>Status</span>
                <span>{{ $ticket['order']['status'] ?? '-' }}</span>
            </div>
            <div class="row">
                <span>Impresso</span>
                <span>{{ $ticket['generated_at'] }}</span>
            </div>
        </section>

        <div class="separator"></div>

        <section>
            <div class="section-title">CLIENTE</div>
            @if (! empty($ticket['customer']['payer_name']))
                <div>Pagador: {{ $ticket['customer']['payer_name'] }}</div>
            @endif
            @if (! empty($ticket['customer']['payer_phone']))
                <div>Telefone: {{ $ticket['customer']['payer_phone'] }}</div>
            @endif
            @if (! empty($ticket['customer']['pickup_person_name']))
                <div>Retira: {{ $ticket['customer']['pickup_person_name'] }}</div>
            @endif
            @if (! empty($ticket['customer']['pickup_authorized_by']))
                <div>Autorizado por: {{ $ticket['customer']['pickup_authorized_by'] }}</div>
            @endif
            @if (! empty($ticket['customer']['delivery_recipient_name']))
                <div>Recebe: {{ $ticket['customer']['delivery_recipient_name'] }}</div>
            @endif
        </section>

        <div class="separator"></div>

        <section>
            <div class="section-title">ITENS</div>
            @foreach ($ticket['items'] as $item)
                <article class="item">
                    <div class="row strong">
                        <span>{{ $item['quantity'] }}x {{ $item['product_name'] }}</span>
                        <span>{{ $item['total_price'] }}</span>
                    </div>
                    @if (! empty($item['options']))
                        <ul class="details">
                            @foreach ($item['options'] as $option)
                                <li>{{ $option['quantity'] }}x {{ $option['name'] }} {{ $option['total_price'] }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <ul class="details">
                        @if (! empty($item['beneficiary_name']))
                            <li>Para: {{ $item['beneficiary_name'] }}</li>
                        @endif
                        @if (! empty($item['beneficiary_notes']))
                            <li>Obs pessoa: {{ $item['beneficiary_notes'] }}</li>
                        @endif
                        @if (! empty($item['item_notes']))
                            <li>Obs item: {{ $item['item_notes'] }}</li>
                        @endif
                        @foreach (['preferences' => 'Pref', 'restrictions' => 'Restr', 'removed_ingredients' => 'Remover', 'selected_components' => 'Comp'] as $field => $label)
                            @foreach ($item[$field] as $detail)
                                <li>{{ $label }}: {{ $detail }}</li>
                            @endforeach
                        @endforeach
                        @if (! empty($item['substitution_notes']))
                            <li>Subst: {{ $item['substitution_notes'] }}</li>
                        @endif
                    </ul>
                </article>
            @endforeach
        </section>

        <div class="separator"></div>

        <section>
            <div class="section-title">PAGAMENTO</div>
            <div class="row"><span>Forma</span><span>{{ $ticket['payment']['method'] ?? '-' }}</span></div>
            <div class="row"><span>Status</span><span>{{ $ticket['payment']['status'] ?? '-' }}</span></div>
            <div class="row"><span>Subtotal</span><span>{{ $ticket['payment']['subtotal'] }}</span></div>
            <div class="row"><span>Entrega</span><span>{{ $ticket['payment']['delivery_fee'] }}</span></div>
            <div class="row"><span>Ajustes</span><span>{{ $ticket['payment']['adjustments'] }}</span></div>
            <div class="row strong"><span>Total</span><span>{{ $ticket['payment']['total'] }}</span></div>
            <div class="row"><span>Pago</span><span>{{ $ticket['payment']['amount_paid'] }}</span></div>
            <div class="row"><span>Falta</span><span>{{ $ticket['payment']['amount_due'] }}</span></div>
            <div class="row"><span>Credito usado</span><span>{{ $ticket['payment']['credit_used'] }}</span></div>
            <div class="row"><span>Credito gerado</span><span>{{ $ticket['payment']['credit_generated'] }}</span></div>
        </section>

        <div class="separator"></div>

        <section>
            <div class="section-title">OBSERVACOES</div>
            @foreach (['general' => 'Geral', 'kitchen' => 'Cozinha', 'pickup' => 'Retirada', 'delivery' => 'Entrega', 'recurrence' => 'Historico'] as $field => $label)
                @if (! empty($ticket['notes'][$field]))
                    <div>{{ $label }}: {{ $ticket['notes'][$field] }}</div>
                @endif
            @endforeach
        </section>

        <div class="separator"></div>

        <footer class="center muted">
            <div>{{ $ticket['printer']['print_mode'] }}</div>
            @if (! empty($ticket['printer']['model']))
                <div>{{ $ticket['printer']['model'] }}</div>
            @endif
        </footer>
    </main>
</body>
</html>
