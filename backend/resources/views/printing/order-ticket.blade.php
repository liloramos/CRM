<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $ticket['title'] }} {{ $ticket['order']['code'] }}</title>
    @php($paperWidthMm = max(40, (int) ($ticket['printer']['paper_width_mm'] ?? 80)))
    @php($ticketWidthMm = max(36, $paperWidthMm - 4))
    <style>
        @page { size: {{ $paperWidthMm }}mm auto; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eee; color: #000; font-family: Arial, sans-serif; font-size: 12px; line-height: 1.35; }
        .screen-actions { padding: 12px; text-align: center; }
        .screen-actions button { padding: 8px 14px; background: #fff; border: 1px solid #111; }
        .ticket { width:{{ $ticketWidthMm }}mm; margin: 0 auto; padding: 4mm 2.5mm 12mm; background: #fff; }
        .center { text-align: center; }
        .logo { display: block; max-width: 42mm; max-height: 22mm; margin: 0 auto 2mm; object-fit: contain; }
        .rule { border: 0; border-top: 1px dashed #000; margin: 7px 0; }
        .row { display: flex; justify-content: space-between; gap: 8px; }
        .right { text-align: right; }
        .section { margin: 7px 0; }
        .section-title { font-size: 11px; font-weight: 700; margin-bottom: 3px; }
        .item { margin: 6px 0; break-inside:avoid; }
        .item-main { font-weight: 700; }
        .details { margin: 2px 0 0 7mm; padding: 0; list-style: none; }
        .details li { margin: 1px 0; }
        .addition { padding-left: 3mm; }
        .total { font-size: 14px; font-weight: 700; padding-top: 4px; }
        .muted { font-size: 10px; color: #333; }
        .footer { margin-top: 10px; }
        .developer { font-size: 8px; color: #555; margin-top: 5px; line-height: 1.4; }
        .developer-contact { font-size: 7px; color: #666; margin-top: 1px; }
        .draft-heading { font-size: 15px; letter-spacing: .4px; }
        .manual-block { margin-top: 10px; }
        .manual-field { margin-top: 7px; }
        .manual-line { height: 7mm; border-bottom: 1px solid #000; }
        .manual-choice { display: flex; gap: 6mm; margin: 5px 0 8px; }
        .manual-box { display: inline-block; width: 4mm; height: 4mm; margin-right: 1.5mm; border: 1px solid #000; vertical-align: -0.8mm; text-align: center; line-height: 3.4mm; }
        @media print {
            html, body { width: {{ $paperWidthMm }}mm; background: #fff; color: #000; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .screen-actions { display: none !important; }
            .ticket { width:{{ $ticketWidthMm }}mm; margin: 0; padding: 4mm 2.5mm 12mm; }
            .section, .item, footer, .manual-block { break-inside:avoid; page-break-inside:avoid; }
        }
    </style>
    <script>(function(){if(new URLSearchParams(window.location.search).get('autoprint')==='1'){window.addEventListener('load',function(){window.setTimeout(function(){window.print()},200)},{once:true})}})()</script>
</head>
<body>
<div class="screen-actions"><button type="button" onclick="window.print()">Imprimir</button></div>
<main class="ticket">
    <header class="center">
        @if($ticket['restaurant']['logo_src'])
            <img class="logo" src="{{ $ticket['restaurant']['logo_src'] }}" alt="Sol Restaurante">
        @endif
        @if($ticket['restaurant']['phone'])
            <div>Telefone: {{ $ticket['restaurant']['phone'] }}</div>
        @endif
    </header>

    @if($ticket['print']['is_operational_counter_draft'])
        @php($draft = $ticket['print']['operational_draft'])
        <hr class="rule">
        <div class="center draft-heading"><strong>COMANDA DE BALCÃO</strong></div>
        <hr class="rule">
        <section class="section">
            <div class="row"><span>Comanda</span><strong>{{ $ticket['order']['code'] }}</strong></div>
            <div class="row"><span>Abertura</span><strong>{{ $ticket['order']['created_at'] }}</strong></div>
            <div class="row"><span>Modalidade</span><strong class="right">{{ $draft['modality_label'] }}</strong></div>
            @if($draft['price_per_kg'])
                <div class="row"><span>Tarifa</span><strong>{{ $draft['price_per_kg'] }}</strong></div>
            @elseif($draft['base_price'])
                <div class="row"><span>Base</span><strong>{{ $draft['base_price'] }}</strong></div>
            @endif
        </section>

        @if($draft['selected_components'] !== [] || $draft['notes'] !== [])
            <hr class="rule">
            <section class="section">
                <div class="section-title">INFORMAÇÕES JÁ REGISTRADAS</div>
                @if($draft['selected_components'] !== [])
                    <div>Componentes: {{ implode(', ', $draft['selected_components']) }}</div>
                @endif
                @foreach($draft['notes'] as $line)
                    <div>Obs.: {{ $line }}</div>
                @endforeach
            </section>
        @endif

        <hr class="rule">
        <section class="manual-block">
            <div class="section-title center">ANOTAÇÕES DA CHAPA</div>
            @if($draft['is_weight'])
                <div class="manual-field">Peso: __________________________ g</div>
            @endif
            <div class="manual-field">Bife adicional:</div>
            <div class="manual-choice">
                <span><i class="manual-box">{{ $draft['has_extra_beef'] ? 'X' : '' }}</i>Sim</span>
                <span><i class="manual-box"></i>Não</span>
            </div>
            <div class="manual-field">Quantidade de bife: ______________</div>
            <div class="manual-field">Outros adicionais:</div>
            <div class="manual-line"></div>
            <div class="manual-line"></div>
            <div class="manual-field">Observações:</div>
            <div class="manual-line"></div>
            <div class="manual-line"></div>
        </section>
        <hr class="rule">
        <footer class="center footer">
            <strong>Ficha operacional — pagamento ainda não registrado</strong>
        </footer>
    @else
        <hr class="rule">
        <div class="center">
            @if($ticket['print']['is_non_fiscal_receipt'])
                <strong>{{ $ticket['print']['document_label'] }}</strong><br>
                Venda Nº {{ $ticket['order']['code'] }}
            @else
                <strong>Pedido Nº {{ $ticket['order']['code'] }}</strong>
            @endif
            <br>{{ $ticket['print']['fulfillment_label'] }}
        </div>
        <hr class="rule">

        @if($ticket['print']['customer_name'] || $ticket['print']['customer_phone'])
            <section class="section">
                <div class="section-title">CLIENTE</div>
                @if($ticket['print']['customer_name'])<div>Nome: {{ $ticket['print']['customer_name'] }}</div>@endif
                @if($ticket['print']['customer_phone'])<div>Telefone: {{ $ticket['print']['customer_phone'] }}</div>@endif
            </section>
            <hr class="rule">
        @endif

        @if($ticket['print']['address'] !== [])
            <section class="section">
                <div class="section-title">ENDEREÇO DE ENTREGA</div>
                @foreach($ticket['print']['address'] as $line)<div>{{ $line }}</div>@endforeach
            </section>
            <hr class="rule">
        @endif

        <section class="section">
            <div class="section-title">ITENS DO PEDIDO</div>
            <div class="muted">Data: {{ $ticket['print']['order_date'] }} @if($ticket['print']['order_time']) · {{ $ticket['print']['order_time'] }}@endif</div>
            @foreach($ticket['print']['items'] as $item)
                <article class="item">
                    @if($ticket['print']['is_non_fiscal_receipt'])
                        <div class="item-main">{{ $item['name'] }}</div>
                        @if($item['weight'])
                            <div class="row"><span>Peso</span><span>{{ $item['weight'] }}</span></div>
                            <div class="row"><span>Preço por kg</span><span>{{ $item['price_per_kg'] }}</span></div>
                            <div class="row"><span>Subtotal</span><span>{{ $item['amount'] }}</span></div>
                        @else
                            <div class="row"><span>{{ $item['quantity'] }} x {{ $item['amount'] }}</span><span class="right">{{ $item['amount'] }}</span></div>
                        @endif
                    @else
                        <div class="row item-main"><span>{{ $item['quantity'] }}&nbsp; {{ $item['name'] }}</span><span class="right">{{ $item['amount'] }}</span></div>
                    @endif

                    @if($item['ingredients'] !== [] || $item['notes'] !== [] || $item['removed'] !== [])
                        <ul class="details">
                            @foreach($item['ingredients'] as $line)<li>{{ $line }}</li>@endforeach
                            @foreach($item['notes'] as $line)<li>Obs.: {{ $line }}</li>@endforeach
                            @if($item['removed'] !== [])<li><strong>Sem: {{ implode(', ', $item['removed']) }}</strong></li>@endif
                        </ul>
                    @endif

                    @if($ticket['print']['is_non_fiscal_receipt'] && $item['additions'] !== [])
                        <div class="section-title addition">Adicional:</div>
                    @endif
                    @foreach($item['additions'] as $addition)
                        <div class="row addition"><span>{{ $addition['quantity'] }}&nbsp; {{ $addition['name'] }}</span><span>{{ $addition['amount'] }}</span></div>
                    @endforeach
                    @if($ticket['print']['is_non_fiscal_receipt'] && $item['additions'] !== [])
                        <div class="row item-main addition"><span>Total do item</span><span>{{ $item['total'] }}</span></div>
                    @endif
                </article>
            @endforeach
        </section>
        <hr class="rule">

        @if($ticket['print']['notes'] !== [])
            <section class="section">
                <div class="section-title">OBSERVAÇÕES</div>
                @foreach($ticket['print']['notes'] as $line)<div>{{ $line }}</div>@endforeach
            </section>
            <hr class="rule">
        @endif

        <section class="section">
            <div class="row"><span>Itens do pedido</span><span>{{ $ticket['print']['items_total'] }}</span></div>
            @if($ticket['print']['additions_cents'] > 0)<div class="row"><span>Adicionais</span><span>{{ $ticket['print']['additions_total'] }}</span></div>@endif
            @if($ticket['print']['delivery_fee_cents'] > 0)<div class="row"><span>Taxa de entrega</span><span>{{ $ticket['print']['delivery_fee'] }}</span></div>@endif
            @if($ticket['print']['adjustments_cents'] !== 0)<div class="row"><span>Ajustes</span><span>{{ $ticket['print']['adjustments'] }}</span></div>@endif
            <hr class="rule">
            <div class="row total"><span>TOTAL</span><span>{{ $ticket['print']['total'] }}</span></div>
            @if($ticket['print']['payment_method'])
                <div class="row"><span>Pagamento: {{ $ticket['print']['payment_method'] }}</span>@if($ticket['print']['is_non_fiscal_receipt'])<span>{{ $ticket['print']['payment_amount'] }}</span>@endif</div>
            @endif
        </section>

        <footer class="center footer">
            @if($ticket['print']['fiscal_disclaimer'])
                <div class="muted"><strong>{{ $ticket['print']['fiscal_disclaimer'] }}</strong></div>
                <hr class="rule">
            @endif
            <strong>Obrigado pela preferência!</strong>
            <div>SOL RESTAURANTE</div>
            <div class="developer">Sistema desenvolvido por Murilo</div>
            <div class="developer-contact">Contato para soluções em sistemas de software: (62) 9 9619-1921</div>
        </footer>
    @endif
</main>
</body>
</html>
