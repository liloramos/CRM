# V1 Real QA Findings — 2026-09-04

## Contexto

QA real feito com ajuda da equipe do Restaurante Sol, com estilos espontâneos diferentes.

O teste foi valioso porque os clientes reais não seguirão o roteiro de desenvolvimento.

---

## Achado 1 — Greeting coloquial

Entrada observada:

`fala my friend`

Resultado:

resposta natural em uma conversa.

Conclusão:

greeting melhorou e deve permanecer coberto por regressão.

---

## Achado 2 — `N8` sem variante

Entrada:

`quero uma marmitex n8`

Resultado:

`Qual carne você deseja?`

Problema:

o domínio possui pelo menos N8 Casa e N8 Livre, com regras diferentes.

Esperado:

- reconhecer família N8;
- perguntar Casa/Livre quando o contexto não resolver;
- não ir direto para carne;
- preservar demais informações já fornecidas.

---

## Achado 3 — duas carnes

Resposta:

`almôndegas e porco`

Resultado:

fallback `não consegui entender` e human review.

Problema:

o resolver ainda trata match múltiplo como inválido em caminhos relevantes.

Esperado:

- suportar `meats[]`;
- respeitar cardinalidade canônica da variante;
- se permite 2, aceitar 2;
- se permite 1, pedir qual manter;
- zero review apenas pelo multi-select.

---

## Achado 4 — lista longa de itens

Entrada observada:

`arroz branco feijão macarrão vermelho mandioca abóbora banana cenoura couve almôndega e porco`

Resultado:

fallback/handoff.

Problema:

extração multi-entity ainda não cobre linguagem real em lista livre.

Esperado:

- components[] múltiplos;
- meats[] múltiplos;
- tolerância a pontuação imperfeita;
- validação posterior por produto;
- clarification de conflito, não fallback genérico.

---

## Achado 5 — `marmitex n8, qual o cardápio de hoje`

Resultado:

foi enviado um cardápio muito extenso contendo marmitas, combos, bebidas, sucos, açaí, buffet e carnes.

Pontos positivos:

- formatação melhor;
- buffet/carnes apareceram;
- catálogo/preço customer-facing ficou mais legível.

Problemas:

- resposta desproporcional à pergunta;
- N8 Casa/Livre não foi priorizado;
- categorias irrelevantes foram despejadas.

Novo requisito:

**progressive disclosure**.

### Pedido genérico de cardápio

Priorizar:

- marmitas;
- buffet;
- carnes;
- CTA informando demais categorias.

### `cardápio completo`

Pode mostrar todas as categorias em blocos.

### pergunta específica

Responder apenas o necessário + próximo passo.

---

## Achado 6 — burst real da Bia

Sequência:

1. pedido detalhado de N8;
2. `também quero uma água sem gás`;
3. `vocês fazem entrega?`;
4. endereço;
5. `quanto fica?`;
6. `posso pagar em dinheiro?`.

As mensagens foram enviadas próximas.

Resultado observado:

- vários alerts/reviews;
- um alert dizia faltar endereço mesmo depois do endereço estar presente;
- conversa permaneceu bloqueada/revisão.

Hipóteses a confirmar no código:

- jobs concorrentes por conversation;
- state snapshot obsoleto;
- alert criado em estado intermediário;
- missing-field alert não superseded depois;
- deduplicação insuficiente.

Não assumir a causa sem reproduzir.

---

## Achado 7 — alertas duplicados/genéricos

Foram vistos vários cards atuais:

`Revisão necessária`

incluindo mensagens genéricas e uma causa específica.

Problemas operacionais:

- repetição;
- ausência de ação clara;
- motivo já resolvido pode continuar atual;
- atendente não sabe o que precisa fazer.

Esperado:

um alert atual precisa responder:

- o que aconteceu?
- por que precisa de humano?
- o que a atendente deve fazer?

Histórico pode guardar eventos antigos.

Pendência atual deve ser deduplicada e refletir estado atual.

---

## Achado 8 — missing address não deveria virar review normal

Um card dizia:

`Pedido precisa de confirmação: falta informar o endereço de entrega.`

Missing address é um slot normal do fluxo.

Esperado:

- IA pede endereço;
- conversa continua automática;
- sem human review apenas por isso.

Se o endereço chega:

- missing field resolve;
- alert equivalente, se por algum motivo técnico existia, deve ser superseded/resolved.

---

## Achado 9 — Manual takeover

Manual foi usado para limpar/parar o estado de revisão.

Regra:

- Manual é takeover operacional;
- Manual não deve ser o mecanismo necessário para resolver um alert já sanado;
- em Manual, IA não responde automaticamente;
- ao voltar para Automático, não reprocessar retroativamente mensagens antigas.

---

## Conclusão técnica

O próximo trabalho NÃO deve ser uma simples edição de prompt.

Há indícios de ajustes necessários em:

- parsing multi-entity;
- candidate vs confirmed;
- resolução de variantes;
- cardinalidade de slots;
- multi-select;
- burst serialization/concurrency;
- stale state;
- alert lifecycle/dedup/actionability;
- progressive disclosure.

Preservar:

- pricing canônico;
- authority;
- payment protection;
- idempotência existente;
- formatting;
- LOCATION;
- Order/Payment workflows.

---

## Critério da próxima rodada

Os casos reais acima devem primeiro ser reproduzidos por testes focados.

A correção deve generalizar.

Não criar regex exclusiva para as frases do QA se a causa for estrutural.
