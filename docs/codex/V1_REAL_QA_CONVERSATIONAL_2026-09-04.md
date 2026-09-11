# V1 Real QA — Conversational Intelligence — 2026-09-04

## Contexto

Após o gate automatizado A–F reportar:

- 100 testes;
- 976 assertions;
- candidate/confirmed;
- multi-select;
- listas livres;
- progressive disclosure;
- burst/state;
- alert lifecycle;

foi feito novo smoke real na Meta.

O smoke mostrou que a automação está mais robusta, porém ainda não se comporta como uma conversa viva o suficiente para freeze.

---

## 1. Pedido genérico `marmita`

Entrada observada:

`qro uma marmita`

Foram observadas respostas que incluíram:

- `Não encontrei essa opção no cardápio disponível de hoje.`
- depois apresentação/pergunta de marmitas.

Problema:

`marmita` é uma intenção genérica de compra, não necessariamente um SKU inexistente.

Esperado:

- reconhecer intenção de pedir marmita;
- apresentar/perguntar opções;
- resposta coerente única;
- não declarar inexistência e depois oferecer as opções.

---

## 2. Comparação das N8

Entrada:

`qual a diferença das n8`

Resultado:

resposta reduziu a pergunta a preço de uma das variantes.

Depois:

`sim mas qual a diferença dessa pra n8 casa?`

Resultado:

resposta novamente informou apenas preço.

Problema:

o sistema reconhece entidades comerciais, mas não preserva plenamente a intenção semântica `comparar`.

Esperado:

- resolver N8 Casa e N8 Livre;
- buscar facts canônicos relevantes;
- explicar diferenças de composição/regra/tamanho/carnes/preço quando aplicável;
- não mutar pedido;
- compreender `dessa` pelo contexto.

---

## 3. Seleção de N8 Casa

Entrada:

`eu quero uma n8 casa`

Resultado:

o fluxo prosseguiu para carne.

Isso indica que seleção explícita simples está funcional em caminho relevante.

Manter regressão.

---

## 4. Carne

Entrada:

`almondega`

Resultado:

o fluxo prosseguiu para pergunta de salada.

Isso indica que contextual continuation de carne funciona em caminho relevante.

Manter regressão.

---

## 5. Pergunta sobre saladas

IA:

`Qual salada você deseja?`

Cliente:

`quais que tem?`

Resultado:

fallback genérico:

`Desculpe, não consegui entender direitinho...`

Problema:

o sistema não usou suficientemente a última pergunta/objetivo como referente.

Esperado:

- entender que o cliente pergunta quais saladas estão disponíveis;
- listar opções canônicas válidas para o produto;
- manter pedido intacto;
- zero review.

---

## 6. Clarificação explícita ainda falha

Cliente depois escreveu:

`quero saber qual saladas que tem`

Resultado observado:

o sistema ainda não respondeu diretamente a lista correta e desviou para outro caminho de produto/menu.

Isso mostra que a falha não é apenas abreviação.

É falta de orquestração semântica/contextual suficiente.

---

## 7. Resposta direta ao slot de salada

Cliente:

`beterraba`

após o contexto de salada.

Resultado:

fallback/handoff.

Foi observado alert:

`A mensagem continuou incompreensível após uma tentativa de clarificação.`

Problema:

um valor semanticamente compatível com o slot atual ainda não é resolvido genericamente.

Esperado:

- `candidate salad = Beterraba`;
- validar nas opções permitidas da N8 Casa;
- se válida, confirmar e continuar;
- zero review.

---

## 8. Diagnóstico de produto

O comportamento atual ainda se aproxima de:

```text
mensagem
→ resolver específico
→ intent/slot
→ builder
```

Isso funciona para casos previstos, mas quebra em:

- comparação;
- referência;
- pergunta sobre último slot;
- paráfrase;
- resposta contextual em slot não coberto.

O objetivo pós-QA é:

```text
conversa + state + último objetivo
→ interpretação semântica
→ facts relevantes
→ backend valida
→ resposta natural
```

---

## 9. Não adicionar frases uma a uma

Os seguintes exemplos são QA, NÃO regras de hardcode:

- `qual a diferença das n8`
- `dessa pra casa`
- `quais que tem?`
- `quero saber qual saladas que tem`
- `beterraba`

A correção deve generalizar para paráfrases equivalentes.

---

## 10. Freeze status

### Automatizado

PASS na rodada anterior.

### Conversational Intelligence

FAIL.

### Meta real

FAIL.

Copilot ainda NÃO pode ser congelado.

---

## 11. Próximo objetivo

Implementar o contrato:

`COPILOT_CONVERSATIONAL_ORCHESTRATION.md`

Preservando:

- facts do backend;
- safety;
- authority;
- state;
- idempotência;
- payment protection;
- burst handling;
- alert lifecycle.

Sem reescrever o Copilot inteiro e sem criar uma segunda engine de pedidos.
