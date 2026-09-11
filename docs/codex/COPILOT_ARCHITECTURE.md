# Copilot Architecture — V1

```text
Meta / WhatsApp
      ↓
MetaWebhookPayloadParser
      ↓
Conversation + Message
      ↓
CopilotAutomationService
      ↓
Context / Knowledge
      ↓
LLM
      ↓
Normalizer
      ↓
Grounding / Authority
      ↓
Domain Workflows
      ↓
Persistence / Outbound
```

## Inbound
`MetaWebhookPayloadParser.php` deve preservar text, media/proof metadata suportado e LOCATION: latitude, longitude, name, address.

## Context Builder
`ConversationCopilotContextBuilder.php` deve fornecer conforme intenção: inbound/outbound recentes, expected slot, pending_order_state, OrderProposal, active Order, product, quantity, customer explicit selections, defaults, missing fields, fulfillment, address/location, fee, payment method/state, catálogo, buffet, carnes, company settings, operating hours e Pix público.

Evitar contexto gigante sem necessidade.

## Intent Resolution
Distinguir greeting, menu request, product question, buffet question, meat question, product selection, slot answer, fulfillment, address/location, payment, proof, human request, off-topic/security e multi-intent.

Multi-intent não pode ser reduzido arbitrariamente a uma única parte.

## Runtime model
Atual conhecido: `gpt-5.6-luna`. Responsável por linguagem/interpretação, não por autoridade comercial/financeira.

## Normalizer
`ConversationCopilotNormalizer.php`: preservar line breaks, limitar blank lines, até 3 reply_messages, sem squish destrutivo em conteúdo customer-facing.

## Grounding / Authority
`CopilotIntentGroundingGuard`, `CopilotAutomationAuthorityPolicy`, `CopilotSuggestedReplyGuard` bloqueiam price mutation, payment confirmation, admin action, prompt injection e unsupported facts.

## Structured state
`pending_order_state`, `AutomationEvent`, `OrderProposal`, `Order`.

Texto da IA nunca reconstrói pedido. Proveniência deve distinguir `customer_explicit`, `product_default`, `system_suggestion`, `informational_only`, `unresolved` ou equivalente.

## Builders
`CopilotCustomerFacingReplyBuilder`, `CopilotOrderClarificationReplyBuilder`, `CopilotProductClarificationReplyBuilder`, `CopilotMenuReplyBuilder` fazem fatos canônicos → mensagem humana. Nunca mensagem → estado.

## Orders
`OrderWorkflowService`: OrderProposal é intermediário; Order real surge quando operacionalmente suficiente e aparece em Pedidos. Usar active_order/idempotência existente.

## Delivery
`DeliveryRoutingService` + `DeliveryWorkflowService`: address/location → fee → total → lifecycle.

## Payments
`PaymentWorkflowService`: create/reuse Payment, proof, human confirm, idempotência.

## Alerts
`ConversationAlertService`, `ConversationOperationalStatusResolver`, `ConversationWorkflowService`.

Greeting, slot answer válida, pergunta normal e incompatibilidade resolvível não geram low-confidence acionável. Alertas protegidos de payment/proof/admin/human request não devem ser auto-resolvidos.

## Idempotência
Webhook duplicado safe, outbound retry parcial, single Order, single Payment e single confirmation effect.
