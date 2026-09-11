# Copilot Flow — V1

```text
Greeting / Menu / Pedido
        ↓
Intent + Multi-intent
        ↓
State update
        ↓
Information / Clarification
        ↓
Valid OrderProposal
        ↓
Fulfillment
        ↓
Address / LOCATION
        ↓
Delivery fee
        ↓
Summary
        ↓
Payment method
        ↓
Order real
        ↓
Payment
        ↓
Pix / Proof
        ↓
Human confirmation
        ↓
Preparation
        ↓
Pickup / Delivery
        ↓
Finished
```

## Greeting
Sempre automático. Nunca review por low-confidence.

## Menu request
Responder produtos, preços, composição, quantidade de carnes, Casa/Livre, buffet e carnes do dia, preservando pedido pendente.

## Multi-intent
Uma mensagem pode produzir múltiplos efeitos válidos. Ex.: `quero N8 Livre, qual o buffet de hoje?` → selecionar produto + responder buffet + perguntar próximo slot.

## Progressive slot filling
Slots: product, quantity, components, meat, extras, fulfillment, address/location, delivery_fee, payment_method. Podem chegar em qualquer ordem.

## Compatibility
Ao selecionar produto, validar escolhas existentes. Nunca substituir `customer_explicit`. Se conflito, clarificar.

## Meat slot
Se expected slot=meat, mensagens como `porco`, `pode ser porco`, `almôndega`, `quero frango` devem resolver o slot quando válidas.

## Fulfillment
Retirada: sem fee, resumo, pagamento.
Entrega: address/location, fee, resumo, pagamento.

## Address/LOCATION
Se suficiente, calcular fee. Se faltar só detalhe, perguntar apenas detalhe.

## Order materialization
OrderProposal é intermediário. Order real deve surgir quando houver dados suficientes para operação e aparecer em Pedidos. Não esperar confirmação financeira para existir.

## Payment
Após método, criar/reutilizar Payment; Pix usa total do Order e chave pública canônica.

## Proof
Associar, alertar, não confirmar.

## Human confirmation
Humano confirma Payment existente. A IA continua sem autoridade financeira.

## Pós-pagamento
Retirada: preparo → pronto → retirada → finished.
Entrega: status existentes equivalentes a ready_for_pickup → out_for_delivery → finished.

## Review
Só exceção real. Greeting, menu, multi-intent, slot answer, missing field e incompatibilidade resolvível são automáticos.
