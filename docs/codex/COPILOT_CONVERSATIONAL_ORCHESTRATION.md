# Copilot Conversational Orchestration — V1

## Status

Contrato arquitetural final da inteligência conversacional customer-facing do Copilot do Restaurante Sol.

Este documento NÃO é um catálogo de frases.

A regra central é:

> **A IA deve ser treinada com as regras do Restaurante Sol, não com todas as frases que um cliente pode pronunciar.**

E:

> **O backend controla a verdade e as ações. O modelo controla a compreensão e a conversa.**

---

## 1. Objetivo

O Copilot deve se comportar como uma atendente experiente inserida no ecossistema do Restaurante Sol.

O cliente pode:

- escrever do próprio jeito;
- fazer perguntas no meio do pedido;
- usar referências como `essa`, `a outra`, `quais que tem?`;
- mudar de ideia;
- corrigir escolhas;
- mandar várias informações juntas;
- perguntar diferenças;
- pedir recomendação;
- responder apenas com o valor do último slot;
- enviar mensagens sem pontuação;
- conversar fora da ordem ideal.

O sistema não deve exigir que cada formulação linguística tenha sido previamente programada.

---

## 2. Não construir um chatbot de frases

Anti-pattern:

```text
if mensagem contém "quais que tem"
    listar saladas

if mensagem contém "qual a diferença"
    comparar N8
```

Isso não generaliza.

Outro cliente escreverá:

- `quais tem ai`
- `oq tem de salada`
- `me fala as opção`
- `e a outra?`
- `qual muda oq`
- `qual compensa`
- `essa vem com oq`

A solução V1 deve operar por **significado + contexto + validação**, não por memorização de paráfrases.

Regex/aliases continuam aceitáveis como normalização ou fast path quando realmente gerais, mas não podem ser a única inteligência conversacional.

---

## 3. Arquitetura mental

```text
CLIENTE
   ↓
CONVERSA RECENTE
+ ÚLTIMO OBJETIVO/PERGUNTA DA IA
+ ESTADO ESTRUTURADO DO PEDIDO
+ REFERENTES ATIVOS
+ INTENÇÃO DA MENSAGEM
   ↓
INTERPRETAÇÃO SEMÂNTICA
   ↓
FATOS NECESSÁRIOS DO ECOSSISTEMA SOL
   ↓
BACKEND / DOMÍNIO CANÔNICO
   ↓
VALIDAÇÃO DA INTERPRETAÇÃO E DO STATE DELTA
   ↓
RESPOSTA NATURAL
   ↓
WORKFLOWS CANÔNICOS, QUANDO HÁ AÇÃO
```

O LLM não recebe autoridade para alterar o domínio livremente.

---

## 4. Três camadas complementares

### 4.1 LLM — compreensão e linguagem

Responsável por:

- entender intenção semântica;
- resolver referência contextual;
- interpretar follow-up;
- entender comparação;
- entender perguntas implícitas;
- extrair múltiplas entidades;
- interpretar correções/negações;
- construir resposta natural;
- resumir/explicar regras canônicas.

### 4.2 Backend — verdade e execução

Responsável por:

- catálogo;
- produto/variante;
- preço;
- disponibilidade;
- buffet/carnes;
- composição;
- cardinalidade;
- taxa de entrega;
- pagamento;
- Pix;
- Order;
- Payment;
- Delivery;
- histórico;
- tenant;
- RBAC;
- autoridade.

### 4.3 Guards determinísticos — segurança e fast paths

Responsáveis por:

- ações financeiras protegidas;
- idempotência;
- produto/preço válido;
- cardinalidade;
- prompt injection;
- confirmação humana;
- transições de estado;
- operações administrativas;
- respostas triviais de alta certeza quando já houver caminho robusto.

Os guards não devem substituir compreensão semântica geral.

---

## 5. Conversation Frame

Antes de interpretar uma nova mensagem, o Copilot deve construir um quadro conversacional mínimo e útil.

Conceitualmente:

```text
conversation_frame:
  recent_messages:
    - inbound/outbound em ordem

  active_order_state:
    produto/variante
    quantidade
    componentes
    carnes
    salada
    extras
    bebidas
    fulfillment
    endereço/location
    pagamento
    proof/payment state

  pending_slots:
    - campos realmente faltantes

  last_assistant_goal:
    type
    subject
    allowed_values ou data source relevante

  active_references:
    "essa"
    "a outra"
    "a primeira"
    "essas duas"

  business_context:
    somente fatos relevantes necessários
```

Não é obrigatório criar exatamente esta estrutura ou uma migration.

Reutilizar `pending_order_state`, `AutomationEvent`, histórico e estruturas já existentes sempre que possível.

---

## 6. Último objetivo da IA

O sistema precisa saber não apenas a última mensagem textual, mas o que a IA estava tentando obter.

Exemplo:

```text
assistant:
"Qual salada você deseja?"
```

Objetivo conceitual:

```text
last_assistant_goal:
  type: choose_option
  slot: salad
  product: N8 Casa
```

Cliente:

`quais que tem?`

Interpretação correta:

```text
intent: ask_available_options
subject: salad
state_delta: none
```

A resposta deve listar as opções canônicas válidas para aquele contexto.

Não retornar ao início do pedido.

---

## 7. Generic contextual slot resolution

Evitar uma arquitetura em que cada slot precise de um resolver linguístico independente:

```text
resolveMeatContinuation()
resolveSaladContinuation()
resolveAddressContinuation()
resolvePaymentContinuation()
...
```

Preferir um mecanismo geral:

```text
pending_slot
+ semantic interpretation
+ allowed/current domain values
+ backend validation
```

Exemplos:

### Carne

IA:
`Qual carne você deseja?`

Cliente:
`porco`

→ candidata para `meat`

### Salada

IA:
`Qual salada você deseja?`

Cliente:
`beterraba`

→ candidata para `salad`

### Pagamento

IA:
`Como você prefere pagar?`

Cliente:
`pix`

→ candidata para `payment_method`

O backend valida cada valor no domínio correspondente.

---

## 8. Semantic Recovery

Human review não é fallback primário para falha do parser.

Fluxo esperado:

```text
deterministic fast path
        ↓
resolveu com segurança?
   ├─ SIM → valida e continua
   └─ NÃO
        ↓
semantic interpretation/recovery com contexto
        ↓
interpretação pode ser validada?
   ├─ SIM → aplica delta seguro e continua
   └─ NÃO
        ↓
clarification mínima e específica
        ↓
ainda irresolvível / risco real?
   ├─ NÃO → continua
   └─ SIM → human review
```

O fato de uma regex/resolver não reconhecer a frase NÃO é motivo suficiente para humano.

---

## 9. Human review permanece restrito

Review legítimo continua para:

- proof/payment;
- ação financeira protegida;
- ação administrativa;
- pedido explícito de humano;
- segurança;
- ambiguidade comercial persistente que realmente não pode ser resolvida;
- falha operacional não recuperável;
- outros casos protegidos já existentes.

Não usar review apenas para:

- `quais que tem?`;
- `beterraba`;
- `qual a diferença?`;
- `essa`;
- `a outra`;
- perguntas intermediárias;
- mudança normal de escolha.

---

## 10. Pergunta informativa != mutação

O cliente pode perguntar sobre o domínio sem alterar o pedido.

Exemplo:

Pedido atual:
`N8 Casa`

Cliente:
`e a N9 quanto custa?`

Esperado:

- responder preço canônico da N9;
- NÃO trocar o produto atual para N9.

Outro:

Cliente:
`qual a diferença da livre pra casa?`

Esperado:

- comparar fatos canônicos;
- NÃO escolher uma variante automaticamente.

Somente linguagem de escolha/alteração suficientemente clara gera `state_delta`.

---

## 11. Comparação de produtos

O Copilot precisa ter capacidade geral de comparar produtos/variantes usando facts reais.

Perguntas:

- `qual a diferença das n8?`
- `essa muda o que pra casa?`
- `qual é maior?`
- `qual vem com mais carne?`
- `pq essa é mais cara?`
- `qual vale mais a pena?`

Processo:

1. resolver referentes/produtos;
2. buscar facts canônicos necessários;
3. distinguir fatos de recomendação;
4. responder de forma natural;
5. não inventar benefício.

### Recomendações

`qual vale mais a pena?`

Pode explicar trade-offs com fatos reais.

Exemplo conceitual:

- Casa: composição/regras mais definidas;
- Livre: mais liberdade de montagem;
- preço/tamanho/cardinalidade conforme backend.

Não afirmar subjetivamente que uma é "melhor" sem critério fornecido pelo cliente.

Pode perguntar:

`Você prefere economizar ou ter mais liberdade para montar?`

quando isso for útil.

---

## 12. Perguntas sobre opções do último slot

IA:
`Qual salada você deseja?`

Cliente:
`quais que tem?`

Esperado:

- entender `saladas` pelo último objetivo;
- consultar opções válidas do produto;
- listar somente opções válidas;
- manter pedido intacto.

Cliente depois:
`beterraba`

Esperado:

- preencher `salad=Beterraba` se válida;
- seguir ao próximo slot;
- zero review.

O mesmo princípio vale para:

- carnes;
- bebidas;
- formas de pagamento;
- opções Casa/Livre;
- outras escolhas estruturadas.

---

## 13. Pedido genérico

Entrada:

`qro uma marmita`

Não tratar `marmita` como nome de produto inexistente.

É intenção genérica de compra.

Esperado:

- reconhecer que quer uma marmita;
- apresentar/perguntar opções relevantes;
- não enviar simultaneamente mensagens contraditórias como:
  - `não encontrei essa opção`
  - e depois `qual marmitex você gostaria?`

---

## 14. Correções e mudança de ideia

Exemplos:

- `na verdade troca por frango`
- `não quero mais coca coloca água`
- `essa não, a outra`
- `vou buscar em vez de entrega`
- `tira a beterraba`
- `faz duas`

O LLM interpreta o delta.

O backend:

- valida;
- preserva histórico;
- recalcula quando necessário;
- protege estados financeiros;
- não executa alteração insegura pós-proof/pagamento sem workflow autorizado.

---

## 15. Referências contextuais

Resolver, quando houver base segura:

- `essa`
- `essa daí`
- `a outra`
- `a segunda`
- `essas duas`
- `a mesma`
- `igual a anterior`
- `essa pra casa`
- `essa pra livre`

Fontes para resolução:

1. pergunta imediatamente anterior;
2. opções apresentadas recentemente;
3. estado do pedido;
4. turnos recentes;
5. contexto do domínio.

Se ainda houver mais de um referente plausível:

clarificar.

Nunca adivinhar silenciosamente.

---

## 16. Acesso ao ecossistema Sol

O objetivo é dar ao modelo acesso CONTROLADO aos facts necessários.

Não dar SQL bruto ao LLM.

Não enviar o banco inteiro em cada turno.

Preferir read-model/services/context retrieval existentes, conceitualmente capazes de fornecer:

- detalhes de produto;
- comparação de produtos;
- opções válidas de slot;
- buffet atual;
- carnes atuais;
- disponibilidade;
- preço;
- formas de pagamento;
- configuração pública;
- estado do pedido ativo.

A implementação pode ser:

- contexto pré-resolvido;
- builders;
- serviços read-only;
- tool/function calls se a arquitetura atual suportar;
- combinação equivalente.

Reutilizar infraestrutura atual antes de criar outra.

---

## 17. Context retrieval sob demanda

Evitar prompt gigantesco com todo o catálogo.

Exemplo:

Cliente:
`qual a diferença dessa pra casa?`

Se o referente é N8 Livre:

fornecer ao modelo os facts canônicos da:

- N8 Livre;
- N8 Casa;

e o estado conversacional necessário.

Não é necessário incluir todas as bebidas, açaí e todos os produtos.

Isso reduz:

- tokens;
- ruído;
- risco de resposta irrelevante.

---

## 18. Structured interpretation

Quando útil, o modelo deve produzir interpretação estruturada antes da resposta/ação.

Exemplo:

```text
understanding:
  intents:
    - compare_products

  references:
    lhs: N8 Livre
    rhs: N8 Casa

  questions:
    - differences

  proposed_state_delta: none

  facts_needed:
    - product_details(N8 Livre)
    - product_details(N8 Casa)

  reply_goal:
    explain_difference
```

Outro:

```text
Cliente:
"beterraba então"

understanding:
  intent: answer_pending_slot
  slot: salad
  candidate_value: Beterraba
  proposed_state_delta:
    salad: Beterraba
```

O schema exato deve aproveitar DTOs/analysis existentes quando possível.

Não criar uma segunda análise paralela sem necessidade.

---

## 19. Semantic confidence != business authority

Mesmo uma interpretação semanticamente confiante não autoriza negócio.

Exemplo:

LLM entende:
`quero pagar via pix`

Backend ainda determina:

- se Pix está habilitado;
- chave pública;
- valor;
- Payment;
- lifecycle.

LLM entende:
`N8 Livre`

Backend ainda determina:

- se existe;
- se está ativa;
- preço;
- disponibilidade;
- regras.

---

## 20. Resposta natural

Depois de validar facts e delta, o modelo/builder customer-facing pode redigir naturalmente.

Características:

- português simples;
- amigável;
- bom humor leve;
- emojis moderados;
- mobile-first;
- sem jargão interno;
- sem parecer formulário;
- não repetir tudo a cada turno;
- responder primeiro o que foi perguntado;
- depois conduzir o pedido.

---

## 21. Não sobrescrever escolha explícita

Permanece obrigatório:

`customer_explicit` ou semântica equivalente.

O modelo pode sugerir/interpretrar.

Ele NÃO pode transformar uma sugestão própria anterior em escolha do cliente.

Exemplo:

IA:
`Temos almôndega, porco e frango.`

Isso NÃO significa que o cliente escolheu essas carnes.

---

## 22. Memória

A memória conversacional deve incluir o suficiente para referências naturais:

- inbound recente;
- outbound recente;
- structured state;
- pergunta/objetivo atual;
- opções recentemente apresentadas;
- Order ativo quando aplicável.

Não confiar apenas em reconstrução textual se já existe state estruturado.

---

## 23. Bursts continuam protegidos

A camada semântica não remove os requisitos de:

- ordering;
- idempotência;
- locks/serialização;
- stale snapshot protection;
- alert lifecycle.

Ela interpreta a mensagem.

A infraestrutura continua garantindo consistência.

---

## 24. Custo e performance

Não chamar o provider quando um fast path determinístico já é:

- seguro;
- completo;
- natural;
- semanticamente inequívoco.

Mas também não evitar o provider a ponto de degradar conversa real.

Princípio:

> **Determinístico para certeza e segurança; semântico para linguagem e contexto.**

---

## 25. Falha do provider

Se o provider falhar:

- preservar state;
- não executar mutação incerta;
- usar fallback determinístico quando houver;
- clarification segura quando possível;
- human review somente quando realmente necessário;
- nunca inventar fact.

---

## 26. Critério de arquitetura pronta

A arquitetura está correta quando uma nova paráfrase natural não exige código novo, desde que:

- expresse uma intenção já suportada;
- use entidades existentes;
- possa ser compreendida pelo contexto;
- possa ser validada pelo backend.

Se cada nova maneira de perguntar exige um `if`, a arquitetura ainda está errada.

---

## 27. Regra final

O objetivo não é:

`ensinar todas as perguntas possíveis`

O objetivo é:

`ensinar o trabalho + disponibilizar facts + fornecer contexto + validar ações`

Como uma atendente experiente:

- aprende as regras;
- consulta o que precisa;
- entende a conversa;
- pergunta quando necessário;
- não inventa;
- mantém o pedido correto.
