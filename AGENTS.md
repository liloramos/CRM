# AGENTS.md — ChatBotCRM

## Missão

Este repositório contém o ChatBotCRM, CRM operacional do Restaurante Sol.

A V1 será usada em atendimento real e deve priorizar, nesta ordem:

1. segurança financeira;
2. integridade do pedido;
3. continuidade operacional;
4. auditabilidade;
5. clareza para cliente/equipe;
6. boa experiência conversacional.

Fluxo principal:

`WhatsApp → pedido → pagamento → preparo → retirada/entrega → finalização`

## Estrutura

- `backend/` — Laravel/PHP
- `frontend/` — React/Vite
- PostgreSQL — persistência principal
- Meta WhatsApp Cloud API — canal de atendimento
- OpenAI — interpretação/linguagem natural
- Cloudflare/AWS — produção

## Branch e segurança Git

Trabalhar sempre na branch indicada pelo usuário ou pela tarefa atual.

Durante o fechamento da V1.0.0, a branch de integração utilizada foi:

`feature/production-operational-polish`

Nunca trocar para `main`, criar commit, push, merge ou tag sem autorização explícita do usuário.

O working tree pode estar grande/dirty intencionalmente durante tarefas em andamento.

---

## Regras absolutas de Git

Salvo autorização explícita do usuário:

- NÃO fazer commit
- NÃO fazer push
- NÃO usar stash
- NÃO usar reset
- NÃO usar clean
- NÃO descartar alterações
- NÃO trocar de branch
- NÃO reescrever histórico

Antes de uma rodada:

```bash
git status --short
git diff --stat
```

Depois, inspecionar somente arquivos relacionados ao objetivo da rodada.

---

## Princípio central

**FACTS FROM BACKEND. LANGUAGE FROM AI. ACTIONS VALIDATED BY BACKEND.**

O backend/domínio determina:

- produtos
- variantes
- preços
- cardápio
- buffet
- disponibilidade
- carnes
- composição
- cardinalidade de carnes
- adicionais
- regras Casa/Livre
- taxa de entrega
- formas de pagamento
- Pix
- total
- autoridade
- Order
- Payment
- Delivery
- lifecycle

O LLM ajuda com:

- intenção
- linguagem sem pontuação
- erros de digitação
- abreviações/gírias
- follow-up
- multi-intent
- multi-entity extraction
- referências contextuais
- resposta natural

O LLM NÃO é fonte de verdade comercial.

---

## Orquestração conversacional

O Copilot não deve exigir uma regra linguística para cada frase possível.

Contrato principal:

`docs/codex/COPILOT_CONVERSATIONAL_ORCHESTRATION.md`

Princípio:

**O backend controla a verdade e as ações. O modelo controla a compreensão e a conversa.**

Resolvers determinísticos são:

- fast paths;
- guards;
- safety;
- validação.

Eles não devem ser a única forma de compreender linguagem natural.

Antes de human review por incompreensão, usar semantic recovery quando houver um caminho seguro e validável.

---

## Sem engine paralela

Reutilizar estruturas/workflows existentes, incluindo quando aplicável:

- `pending_order_state`
- `AutomationEvent`
- `OrderProposal`
- `Order`
- `OrderWorkflowService`
- `PaymentWorkflowService`
- `DeliveryRoutingService`
- `DeliveryWorkflowService`
- catálogo/menu/pricing canônicos
- Conversation/Alert workflows

Não criar um segundo sistema de pedido dentro do Copilot.

---

## Integridade do pedido

Escolha explícita do cliente pode ser:

- validada
- complementada
- comparada com regras
- clarificada
- substituída após nova confirmação explícita

Nunca pode:

- desaparecer
- virar default
- ser sobrescrita silenciosamente
- ser reconstruída a partir do texto da IA
- vazar de um item para outro

---

## Candidate != Confirmed

Quando uma expressão é ambígua, ela deve permanecer candidata.

Exemplo:

`N8`

pode significar:

- N8 Casa
- N8 Livre

Se o contexto não resolver com segurança:

- preservar os demais dados;
- perguntar a variante;
- NÃO persistir uma variante final silenciosamente.

---

## Clarification != Human Review

Não abrir review apenas porque existe:

- produto/variante faltante
- quantidade faltante
- carne faltante
- mais de uma carne mencionada
- lista longa de componentes
- endereço faltante
- forma de pagamento faltante
- resposta curta ao último slot
- pergunta de cardápio/preço
- multi-intent
- mensagem sem pontuação
- erro de digitação
- informação fora de ordem
- burst de mensagens

Primeiro:

1. extrair tudo que for seguro;
2. preservar estado;
3. fazer a menor pergunta necessária.

Human review é exceção real.

---

## Financeiro protegido

A IA pode:

- informar formas de pagamento
- informar Pix público canônico
- informar total
- solicitar proof
- registrar proof via workflow

A IA NÃO pode:

- confirmar Payment
- alterar preço
- criar desconto
- marcar pago
- estornar
- executar ação admin/financeira protegida

---

## Linguagem real de WhatsApp

Clientes não devem precisar escrever “do jeito da máquina”.

Tolerar:

- sem vírgula
- sem ponto/interrogação
- sem acento
- caixa aleatória
- erro de digitação
- abreviação
- gíria
- frase incompleta
- várias escolhas juntas
- várias intenções juntas
- várias mensagens em rajada
- correções
- negações
- referências como `a segunda`, `essa`, `isso`

A normalização é auxiliar.

Preservar texto original para histórico/auditoria.

Nunca normalizar de forma que remova negação ou altere significado.

---

## Multi-intent e multi-entity

Uma mensagem pode produzir múltiplos efeitos seguros.

Exemplo:

`quero n8 livre porco entrega rua x 10 pix`

pode conter:

- produto
- carne
- fulfillment
- endereço
- forma de pagamento

Outro exemplo:

`quero uma n8 livre qual o buffet de hoje`

deve:

- selecionar N8 Livre
- responder buffet
- preservar estado
- perguntar o próximo slot faltante

Não limitar conceitualmente a análise a uma única intenção.

---

## Multi-select

Slots podem ter cardinalidade > 1.

Exemplo:

`almôndega e porco`

Se o produto permite duas carnes:

- selecionar ambas.

Se permite uma:

- explicar o limite;
- perguntar qual manter.

Não transformar múltiplos matches válidos em `UNKNOWN`.

---

## Bursts de mensagens

Clientes podem enviar várias mensagens antes de a IA responder.

O sistema deve:

- preservar ordem por conversa;
- serializar/lockar mutações conflitantes quando necessário;
- não perder slots;
- não responder baseado em estado obsoleto;
- não criar Order/Payment duplicado;
- reavaliar missing fields e alerts depois de mensagens posteriores.

---

## Alerts

Alert atual deve ser:

- acionável
- deduplicado
- associado a uma causa concreta quando conhecida
- resolvido/superseded quando a causa deixar de existir

Exemplo:

se um alert diz `falta endereço` e o endereço chega depois, esse alert não pode continuar como pendência atual.

`Manual` não é mecanismo de limpeza de alert.

---

## WhatsApp customer-facing

Toda resposta deve ser mobile-first:

- curta
- natural
- amigável
- bom humor leve
- emojis moderados
- linhas em branco
- bullets quando útil
- máximo prático de 2–3 mensagens por turno

Não expor:

- JSON
- enum
- pending state
- action names
- policy names
- low-confidence
- human_review
- linguagem técnica

---

## Progressive disclosure

Responder proporcionalmente ao que o cliente perguntou.

### `cardápio` / `qual o cardápio de hoje?`

Priorizar:

1. marmitas;
2. buffet do dia;
3. carnes do dia;
4. informar que também há outras categorias.

### `cardápio completo`

Pode incluir:

- marmitas
- combos
- bebidas
- sucos
- açaí
- demais categorias reais

em até 3 mensagens organizadas.

### pergunta específica

Não despejar catálogo inteiro.

Exemplo:

`marmitex n8 qual o cardápio de hoje`

deve priorizar:

- N8 Casa x N8 Livre;
- buffet/carnes relevantes;
- pergunta necessária para continuar.

---

## Greeting

Saudações comuns/coloquiais devem ser reconhecidas sem review:

- oi
- olá
- opa
- bom dia
- boa tarde
- boa noite
- e aí
- fala
- fala my friend

Operating hours e greeting são conceitos diferentes.

Timezone canônica:

`America/Sao_Paulo`

---

## Horários

Horários vêm das configurações da empresa.

Se ainda não configurados:

- não afirmar aberto
- não afirmar fechado
- não bloquear greeting
- não inventar horário

---

## Estratégia de testes

Correção:

1. reproduzir bug;
2. criar/ajustar teste focado;
3. corrigir causa;
4. reexecutar focado;
5. regressões diretamente afetadas;
6. parar.

Freeze:

```bash
composer run lint:check
npm run lint
npm run build
git diff --check
```

---

## Documentos de referência

Leia somente os necessários à tarefa:

- `docs/codex/CURRENT_CHECKPOINT.md`
- `docs/codex/V1_PRODUCT_RULES.md`
- `docs/codex/RESTAURANT_KNOWLEDGE_CONTRACT.md`
- `docs/codex/COPILOT_ARCHITECTURE.md`
- `docs/codex/COPILOT_CONVERSATION_POLICY.md`
- `docs/codex/COPILOT_FLOW.md`
- `docs/codex/COPILOT_INPUT_NORMALIZATION.md`
- `docs/codex/COPILOT_PARSING_AND_STATE_CONTRACT.md`
- `docs/codex/V1_CONVERSATION_SCENARIO_MATRIX.md`
- `docs/codex/V1_OPERATIONAL_EDGE_CASES.md`
- `docs/codex/V1_REAL_QA_FINDINGS_2026-09-04.md`
- `docs/codex/V1_ACCEPTANCE_TESTS.md`
- `docs/codex/V1_CATALOG_ADMINISTRATION.md`
- `docs/codex/CATALOG_REALTIME_CONSISTENCY.md`
- `docs/codex/AI_AUTOMATION_SANDBOX_CONTRACT.md`
- `docs/codex/SYSTEM_ASSISTANT_CONTRACT.md`
- `docs/codex/V1_POST_COPILOT_OPERATIONAL_POLISH.md`
- `docs/codex/V1_SELLER_ATTRIBUTION.md`
- `docs/codex/V1_RELEASE_CHECKLIST.md`

`CURRENT_CHECKPOINT.md` é o documento vivo.

---

## Regra de escopo

Não adicionar feature pós-V1 enquanto houver blocker operacional.

Não usar uma correção do Copilot como desculpa para refatorar módulos fora do escopo.

O objetivo é fechar a V1 real com segurança.
