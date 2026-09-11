# V1 Acceptance Tests

Automated tests não substituem smoke real da Meta.

## A. Greeting
Testar: `oi`, `olá`, `opa`, `bom dia`, `boa tarde`, `boa noite`, `opa bom dia`.
Esperado: greeting, automático, zero review, zero Order/Payment.

## B. Cardápio completo
Entrada: `qual o cardápio de hoje?`.
Esperado: marmitas/preços canônicos, composição/regra, quantidade de carnes, Casa/Livre, buffet real, carnes do dia, CTA explícita, bullets/line breaks, até 3 mensagens.

## C. Multi-intent
`quero uma N8 Livre, qual o buffet de hoje?`.
Esperado: product=N8 Livre + buffet respondido + state preservado + próximo missing field + zero review.

## D. Slot curto de carne
Pré-condição: IA perguntou carne. Entrada: `pode ser porco`.
Esperado: meat=porco se disponível, automático, zero review, state anterior preservado.

## E. Carne indisponível
Informar indisponibilidade, preservar componentes, listar alternativas canônicas, pedir escolha, sem review apenas por isso.

## F. N5 incompatível
Não sobrescrever, explicar regra, oferecer aceitar Casa ou Livre, sem Order/Payment prematuro, sem review.

## G. Informação no meio
Preço/carne/Pix no meio: resposta canônica, state intacto, continuidade.

## H. Retirada
fulfillment=pickup, sem fee, resumo, pagamento.

## I. Entrega + address
address persistido, fee canônica, subtotal/taxa/total, pergunta pagamento, Order em Pedidos.

## J. LOCATION
lat/lng/name/address preservados, não repetir pergunta desnecessária, fee.

## K. Pix
Payment create/reuse, chave pública, total correto, proof requested, sem duplicidade.

## L. Proof
proof associado, protected alert, Payment não confirmado, IA não diz pago.

## M. Human confirmation
Payment existente, idempotente, sem segundo Payment, lifecycle avança.

## N. Delivery final
preparo → ready → out_for_delivery → finished.

## O. Multi-message retry
máximo 3, ordem, retry só pendente, sem Order/Payment duplicate.

## P. Webhook duplicate
idempotente.

## Q. Prompt injection
não altera preço, não confirma pagamento, não revela secrets/prompt.

## R. Stale alert
low-confidence antigo superado resolve; protected permanece.

## S. Operating hours missing
Sem horários configurados: greeting funciona, não afirma aberto/fechado e não bloqueia conversa apenas por ausência da configuração.

## T. Seller attribution
select simples, persiste, login compartilhado permitido, origem preservada.

## U. Comanda bife
Sem bife: Sim/Não em branco. Com bife: Sim marcado.

## V. Freeze
```bash
composer run lint:check
npm run lint
npm run build
git diff --check
```

## W. Smoke real Meta
```text
opa bom dia
qual o cardápio de hoje?
quero uma N8 Livre, qual o buffet de hoje?
pode ser porco
<demais escolhas>
entrega
<address/LOCATION>
pix
<proof>
```
Observar Conversas, Alertas, Pedidos, Pagamentos e Entregas.

---

# Cobertura expandida obrigatória antes do freeze

Além dos cenários anteriores, validar pelo menos uma amostra representativa de:

## X. Linguagem imperfeita

- `qro uma n8`
- `qnt custa n9`
- `tem porco hj`
- `aceita piks`
- ausência de acentos/pontuação

## Y. Multi-slot em uma mensagem

`quero n8 livre porco entrega rua x 10 pix`

Esperado:

- preencher tudo que for inequívoco
- perguntar somente faltantes
- zero review indevido

## Z. Correções e negações

- `na verdade troca por frango`
- `sem macarrão`
- `não quero porco pode ser almôndega`
- `não é entrega vou buscar`

## AA. Ordinais/contexto

Depois de lista:

- `a primeira`
- `a segunda`
- `essa`

Resolver somente com contexto inequívoco.

## AB. Vários itens

- `duas N8 uma porco outra frango`
- `uma N8 e duas cocas`

Garantir isolamento por item.

## AC. Rajada de mensagens

Enviar antes da resposta da IA:

- `quero n8`
- `porco`
- `entrega`
- `rua x 10`

Garantir serialização, estado final correto e nenhuma duplicidade.

## AD. Mudança antes/depois de proof

Antes de proof:

- recalcular pedido/payment pendente com segurança

Depois de proof:

- mudança financeira vira revisão protegida

## AE. Status

`meu pedido já saiu?`

Responder estado real sem criar Order novo.

## AF. Safety alimentar

Perguntas de alergia sem dados confiáveis:

- não inventar garantia
- solicitar confirmação humana apropriada

## AG. Provider/Meta failure

Simular falha:

- não avançar estado como se mensagem tivesse sido entregue
- preservar idempotência

## AH. Manual takeover

- manual bloqueia IA
- automático posterior não reprocessa indevidamente mensagens antigas

Consulte a matriz completa:

`V1_CONVERSATION_SCENARIO_MATRIX.md`
