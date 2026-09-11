# V1 Acceptance Tests — Real Conversation Gate

## Objetivo

Este arquivo complementa os testes de aceitação já existentes e torna obrigatórios os casos descobertos no QA real.

Os testes automatizados NÃO substituem o smoke da Meta.

---

## 1. N8 ambígua

Entrada:

`quero uma marmitex n8`

Esperado:

- família N8 reconhecida;
- se Casa/Livre não estiver resolvido, perguntar variante;
- não ir diretamente para carne;
- zero human review;
- nenhuma escolha silenciosa.

---

## 2. N8 ambígua com dados adicionais

Entrada:

`quero n8 arroz feijão mandioca porco`

Esperado:

- N8 permanece candidata;
- componentes/carne preservados;
- pergunta Casa/Livre;
- depois revalida o que foi informado contra a variante.

---

## 3. Duas carnes

Pré-condição:

produto confirmado permite 2 carnes.

Entrada:

`almôndega e porco`

Esperado:

- `meats` contém as duas;
- automático;
- zero review.

---

## 4. Duas carnes quando só 1 é permitida

Entrada:

`almôndega e porco`

Esperado:

- não escolher silenciosamente;
- explicar limite;
- perguntar qual manter;
- zero review.

---

## 5. Lista longa sem pontuação

Entrada:

`arroz branco feijão macarrão vermelho mandioca abóbora banana cenoura couve almôndega e porco`

Esperado:

- múltiplos componentes reconhecidos;
- múltiplas carnes reconhecidas;
- nada perdido;
- nenhuma dependência de vírgula perfeita;
- conflito gera clarification, não handoff.

---

## 6. Classificação de categorias

Mensagem inclui:

- acompanhamento;
- carne;
- bebida.

Esperado:

- cada item na categoria correta;
- bebida não vira componente;
- acompanhamento não vira carne.

---

## 7. Menu contextual

Entrada:

`marmitex n8 qual o cardápio de hoje`

Esperado:

- reconhecer pergunta + interesse N8;
- explicar/clarificar Casa x Livre;
- buffet/carnes relevantes;
- sem catálogo completo irrelevante;
- próximo passo útil.

---

## 8. Cardápio genérico

Entrada:

`qual o cardápio de hoje`

Esperado:

- marmitas;
- buffet;
- carnes;
- CTA informando demais categorias;
- sem obrigação de listar todas as bebidas.

---

## 9. Cardápio completo

Entrada:

`manda o cardápio completo`

Esperado:

- demais categorias canônicas;
- até 3 mensagens;
- formatação mobile-first.

---

## 10. Burst real

Enviar rapidamente, antes das respostas:

1. pedido detalhado;
2. `também quero uma água sem gás`;
3. `vocês fazem entrega?`;
4. endereço;
5. `quanto fica?`;
6. `posso pagar em dinheiro?`.

Esperado:

- state final contém pedido + bebida + delivery + address;
- fee/total calculados quando possível;
- cash respondido se configurado;
- sem Order duplicado;
- sem Payment duplicado;
- sem review obsoleto.

---

## 11. Alert de endereço superseded

Pré-condição:

missing address existe no estado intermediário.

Depois:

endereço chega.

Esperado:

- missing address deixa de existir;
- nenhum alert atual continua dizendo que falta endereço;
- conversa segue automaticamente, salvo outra causa legítima.

---

## 12. Alert dedup

Mesmo problema detectado repetidamente.

Esperado:

- uma pendência atual;
- histórico pode guardar múltiplos eventos;
- UI não mostra vários cards atuais equivalentes.

---

## 13. Alert acionável

Quando review é realmente necessário:

deve haver, quando a causa é conhecida:

- motivo concreto;
- ação esperada;
- estado/evento relacionado suficiente para a equipe entender.

Fallback genérico só para falha técnica realmente sem causa mais específica.

---

## 14. Missing field normal

Falta:

- endereço;
- carne;
- variante;
- pagamento.

Esperado:

- IA pergunta;
- automático;
- sem human review apenas por missing field.

---

## 15. Manual takeover

Em Manual:

- IA não responde automaticamente.

Ao voltar para Automático:

- não reprocessar indevidamente mensagens antigas;
- não ressuscitar alert resolvido.

---

## 16. Regression gate

Preservar:

- formatting;
- reply_messages;
- `customer_explicit`;
- Casa incompatible clarification;
- LOCATION;
- delivery fee;
- Order idempotency;
- Payment idempotency;
- proof protection;
- prompt injection/authority;
- greeting;
- price/menu canonical facts.

---

## 17. Smoke real obrigatório

Depois de automatizados verdes, repetir com pelo menos duas pessoas diferentes, sem roteiro rígido.

Testar:

- frase curta;
- frase sem pontuação;
- duas carnes;
- N8 ambígua;
- pergunta no meio;
- burst;
- bebida adicional;
- entrega;
- endereço;
- pagamento.

Copilot só pode avançar para freeze se o resultado real acompanhar os testes.
