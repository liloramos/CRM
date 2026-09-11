# Copilot Parsing and State Contract — V1

## Objetivo

Definir como linguagem espontânea de WhatsApp vira intenções, entidades e alterações de estado sem exigir formulário, pontuação perfeita ou uma escolha por mensagem.

O QA real de 2026-09-04 mostrou que os principais riscos restantes não são apenas de “prompt”. Eles envolvem parser/extractor, resolução contextual, cardinalidade de slots, candidate-vs-confirmed, processamento de bursts e lifecycle de alerts.

---

## 1. Pipeline

```text
texto original
  ↓
normalização auxiliar
  ↓
extração de intenções + entidades + candidatos
  ↓
contexto + slot esperado + outbound recente
  ↓
resolução de ambiguidades
  ↓
validação canônica
  ↓
delta de estado
  ↓
domain workflows
  ↓
resposta customer-facing
```

O parser não decide:

- preço
- disponibilidade final
- compatibilidade
- autoridade financeira
- status operacional

Isso pertence ao backend/domínio.

---

## 2. Resultado estruturado

Uma mesma mensagem pode produzir, conforme aplicável:

- `intents[]`
- `questions[]`
- `product_candidate`
- `product_variant_candidate`
- `quantity`
- `components[]`
- `meats[]`
- `extras[]`
- `beverages[]`
- `fulfillment`
- `address`
- `location`
- `payment_method`
- `corrections[]`
- `negations[]`
- `references[]`
- `notes[]`

Não limitar conceitualmente o resolver a “uma intenção + um valor”.

---

## 3. Candidate vs Confirmed

### Candidato

Informação plausível, mas ainda ambígua.

Exemplo:

`N8`

quando existem:

- N8 Casa
- N8 Livre

### Confirmado

Valor explicitamente escolhido ou resolvido de forma inequívoca.

O estado final não pode converter candidato ambíguo em escolha definitiva sem base.

---

## 4. Alias ambíguo de produto

Mensagens:

- `quero uma n8`
- `marmitex n8`
- `me vê uma n8`

devem reconhecer a família N8.

Se o contexto não resolver a variante:

`Você prefere a N8 Casa ou a N8 Livre? 😊`

Não ir direto para carne se Casa/Livre altera composição/cardinalidade.

---

## 5. Preservar dados durante ambiguidade

Entrada:

`quero uma n8 arroz feijão abóbora porco e frango`

Mesmo com variante ambígua:

- preservar componentes candidatos;
- preservar carnes explícitas;
- perguntar Casa/Livre;
- depois validar as escolhas contra a variante confirmada.

Não perder dados só porque um slot anterior ainda está pendente.

---

## 6. Multi-select

Mensagem:

`almôndega e porco`

pode representar duas carnes.

Regra:

- se produto permite duas carnes → selecionar ambas;
- se permite uma → preservar ambas como candidatas e pedir qual manter;
- se produto ainda ambíguo → resolver variante antes de impor cardinalidade final;
- se excede máximo → informar limite e clarificar.

Nunca `UNKNOWN` apenas por haver mais de um match.

---

## 7. Lista livre / bag-of-items

Mensagem real:

`arroz branco feijão macarrão vermelho mandioca abóbora banana cenoura couve almôndega e porco`

Deve ser tratada como coleção de entidades potenciais.

Requisitos:

- reconhecer vários componentes;
- reconhecer várias carnes;
- tolerar separação por espaço, vírgula ou `e`;
- preservar ordem textual quando útil;
- classificar com base no catálogo/categorias canônicos;
- validar depois contra produto;
- clarificar somente conflitos reais.

---

## 8. Não confundir categorias

Exemplo:

- `ovo frito` pode ser acompanhamento/item do buffet;
- `água sem gás` é bebida;
- `porco` é carne.

Classificação deve vir de catálogo/menu estruturado, não de posição na frase ou heurística frágil.

---

## 9. Produto + pergunta

Entrada:

`marmitex n8 qual o cardápio de hoje`

Pode significar simultaneamente:

- interesse/seleção na família N8;
- pergunta sobre cardápio/buffet.

Resposta:

1. reconhecer N8;
2. explicar/clarificar Casa x Livre;
3. responder buffet/carnes relevantes;
4. preservar estado;
5. pedir somente o próximo ponto necessário.

Não despejar catálogo irrelevante.

---

## 10. Produto + bebida

Entrada:

`quero uma n8 com porco e uma água sem gás`

Separar:

- item de comida;
- item de bebida.

Bebida não vira componente da marmita.

Mensagem posterior:

`também quero uma água sem gás`

deve anexar ao pedido ativo.

---

## 11. Perguntas no meio

Entradas:

- `vocês fazem entrega?`
- `quanto fica?`
- `aceita dinheiro?`
- `e a n9?`

devem responder a pergunta sem destruir pedido pendente.

Quando a pergunta também fornece informação:

`entrega e vou pagar pix`

pode preencher dois slots.

---

## 12. Correções

Exemplos:

- `na verdade troca por frango`
- `não quero porco pode ser almôndega`
- `não é entrega vou buscar`
- `tira a água`
- `faz duas`

Devem produzir deltas explícitos no estado.

Antes de proof/pagamento confirmado:

- revalidar;
- recalcular total;
- sincronizar Payment pendente via workflow quando necessário.

Depois de proof:

- alteração com impacto financeiro é protegida.

---

## 13. Slot esperado

Se a IA acabou de perguntar:

`Qual carne você deseja?`

mensagens como:

- `porco`
- `pode ser porco`
- `almôndega e porco`
- `a primeira`
- `essas duas`

devem ser interpretadas dentro desse contexto.

Não aplicar resposta curta globalmente fora do contexto.

---

## 14. Bursts de mensagens

Caso real:

1. pedido detalhado
2. `também quero uma água sem gás`
3. `vocês fazem entrega?`
4. endereço
5. `quanto fica?`
6. `posso pagar em dinheiro?`

podem chegar antes da primeira resposta.

Requisitos:

- ordem por conversa;
- mutações conflitantes serializadas;
- estado final incorpora todas as mensagens;
- respostas não usam snapshot obsoleto;
- alert de missing field deve ser reavaliado quando mensagem posterior o resolve;
- sem Order duplicado;
- sem Payment duplicado.

A implementação pode usar lock, transaction, idempotency, coalescing seguro ou mecanismo equivalente já compatível com a arquitetura.

---

## 15. Out-of-order

Se webhooks chegarem fora de ordem:

- mensagem antiga não deve regredir escolha mais nova;
- usar Meta message id/timestamp/event order disponíveis;
- manter idempotência.

Não criar migration apenas por conveniência sem avaliar estruturas existentes.

---

## 16. Human review threshold

Não usar review como fallback genérico para:

- multi-select;
- lista longa;
- produto ambíguo resolvível;
- burst;
- falta de pontuação;
- pergunta intermediária;
- missing field normal.

Human review para:

- pedido explícito de humano;
- ação financeira/admin protegida;
- segurança;
- falha operacional realmente não resolvível;
- ambiguidade persistente após clarificação adequada, quando não há caminho seguro.

---

## 17. Regra de segurança

Quando não há interpretação suficientemente segura:

1. manter estado anterior;
2. persistir inbound;
3. não executar mutação arriscada;
4. fazer a menor pergunta possível;
5. somente então escalar se necessário.

Precisão vence adivinhação.
