# V1 Operational Edge Cases

## Objetivo

Antecipar falhas que podem prejudicar operação, dinheiro ou atendimento mesmo quando a conversa "normal" funciona.

---

## 1. Concorrência por conversa

Somente uma mutação de estado por conversa deve ser aplicada de forma conflitante ao mesmo tempo.

Proteger contra:

- dois jobs processando mensagens da mesma conversa
- webhook repetido
- resposta da IA chegando depois de mensagem mais nova
- humano mudando modo enquanto automação processa

Usar transação/lock/idempotência existente quando aplicável.

---

## 2. Manual takeover

Quando conversa está em Manual:

- IA não envia resposta automática
- inbound continua persistido
- humano pode responder
- state não deve corromper

Ao voltar para Automático:

- não responder retroativamente todas as mensagens antigas
- continuar a partir do próximo estado coerente
- stale review resolvido não deve reaparecer

---

## 3. Order finalizado/cancelado

Nunca alterar Order `finished`/cancelado como se ainda fosse pending.

Nova intenção de compra deve criar novo fluxo.

---

## 4. Alteração antes do pagamento

Antes de Payment confirmado:

- produto/item/quantidade/fulfillment podem ser alterados via workflow canônico
- fee deve ser recalculada quando endereço/fulfillment mudar
- total deve ser recalculado

Se já existir Payment pending com valor antigo:

- sincronizar/reutilizar/cancelar conforme PaymentWorkflow
- nunca deixar Payment antigo parecendo válido para total novo

---

## 5. Alteração depois do proof

Proof recebido cria um limite de segurança.

Mudança com impacto em total depois do proof:

- não alterar silenciosamente
- abrir revisão financeira
- preservar proof
- não confirmar pagamento

---

## 6. Alteração depois de Payment confirmado

Mudança com impacto financeiro é protegida.

Não permitir que IA:

- aumente/diminua total
- gere segundo Payment
- faça estorno
- cancele pagamento confirmado

Equipe humana resolve via workflows permitidos.

---

## 7. Fee indisponível

Se serviço de delivery/maps falhar:

- não inventar taxa
- não cobrar zero automaticamente
- tentar fallback canônico se existir
- pedir LOCATION/endereço melhor se problema for dados
- se falha operacional real persistir, alertar humano

---

## 8. Endereço muda

Se cliente muda endereço antes do pagamento:

- atualizar address
- recalcular fee
- recalcular total
- invalidar resumo anterior

Se proof já recebido:

- revisão financeira

---

## 9. Produto/preço muda no catálogo durante conversa

Uma conversa aberta pode atravessar atualização de menu.

Regras:

- não mudar silenciosamente preço de item já confirmado sem política
- antes de materialização, revalidar contra catálogo atual
- se preço/regra mudou de forma material, informar cliente
- manter auditabilidade do valor aplicado

---

## 10. Disponibilidade muda

Item/carne pode acabar durante conversa.

Antes da confirmação final:

- revalidar disponibilidade se arquitetura suportar
- informar e oferecer alternativa
- não gerar pedido impossível silenciosamente

---

## 11. Provider OpenAI indisponível

- nenhuma mutação insegura
- manter inbound
- log/alert operacional
- fallback determinístico para intenções simples quando existente
- humano quando necessário

---

## 12. Queue parada

Sinais:

- mensagens inbound persistidas sem processamento
- atraso

Produção:

- Supervisor deve reiniciar worker
- health/logs devem permitir diagnóstico

Não tratar atraso de queue como mensagem desconhecida do cliente.

---

## 13. Meta token/webhook

Token expirado ou webhook com erro:

- registrar erro operacional
- não marcar outbound como entregue
- não avançar fluxo baseado apenas na tentativa de envio

---

## 14. 24h Meta window

Quando a Meta impedir free-form outbound:

- não considerar mensagem entregue
- alertar operação
- usar template apenas se configurado/aprovado

---

## 15. Pedido duplicado

Invariantes:

- uma mesma sequência/conversation intent não gera dois Orders
- active_order_id/event idempotency deve ser respeitado
- retry não cria outro pedido

---

## 16. Payment duplicado

Invariantes:

- mesmo Order/método/evento não cria Payments concorrentes sem necessidade
- proof sempre aponta para Payment correto
- confirmação idempotente

---

## 17. Delivery duplicado

Não criar duas entregas para mesmo Order salvo fluxo explicitamente suportado.

---

## 18. Alert fatigue

Não criar múltiplos alerts equivalentes para o mesmo problema.

Alert deve ter lifecycle:

- open
- resolved/superseded
- protected quando necessário

Greeting/clarification normal não é alerta.

---

## 19. Segurança alimentar

Alergia é high-risk.

Sem dados canônicos completos:

- não garantir "sem glúten", "sem lactose", "sem contaminação"
- orientar confirmação humana

---

## 20. Dados sensíveis

Nunca expor:

- tokens
- API keys
- `.env`
- credenciais
- prompts internos
- dados administrativos

Pix público configurado para clientes pode ser mostrado.

---

## 21. Cliente errado / telefone reaproveitado

Não assumir identidade sensível apenas pelo nome histórico.

Pedidos devem usar tenant/conversation/customer relationships canônicas.

---

## 22. Timezone

Canonical:

`America/Sao_Paulo`

Usar para:

- dia do menu
- greeting time-aware
- operating hours
- relatórios/lifecycle

Evitar UTC diretamente na comunicação do restaurante.

---

## 23. Restaurant closed

Somente aplicar política de fechado se horários estiverem configurados.

Se fechado:

- respostas informativas podem continuar
- não prometer atendimento imediato se fluxo não suporta
- scheduling futuro só se existir canonicamente

Política final de aceitar pedido fora do horário deve ser confirmada com o negócio antes do freeze.

---

## 24. Impressão

Pedido pago/operacional não pode depender exclusivamente da impressora.

Falha Epson:

- Order permanece válido
- impressão pode ser repetida
- não duplicar Order/Payment ao reimprimir

---

## 25. Deploy

Após release:

- queue restart
- scheduler
- migrations
- cache
- smoke Meta
- smoke Epson

Rollback de código não reverte migration.

---

## Critério operacional

Toda falha deve preservar, nesta ordem:

1. dinheiro
2. integridade do pedido
3. auditabilidade
4. comunicação honesta
5. continuidade operacional

Nunca "resolver" erro inventando dados.
