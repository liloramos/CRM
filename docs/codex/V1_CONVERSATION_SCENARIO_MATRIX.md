# V1 Conversation Scenario Matrix

## Objetivo

Cobrir padrões reais de clientes que não aparecem em um smoke linear.

Cada cenário deve virar teste automatizado quando alterar estado/autoridade, ou smoke dirigido quando depender da Meta/infra.

---

## 1. Linguagem imperfeita

| Entrada | Esperado |
|---|---|
| `opa bom dia` | greeting, automático, zero review |
| `qro uma n8` | entender intenção de N8 ou clarificar variante Casa/Livre |
| `n8 livri` | aproximar para N8 Livre se inequívoco |
| `qnt custa n9` | responder preço canônico |
| `tem porco hj` | responder disponibilidade real |
| `aceita piks` | responder payment methods/Pix canônico |

---

## 2. Sem pontuação

Entrada:

`quero n8 livre arroz feijao batata doce porco entrega rua x 10 pix`

Esperado:

- extrair todos os slots seguros
- não exigir vírgula
- não gerar review
- perguntar somente o que faltar
- valores sempre backend

---

## 3. Multi-intent

Entrada:

`quero uma N8 Livre, qual o buffet de hoje?`

Esperado:

- selecionar produto
- responder buffet
- continuar pedido

Entrada:

`pode ser porco e é pra entrega`

Esperado:

- meat=porco
- fulfillment=delivery

Entrada:

`entrega na rua x 10 vou pagar pix`

Esperado:

- address
- payment_method
- calcular fee quando possível

---

## 4. Resposta contextual curta

Depois de lista:

`a segunda`

Depois de pergunta de carne:

`porco`

Depois de confirmação:

`isso`

Depois de fulfillment:

`entrega`

Esperado:

usar o contexto outbound recente, sem low-confidence indevido.

---

## 5. Correção

Entrada:

`na verdade troca o porco por frango`

Esperado:

- atualizar carne explicitamente
- preservar demais slots

Entrada:

`não é entrega vou buscar`

Esperado:

- fulfillment muda para pickup
- remover/recalcular fee de modo canônico
- total atualizado

Entrada:

`tira a coca`

Esperado:

- remover item correto
- total atualizado
- Payment pendente sincronizado se aplicável

---

## 6. Negação

Entrada:

`sem macarrão`

Esperado:

- marcar exclusão/preferência conforme regra do produto
- não apagar outros componentes

Entrada:

`não quero porco pode ser frango`

Esperado:

- não selecionar porco
- selecionar frango se válido

---

## 7. Produto ambíguo

Entrada:

`quero uma grande`

Se mais de um produto plausível:

- clarificar
- não review
- não escolher sozinho

Entrada:

`quero a de 16`

Se preço identifica produto único canônico:

- pode resolver
- senão clarificar

---

## 8. Vários itens

Entrada:

`2 N8 uma porco outra frango`

Esperado:

- quantidade 2
- customização separada por item
- sem vazamento de carne

Entrada:

`uma N8 e duas cocas`

Esperado:

- itens distintos
- quantidades corretas

---

## 9. Pergunta no meio do pedido

Estado parcial já existe.

Entrada:

`e a n9 quanto fica`

Esperado:

- responder
- não trocar produto atual automaticamente

Entrada:

`vocês aceitam pix`

Esperado:

- responder
- pending order intacto

---

## 10. Rejeição de sugestão

IA oferece carnes.

Cliente:

`nenhuma dessas`

Esperado:

- não escolher
- explicar alternativas/regras
- perguntar próximo passo
- humano apenas se não houver resolução possível

---

## 11. Fora de ordem

Entrada inicial:

`vou pagar pix e é entrega`

Esperado:

- guardar payment_method/fulfillment
- perguntar itens/address conforme necessário

Não reiniciar informações já dadas.

---

## 12. Endereço informal

Entrada:

`rua s 12 qd j lt 8 jardim das samambaias`

Esperado:

- preservar texto
- tentar resolver de forma canônica
- pedir somente detalhe faltante
- não inventar coordenada

Entrada:

`perto da praça`

Esperado:

- insuficiente para fee → pedir endereço/location

---

## 13. Endereço salvo / "o de sempre"

Entrada:

`manda no endereço de sempre`

Se houver exatamente um endereço canônico utilizável:

- pode apresentar para confirmação

Se houver zero ou múltiplos:

- perguntar
- nunca escolher arbitrariamente

---

## 14. Troco em dinheiro

Entrada:

`dinheiro troco pra 50`

Se cash estiver configurado:

- payment_method=cash
- registrar troco solicitado se estrutura canônica suportar

Se não houver campo seguro:

- preservar em nota operacional
- ou identificar necessidade de implementação
- nunca alterar total

---

## 15. Cancelamento

Antes de confirmação financeira:

`cancela meu pedido`

Esperado:

- workflow canônico de cancelamento
- sem pedido órfão

Depois de pagamento confirmado:

- ação protegida/humana conforme workflow
- não prometer estorno automático

---

## 16. Status do pedido

Entrada:

`meu pedido ja saiu?`

Esperado:

- consultar Order/Delivery ativo
- responder estado real
- não criar novo Order

---

## 17. Reabertura de conversa

Pedido anterior finished/cancelled.

Nova mensagem no dia seguinte:

`quero uma n8`

Esperado:

- não reutilizar Order finalizado
- novo estado/pedido

Se houver pedido ainda aberto:

- perguntar se deseja continuar quando necessário
- não duplicar silenciosamente

---

## 18. Burst de mensagens

Cliente manda rapidamente:

`quero n8`
`porco`
`entrega`
`rua x 10`

Esperado:

- ordem preservada
- state final contém todos dados
- nenhuma resposta baseada em state obsoleto
- sem duplicidade

---

## 19. Duplicate/out-of-order webhook

Mesmo Meta message ID duas vezes:

- efeito único

Webhooks chegam fora de ordem:

- não regredir state confirmado com mensagem antiga
- ordenar/validar por ids/timestamps/contexto

---

## 20. Provider/API failure

LLM falha/timeout:

- nenhuma mutação arriscada
- não confirmar ação não validada
- registrar falha
- mensagem segura quando possível
- human review operacional somente se realmente necessário

---

## 21. Meta send failure

Mensagem outbound falha:

- persistir estado de envio
- retry seguro
- não considerar cliente informado se Meta não aceitou
- não duplicar partes já entregues

---

## 22. Janela WhatsApp

Fora da janela permitida pela Meta:

- não fingir que mensagem foi enviada
- superfície operacional clara para equipe
- usar template apenas se fluxo/configuração suportar
- não quebrar Order/Payment

---

## 23. Cliente agressivo/off-topic

- manter tom profissional
- não retaliar
- não alterar pedido
- pedido explícito de humano pode transferir
- off-topic simples não precisa criar incidente financeiro

---

## 24. Alergia / restrição alimentar

Entrada:

`tem glúten?`
`tenho alergia a amendoim`
`é sem lactose?`

Somente responder se houver informação canônica confiável.

Se não houver:

- informar que não pode garantir
- orientar confirmação com equipe
- tratar como segurança alimentar
- não inventar ausência de alergênico

---

## 25. Pedido futuro / horário específico

Entrada:

`quero pra amanhã`
`entrega 12:30`

Se scheduling não existir canonicamente na V1:

- não prometer agendamento
- explicar limitação / encaminhar equipe conforme política

Não criar horário fictício.

---

## 26. Alteração após proof

Cliente enviou proof e depois:

`troca por uma n9`

Esperado:

- não alterar total silenciosamente
- protected review
- preservar Payment/proof
- equipe resolve diferença

---

## 27. Alteração após pagamento confirmado

Qualquer alteração com impacto financeiro:

- humana/protegida
- auditable
- não recriar Payment automaticamente

---

## 28. Proof sem pedido

Cliente envia imagem/comprovante sem Order/Payment identificável:

- não confirmar
- não criar Payment inventado
- pedir contexto ou alertar equipe

---

## 29. "Paguei"

Entrada:

`já paguei`

Sem proof/confirmação:

- não marcar pago
- orientar proof/equipe

---

## 30. Produto indisponível

Cliente pede item não disponível hoje:

- informar
- oferecer alternativas canônicas
- preservar demais escolhas
- sem review apenas por indisponibilidade

---

## 31. Preço/desconto

Entrada:

`faz por 15?`
`me dá desconto`

IA:

- não altera preço
- informa preço canônico
- se política permitir exceção humana, encaminhar
- não inventar promoção

---

## 32. Alteração de quantidade

`faz duas`
`na verdade só uma`

Esperado:

- quantidade atualizada
- total recalculado
- sem duplicar Order

---

## 33. Mensagem muito longa

Cliente envia todo pedido + observações em um bloco.

Esperado:

- extrair slots
- preservar observações
- resumir para confirmação
- perguntar apenas ambiguidade

---

## 34. Observações de cozinha

Exemplos:

- `pouco arroz`
- `capricha no feijao`
- `sem cebola`
- `molho separado`

Quando não alterarem regra/preço:

- preservar como observação estruturada/nota canônica se disponível

Quando conflitarem com produto:

- clarificar

---

## 35. Nome/cliente

Cliente diz:

`pedido pra Maria`

Não confundir nome do destinatário com item.

Se fluxo suportar destinatário:

- persistir apropriadamente

---

## Critério global

Se o sistema não consegue interpretar com segurança:

1. preservar state existente;
2. não executar mutação arriscada;
3. fazer a menor pergunta de clarificação possível;
4. human review só quando a conversa realmente não resolve ou há ação protegida.
