# V1 Operational Edge Cases — Real QA Additions

## 1. Serialização por conversa

Mensagens da mesma conversation não podem gerar mutações conflitantes simultâneas.

Investigar/reutilizar mecanismos existentes:

- DB transaction;
- row/advisory lock;
- queue uniqueness;
- event idempotency;
- version/timestamp checks.

Não criar mecanismo paralelo se a aplicação já possui equivalente.

---

## 2. State obsoleto

Antes de abrir review por missing field:

- verificar estado mais atual aplicável;
- não basear pendência em snapshot já resolvido por inbound posterior.

---

## 3. Alerts

Alert atual precisa ter lifecycle semântico.

Estados conceituais:

- open/actionable;
- resolved;
- superseded.

Histórico não precisa ser apagado.

Pendência atual precisa refletir a realidade atual.

---

## 4. Deduplicação

Mesma causa lógica não deve gerar múltiplos alerts atuais equivalentes.

Exemplo:

`missing_delivery_address`

detectado em dois eventos próximos:

- histórico pode ter duas detecções;
- UI deveria ter uma pendência atual.

---

## 5. Missing field não é exceção operacional

Missing address/meat/product/payment method é parte normal do slot filling.

Não transformar em human review sem outra causa.

---

## 6. Mudança de estado durante burst

Se mensagem posterior resolve algo:

- reavaliar próximo passo;
- não responder com pergunta já respondida;
- não manter alert anterior;
- não criar duplicidade.

---

## 7. Order/Payment

Burst e retry nunca podem:

- duplicar Order;
- duplicar Payment;
- trocar Payment associado ao proof;
- alterar total sem revalidação.

---

## 8. Manual takeover

Manual bloqueia outbound automático, mas não deve:

- apagar histórico;
- resolver finance alert indevidamente;
- ser necessário para esconder stale low-confidence/missing-field alert.

---

## 9. UI de review

Quando backend possui reason/code:

frontend deve mostrar razão operacional útil.

Evitar como padrão:

`A conversa precisa de conferência da equipe antes de continuar.`

quando existe motivo mais específico.

---

## 10. Safety order

Quando algo falha, preservar:

1. dinheiro;
2. pedido;
3. auditoria;
4. comunicação honesta;
5. automação.

Nunca inventar dado para “destravar” o fluxo.
