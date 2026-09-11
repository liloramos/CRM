# V1 Release Checklist

## Copilot blockers
- [ ] greeting normal
- [ ] menu completo
- [ ] buffet real
- [ ] quantidade de carnes explicada
- [ ] multi-intent
- [ ] short slot answer
- [ ] zero review indevido
- [ ] integridade do pedido
- [ ] address/location
- [ ] fee
- [ ] Order em Pedidos
- [ ] Pix
- [ ] proof
- [ ] human confirm
- [ ] delivery finished

## Pequenos blockers V1
- [ ] seller attribution
- [ ] pré-comanda bife blank behavior
- [ ] Epson 80mm smoke

## Freeze validation
- [ ] Copilot regressions
- [ ] WhatsApp inbound/webhook
- [ ] alerts
- [ ] orders
- [ ] payments
- [ ] delivery
- [ ] menu/pricing
- [ ] counter sale
- [ ] printing
- [ ] critical permissions
- [ ] composer lint
- [ ] npm lint
- [ ] npm build
- [ ] git diff --check

## Secrets
- [ ] no `.env` committed
- [ ] no Meta token
- [ ] no OpenAI key
- [ ] no private key
- [ ] no DB password

## Migrations
- [ ] `php artisan migrate:status`
- [ ] verify `2026_09_01_000012_add_weight_pricing_fields_to_order_items.php`
- [ ] review all V1 migrations
- [ ] production only `php artisan migrate --force`
- [ ] never fresh/refresh

## Git freeze
Após aprovação explícita:
- [ ] checkpoint/commit V1
- [ ] review diff
- [ ] push seguro
- [ ] promote to main
- [ ] optional tag

## Production release
- [ ] release directory
- [ ] Composer no-dev optimize
- [ ] frontend build
- [ ] prod `.env` directly on server
- [ ] APP_ENV=production
- [ ] APP_DEBUG=false
- [ ] APP_URL=https://app.chatbotcrm.dev.br
- [ ] shared storage/env
- [ ] migrations/seeds
- [ ] optimize/cache
- [ ] atomic switch current

## Worker / scheduler
- [ ] supervisor
- [ ] queue
- [ ] scheduler
- [ ] logs

## Meta official
Somente após app saudável:
- [ ] real number
- [ ] Phone Number ID
- [ ] WABA
- [ ] production token
- [ ] webhook
- [ ] verify token
- [ ] subscriptions
- [ ] controlled smoke

## Production smoke
- [ ] login
- [ ] conversations
- [ ] menu
- [ ] WhatsApp order
- [ ] fee
- [ ] Orders
- [ ] Pix/proof
- [ ] human confirm
- [ ] delivery
- [ ] Finance
- [ ] Caixa
- [ ] Epson

## Rollback
Rollback de código não reverte migration. Manter migrations backward-compatible e release anterior disponível.

---

## 12. Conversation robustness gate

Antes de aprovar V1:

- [ ] input sem pontuação
- [ ] erros de digitação
- [ ] abreviações
- [ ] multi-intent
- [ ] multi-slot
- [ ] correção/negação
- [ ] ordinais/contexto
- [ ] vários itens
- [ ] rajada de mensagens
- [ ] duplicate webhook
- [ ] out-of-order safety
- [ ] manual takeover
- [ ] alteração antes de payment
- [ ] alteração depois de proof
- [ ] status de pedido
- [ ] provider failure
- [ ] Meta send failure
- [ ] allergy-safe fallback
