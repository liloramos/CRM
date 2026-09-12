# CURRENT CHECKPOINT — ChatBotCRM V1

Data: 2026-09-04.

## Branch

`feature/production-operational-polish`

Working tree grande/dirty intencional.

Não fazer:

- reset
- stash
- clean
- descarte de alterações
- commit
- push
- troca de branch

sem autorização explícita.

---

## Base automatizada anterior

Rodadas anteriores reportaram como implementado/validado:

- `selection_source=customer_explicit` ou equivalente;
- proteção Casa incompatível;
- outbound recente no contexto;
- formatting/line breaks;
- até 3 `reply_messages`;
- stale low-confidence lifecycle parcialmente corrigido;
- LOCATION;
- Order materialization;
- DeliveryRoutingService para fee;
- Payment/Pix reuse;
- proof sem auto-confirm;
- authority/prompt-injection protections.

Última validação ampla reportada antes dos novos smokes:

- 241 testes / 2.157 assertions;
- composer lint PASS;
- npm lint PASS;
- npm build PASS;
- git diff --check PASS;
- nenhuma migration nova naquela rodada.

Depois houve uma correção focada:

- greeting deterministic;
- cardápio mais completo;
- multi-intent inicial;
- short meat slot;
- low-confidence relacionado.

Validação da rodada focada:

- 16 testes / 277 assertions;
- Pint focado PASS;
- git diff --check direcionado PASS;
- sem suíte ampla/frontend.

---

## Smoke real ampliado — equipe Restaurante Sol

O QA real de 2026-09-04 mostrou que o Copilot ainda NÃO está pronto para freeze.

### O que melhorou

- greeting simples/coloquial funcionou em caminhos reais;
- cardápio passou a ter formatação melhor;
- buffet e carnes começaram a aparecer;
- preços/categorias customer-facing ficaram mais legíveis.

---

## Blocker A — N8 ambígua

Entrada:

`quero uma marmitex n8`

Resultado:

sistema foi direto para:

`Qual carne você deseja?`

Esperado:

- reconhecer família N8;
- desambiguar N8 Casa x N8 Livre quando necessário;
- não impor regra de carne antes da variante;
- preservar dados já fornecidos.

---

## Blocker B — multi-select de carne

Entrada:

`almôndegas e porco`

Resultado:

fallback/human review.

Esperado:

- reconhecer duas carnes;
- validar cardinalidade conforme variante;
- aceitar ambas quando permitido;
- pedir qual manter quando limite=1;
- sem review por multi-select normal.

---

## Blocker C — multi-entity list

Entrada longa com vários acompanhamentos + carnes.

Resultado:

fallback/handoff.

Esperado:

- extração `components[]`;
- extração `meats[]`;
- tolerância a ausência de pontuação;
- validação canônica;
- clarification apenas para conflito real.

---

## Blocker D — resposta de cardápio excessiva

Entrada:

`marmitex n8, qual o cardápio de hoje`

Resultado:

dump amplo de marmitas + combos + bebidas + sucos + açaí + buffet + carnes.

Esperado:

progressive disclosure:

- resolver/clarificar N8;
- responder buffet/carnes relevantes;
- não despejar categoria irrelevante;
- CTA explícita.

---

## Blocker E — burst / state obsoleto

Sequência real:

- pedido detalhado;
- água;
- entrega;
- endereço;
- `quanto fica?`;
- dinheiro.

Resultado:

- múltiplos alerts/reviews;
- alert de missing address apesar de endereço já ter chegado.

Investigar causa real:

- concorrência/jobs;
- ordering;
- stale pending state;
- lifecycle de alert;
- missing-field snapshot.

Não assumir antes de teste.

---

## Blocker F — alert lifecycle / dedup / actionability

Observado:

- múltiplos alerts atuais equivalentes/genéricos;
- motivo nem sempre útil;
- um missing field normal foi tratado como review.

Esperado:

- missing field normal continua automático;
- uma causa lógica → uma pendência atual;
- histórico pode ter eventos múltiplos;
- alert atual tem motivo concreto/ação quando conhecido;
- causa resolvida → resolved/superseded;
- toggle Manual não é necessário para limpar estado sanado.

---

## Horários

Horários reais do Restaurante Sol ainda precisam ser configurados nas configurações.

Enquanto ausentes:

- greeting funciona;
- não afirmar aberto/fechado;
- não bloquear pedido só pela falta dessa configuração.

Timezone:

`America/Sao_Paulo`

---

## Próxima rodada Codex — escopo recomendado

Ler:

1. `AGENTS.md`
2. `docs/codex/CURRENT_CHECKPOINT.md`
3. `docs/codex/COPILOT_PARSING_AND_STATE_CONTRACT.md`
4. `docs/codex/COPILOT_INPUT_NORMALIZATION.md`
5. `docs/codex/COPILOT_CONVERSATION_POLICY.md`
6. `docs/codex/V1_REAL_QA_FINDINGS_2026-09-04.md`
7. `docs/codex/V1_ACCEPTANCE_TESTS.md`

Objetivo:

corrigir SOMENTE os blockers A–F de parser/state/alerts/progressive disclosure.

Estratégia:

- reproduzir casos reais;
- localizar causas estruturais;
- evitar regex específica;
- preservar arquitetura/domain workflows;
- testes focados;
- regressões diretamente afetadas;
- não suíte ampla;
- não frontend se não necessário;
- atualizar este checkpoint ao final.

---

## Pendências V1 depois do Copilot

- seller/attendant attribution;
- pré-comanda bife blank behavior;
- horários reais/configuração;
- smoke Epson;
- migration weight pricing no deploy;
- freeze audit;
- checkpoint/commit;
- promoção para `main`;
- deploy;
- número oficial Meta;
- smoke produção.

---

## Regra de aprovação

Nenhum resultado automatizado sozinho congela o Copilot.

É obrigatório repetir smoke real da Meta depois da próxima correção.

---

# Checkpoint mais recente — Conversational Intelligence — 2026-09-04

## Rodada A–F automatizada concluída

Codex reportou:

- A: `N8` candidate até Casa/Livre;
- B: multi-select de carnes;
- C: listas livres de components/meats;
- D: progressive disclosure;
- E: burst com serialização/stale protection/idempotência;
- F: missing fields normais/alert lifecycle.

Validação reportada:

- 100 testes;
- 976 assertions;
- todos passando;
- Pint direcionado PASS;
- `git diff --check` direcionado PASS;
- nenhuma migration;
- nenhum frontend;
- nenhum commit/push/stash/reset/clean.

## Novo smoke real posterior

Apesar do gate automatizado, o Copilot ainda NÃO está pronto para freeze.

### Conversational blocker 1 — pedido genérico

`qro uma marmita`

foi tratado em caminho que chegou a dizer que a opção não existia e também a listar/perguntar marmitas.

`marmita` deve ser intenção genérica de compra, não SKU inválido.

### Conversational blocker 2 — comparação

`qual a diferença das n8`

e

`sim mas qual a diferença dessa pra n8 casa?`

foram reduzidos praticamente a consultas de preço.

O Copilot precisa compreender:

- compare;
- referências;
- facts necessários;

e explicar diferença real sem mutar o pedido.

### Conversational blocker 3 — pergunta sobre último slot

IA:
`Qual salada você deseja?`

Cliente:
`quais que tem?`

caiu em fallback.

Deve inferir subject=salad pelo último objetivo da IA e listar opções válidas.

### Conversational blocker 4 — clarificação explícita ainda desviou

`quero saber qual saladas que tem`

não resolveu corretamente a pergunta no contexto do pedido.

### Conversational blocker 5 — resposta direta ao slot

`beterraba`

após pergunta de salada caiu em fallback/handoff.

Alert observado:

`A mensagem continuou incompreensível após uma tentativa de clarificação.`

Isso deve ser semanticamente interpretado e validado no slot atual.

## Diagnóstico atual

O Copilot está robusto em vários fluxos estruturados, mas ainda depende demais de resolvers/branches específicos.

Não corrigir adicionando frases uma por uma.

Próxima evolução:

`docs/codex/COPILOT_CONVERSATIONAL_ORCHESTRATION.md`

Objetivo:

```text
conversa + state + último objetivo
→ interpretação semântica
→ facts relevantes
→ backend valida
→ resposta natural
```

Resolvers determinísticos continuam como fast path/safety.

Human review deixa de ser fallback precoce para falha linguística.

## Próxima rodada Codex — escopo

Ler primeiro:

1. `AGENTS.md`
2. `docs/codex/CURRENT_CHECKPOINT.md`
3. `docs/codex/COPILOT_CONVERSATIONAL_ORCHESTRATION.md`
4. `docs/codex/V1_REAL_QA_CONVERSATIONAL_2026-09-04.md`
5. `docs/codex/V1_CONVERSATIONAL_INTELLIGENCE_ACCEPTANCE.md`

Depois, somente os contratos já existentes diretamente necessários para não quebrar:

- parsing/state;
- authority;
- customer-facing;
- product rules.

NÃO atacar ainda V5 operational polish:

- seller attribution;
- CRUD catálogo;
- sandbox IA/Automação;
- System Assistant;
- bife/comanda;
- horários;
- Epson.

## Freeze status

- Parser/state automated gate: PASS
- Conversational Intelligence: FAIL
- Meta real: FAIL
- Copilot freeze: NÃO
- V1 freeze: NÃO

## Regra da próxima correção

Não criar:

- uma segunda engine de pedidos;
- SQL/tooling bruto para o LLM;
- dezenas de regex de QA;
- migration sem necessidade comprovada.

Reutilizar:

- ConversationCopilotService;
- ContextBuilder;
- Normalizer/Analysis/DTOs;
- pending_order_state;
- AutomationEvent;
- authority policy;
- workflows canônicos.

## Pendências depois do Copilot

Continuam documentadas no patch V5:

- seller/attendant attribution;
- catálogo CRUD;
- real-time catalog consistency;
- IA/Automação sandbox;
- System Assistant;
- bife blank;
- horários;
- Epson;
- freeze audit;
- main;
- deploy.

---

# Checkpoint mais recente — Conversational Intelligence concluída — 2026-09-04

## Resultado da rodada

O gate automatizado de Conversational Intelligence está verde. A implementação reutiliza o pipeline existente e não cria uma segunda engine de pedido:

`conversation_frame → interpretação semântica → delta candidato → validação canônica → resposta grounded`

- `conversation_frame` reúne mensagens recentes, `pending_order_state`, pedido ativo, slots pendentes, último objetivo da IA, opções e referências recentes e somente os facts de negócio relevantes;
- o último objetivo é persistido no payload do `AutomationEvent` existente e reconstruído pelo `ConversationCopilotContextBuilder` com opções e `state_delta` pertencentes ao backend;
- respostas a slots pendentes são genéricas: a interpretação indica subject/valor/índice, mas somente opções canônicas do frame podem produzir delta;
- comparações e referências contextuais são interpretadas semanticamente, enquanto nome, variante, descrição, preço, composição e cardinalidade vêm do catálogo canônico;
- perguntas informativas são marcadas como read-only: o draft operacional do turno é esvaziado, o `pending_order_state` permanece intacto e o próximo slot continua depois da resposta;
- semantic recovery ocorre antes de human review, mas continua passando por normalização, grounding, validação de domínio, reply guard e authority policy;
- nenhuma mutação de `Order`, `Payment` ou `Delivery` foi adicionada ao adapter semântico; ações continuam exclusivamente nos workflows canônicos;
- proof continua sem auto-confirmação de pagamento;
- prompt injection, disponibilidade/tenant, preço, quantidade, remoções, notas, Casa/Livre, `customer_explicit`, multi-meat/multi-entity, idempotência, ordering de burst e lifecycle de alerts permanecem protegidos pelos guards/workflows existentes e pelas regressões afetadas.

## Incompatibilidades reais corrigidas durante as regressões

1. Uma resposta curta com valor canônico exato do slot (`retirada`) chegava ao caminho semântico, mas providers legados sem o novo bloco de interpretação não aplicavam o delta. O resolver passou a reconhecer genericamente valores exatos das opções canônicas de `last_assistant_goal` como fast path.
2. Um novo pedido explícito durante uma clarificação de carne era tratado como continuação da clarificação. A precedência foi ajustada para o novo pedido explícito superseder a clarificação anterior.
3. Casos legados que tratavam `N8` ambígua como `N8 Livre` foram alinhados ao contrato Candidate != Confirmed; a variante continua candidata até escolha explícita.

## Validação final direcionada

- `ConversationCopilotPipelineTest`: 43 testes / 268 assertions — PASS;
- `OpenAiConversationCopilotProviderTest`: 19 testes / 86 assertions — PASS;
- `CopilotDeterministicIntentTest`: 43 testes / 402 assertions — PASS;
- `ConversationCopilotContextBoundaryTest` + `ConversationCopilotTest` + `CopilotSafeClarificationContinuationTest`: 47 testes / 325 assertions — PASS;
- `CopilotAutomationServiceTest` + `CopilotAutomationAuthorityEvaluationTest`: 37 testes / 410 assertions — PASS;
- `CopilotConversationalIntelligenceTest`: 11 testes / 144 assertions — PASS;
- total dos arquivos finais sem sobreposição: 200 testes / 1.635 assertions — PASS;
- Pint executado somente nos PHP tocados — PASS;
- `git diff --check` direcionado — PASS;
- migrations: nenhuma.

## Status após esta rodada

- Conversational Intelligence automatizada: PASS;
- regressões diretamente afetadas: PASS;
- smoke real Meta: ainda obrigatório e não executado nesta rodada;
- Copilot freeze: aguarda smoke real Meta;
- nenhuma etapa V5/pós-Copilot, frontend, deploy ou `main` foi iniciada.

---

# V5 — Catalog Administration / Realtime Consistency — 2026-09-04

## Estrutura e causa encontrada

- `ProductCategory` já era entidade persistida por `company_id`; `Product` já referenciava a categoria por `category_id`.
- A limitação era da camada administrativa: o único cadastro chamava `createCounterProduct`, aceitava uma lista fixa de slugs e sempre gravava o produto como `counter`.
- `StructuredMenuCatalogService` + `StructuredProductConfigurationService` permanecem como read model/fonte canônica para UI e consumidores comerciais.

## CRUD concluído

- cadastro geral e atalho explícito de produto de balcão no mesmo fluxo/API;
- edição de nome, categoria, preço, status, disponibilidade e dias;
- criação, edição, ativação/inativação e exclusão segura de categoria;
- tenant isolation e `menu.manage` reutilizados;
- UI de `Produtos e preços` com `Novo produto`, atalho de balcão, categorias e ações Editar/Ativar/Inativar/Excluir.

## Integridade e freshness

- produto sem referências relevantes sofre permanent delete;
- produto com `OrderItem`, uso em combo ou seleção é arquivado via metadata e fica inativo/indisponível;
- categoria com produtos não pode ser excluída; categoria vazia pode;
- `OrderItem` preserva `product_name`, `unit_price_cents` e totais; alteração futura do `Product` não recalcula venda antiga;
- novas vendas leem o preço atual do produto;
- não existe cache persistente de catálogo; as queries/read models consultam o banco a cada request/análise, e a disponibilidade canônica considera produto e categoria ativos. Nenhum restart/invalidação manual é necessário.

## Validação

- 45 testes / 626 assertions de Catalog Administration, Counter Product, Menu Admin, Menu Catalog e Structured Menu — PASS;
- `npm run lint` — PASS;
- `npm run build` — PASS (somente warning de chunk grande);
- Pint direcionado aos PHP tocados — PASS;
- migrations novas: nenhuma;
- blockers automatizados do escopo: nenhum;
- pendente operacional: smoke manual da UI contra o banco real e smoke real Meta já exigido para o freeze global.

## Catalog UI polish / Product Images

- causa da foto quebrada: o arquivo era salvo em `storage/app/public`, mas `Storage::url()` serializava a origem de `APP_URL=http://localhost`, diferente da porta usada pela API local; além disso, produto normal já existente não exibia o campo de foto e o `<img>` não tratava falha de carregamento;
- solução: o mesmo `metadata.catalog_image_path` e disco `public` continuam canônicos, com leitura por URL relativa versionada da API, isolamento por empresa, troca segura do arquivo e remoção; o link local `public/storage` já existe, mas a renderização administrativa não depende mais da origem configurada em `APP_URL`;
- produtos novos, antigos, normais e de balcão agora podem adicionar, visualizar, trocar e remover foto; miniaturas inválidas ou ausentes caem em `Sem foto`;
- layout: ações principais e busca/filtros vêm antes do resumo compacto de categorias; categorias usam linhas curtas com contagem, badge e ações; cards têm miniatura previsível, badges compactos e ações alinhadas sem largura total;
- validação focada: 15 testes / 206 assertions — PASS; `npm run lint` — PASS; `npm run build` — PASS (somente warning de chunk grande); Pint direcionado — PASS; `git diff --check` — PASS; migrations novas: nenhuma;
- blocker restante: smoke manual da tela com o banco/navegador real, incluindo recarga após adicionar, trocar e remover foto.

---

# V5 — Seller / Attendant Attribution — 2026-09-05

- estrutura: `orders.seller_user_id` opcional referencia um usuário real da empresa e `seller_name_snapshot` preserva o nome histórico; `created_by_user_id` continua sendo o login técnico e `order_status_histories.user_id`, o ator da ação;
- automático: pedidos WhatsApp/Copilot continuam sem responsável humano por padrão e uma atribuição posterior não altera a origem nem os eventos de automação;
- Caixa: venda imediata, abertura e finalização de comanda aceitam seleção rápida e explícita do atendente, sem default silencioso;
- Pedidos: criação manual aceita responsável; lista e detalhe exibem o atendente e permitem alterar/remover conforme `orders.manage`;
- histórico: mudanças registram `order_seller_changed` no histórico canônico com ator, IDs e nomes anterior/novo em snapshot;
- tenant/RBAC: candidatos e validação são limitados por `company_id`; edição reutiliza `orders.manage`;
- testes: 86 testes / 726 assertions de Seller Attribution, Caixa, comandas, histórico e Order — PASS;
- frontend: `npm run lint` e `npm run build` — PASS; permanece apenas o warning conhecido de chunk grande;
- migration: `2026_09_05_000010_add_seller_attribution_to_orders_table.php`;
- blockers: nenhum automatizado; smoke manual da seleção no Caixa/Pedidos e aplicação da migration no deploy continuam pendentes.
- correção de elegibilidade: `users.can_be_seller` passou a representar explicitamente, e sem dependência de role/permissão/cargo, quem pode ser escolhido como responsável por vendas; a flag é administrável em Usuários e permissões;
- fonte canônica: `OrderSellerEligibilityService` limita candidatos e novas atribuições aos usuários elegíveis da mesma empresa em Caixa e Pedidos; `Não atribuído` continua válido;
- histórico: remover elegibilidade não apaga `seller_user_id`, não reescreve `seller_name_snapshot` e mantém o responsável anterior visível nos pedidos existentes;
- migration: `2026_09_05_000011_add_can_be_seller_to_users_table.php`; validação focada e regressões diretas: 92 testes / 766 assertions — PASS.

---

# V5 — User Access Governance — 2026-09-05

- cargo: causa era o formulário enviar `jobTitle` enquanto a API validava `job_title`; o mapeamento foi corrigido, a sessão agora projeta cargo/identidade e o Perfil preserva RBAC ao editar dados pessoais;
- avatar: `UserIdentityPresenter` fornece URL relativa canônica, versionada pelo arquivo, e `UserAvatar` aplica foto/fallback de iniciais em Perfil, sidebar, menu/topbar e Usuários e permissões; upload administrativo e remoção também foram incluídos;
- RBAC/governança: roles/permissões existentes foram preservados; overrides individuais usam a pivot `permission_user`, e toda alteração administrativa passa por `UserAccessGovernanceService` no backend;
- DEV: o `super_admin` existente representa semanticamente o perfil DEV, sem hardcode de pessoa, com maior autoridade, role/permissão protegidas e proteção do último DEV ativo do tenant;
- delegation ceiling: ator só altera role até o próprio nível e permissões efetivamente possuídas/delegáveis; autoalteração de acesso/status, payload manual, edição cross-tenant e alteração de DEV por autoridade inferior são rejeitados;
- usuários: criação gera conta autenticável pelo fluxo existente; edição separa identidade, acesso, status e operação; usuário inativo não autentica nem permanece autorizado;
- seller eligibility: `can_be_seller` segue independente de cargo/perfil, e `OrderSellerEligibilityService` agora também exclui usuários inativos sem reescrever histórico;
- migration nova: `2026_09_05_000012_add_user_access_governance.php` adiciona `users.is_active` e `permission_user` de forma backward-compatible;
- validação: 26 testes / 208 assertions — PASS; `npm run lint` — PASS; `npm run build` — PASS, somente warning conhecido de chunk grande; Pint somente nos PHP tocados — PASS; `git diff --check` — PASS;
- smoke pendente: validar no navegador/banco real refresh do cargo, propagação/troca/remoção do avatar, criação/login/inativação e modal de permissões/DEV.

---

## UX de preview de fotos de perfil — 2026-09-05

- `UserAvatar` agora abre lightbox reutilizável somente para fotos reais carregadas, em Perfil, Usuários e permissões e avatar/menu da conta; avatares de iniciais e clientes/WhatsApp permanecem inalterados;
- tabela de Usuários e permissões usa avatar de 46 px, mantendo a linha compacta;
- preview fecha por X, backdrop ou Escape, bloqueia scroll de fundo e limita a imagem proporcionalmente à viewport;
- frontend: `npm run lint` e `npm run build` — PASS (somente warning conhecido de chunk grande); sem backend ou migration.
- Correção do preview de avatar: lightbox agora centraliza o conjunto na viewport e limita fotos grandes a `min(85vw, 800px)` × `min(80vh, 800px)`, sem corte ou extrapolação vertical.

---

## V5 — Labia / IA e Automação — 2026-09-05

- Labia finalizada como assistente interna do CRM: catálogo estrutural com paráfrases/contexto recente, leitura canônica de produtos, fallback local útil e CTAs filtradas por RBAC; identidade visual atualizada sem renomear a arquitetura técnica.
- Sandbox continua read-only e agora reutiliza explicitamente o pipeline canônico do Copilot, preserva `reply_messages`, reconhece Coca-Cola 2L e projeta somente handoffs reais ainda acionáveis com o motivo disponível.
- Realtime catalog consistency comprovada por testes de alteração de preço, criação/categoria e inativação: cada nova simulação consulta o estado atual, sem cache ou cópia paralela.
- Validação: 97 testes / 852 assertions focados e regressões diretas — PASS; lint backend/frontend e build — PASS (um warning frontend preexistente e o warning conhecido de chunk); migrations novas: nenhuma.
- Blocker automatizado: nenhum; pendem somente smoke manual destas telas e, na rodada seguinte, o QA real/freeze final do Copilot WhatsApp.

---

## Correcao final pre-freeze — 2026-09-05

- Labia resolve follow-ups deiticos recentes para o modulo anterior; remover responsavel por vendas orienta editar a usuaria e desmarcar elegibilidade. Preco, novo produto e categoria agora descrevem a tarefa e mantem a CTA canonica de Cardapio.
- Copilot/Sandbox busca alternativa ativa e operacionalmente disponivel somente na mesma familia. Sem match confiavel, informa indisponibilidade e oferece a mesma categoria. Nome e preco continuam vindo do catalogo atual.
- Pix continua `denied_auto` com revisao humana. A resposta segura informa que a IA nao confirma pagamento, solicita comprovante e diz que a equipe fara a conferencia.
- Validacao focada: `SystemAssistantTest` 7/87, sandbox 4/40, Pix 1/7; regressoes diretas `CopilotAutomationServiceTest` 32/307 e `AiAutomationSettingsTest` 10/79 — PASS. Sem migration.

---

## Copilot WhatsApp — fechamento da conversa incremental crítica — 2026-09-05

- causas raiz confirmadas: discovery genérico entrava em seleção de SKU; turnos informativos podiam prevalecer sobre o pedido pendente; continuações validavam novamente produto/escolhas apenas pela janela textual curta; merge e carnes compostas deduplicavam antes da identidade canônica; `UNKNOWN` repetido ignorava o contexto válido;
- discovery agora lista as marmitas canônicas sem selecionar produto, sem product-not-found e sem review; pergunta de preço de marmita continua sendo informação, não discovery;
- `N8 Livre` e todos os slots resolvidos sobrevivem a cardápio, buffet, `quais carnes?`, disponibilidade de Coca e total; turnos informativos são read-only e retomam o mesmo `pending_order_state`;
- continuação incremental reutiliza IDs e snapshots já validados; produto ou escolha nova ainda exige grounding no turno atual, sem ampliar a autoridade do modelo;
- multi-slot preserva arroz, feijão, candidato de macarrão e duas carnes canônicas; Porco/Filé composto não duplica seleções; `macarrão` ambíguo preserva o restante e pergunta somente entre as opções reais;
- regra canônica: `arroz`/`arroz branco` resultam em Arroz branco; Arroz amarelo somente quando explícito; `e arroz` complementa de forma idempotente sem reset;
- `tem coca?` lista variantes disponíveis e preços canônicos sem escolher embalagem; `quanto fica?` usa total persistido ou preços atuais do draft e não inventa taxa de entrega;
- low confidence com state válido produz clarificação contextual; casos normais da rodada permanecem automáticos, enquanto proof/pagamento, financeiro, autoridade e handoff explícito continuam protegidos;
- conversa sequencial única coberta: pedido → discovery → N8 Livre → cardápio/buffet/carnes → multi-slot → Coca → `e arroz` → total; nenhuma `Order` ou `Payment` duplicada;
- runtime e testes usam o mesmo núcleo: webhook persiste/agendar `ProcessCopilotAutomation` → `CopilotAutomationService` → `ConversationCopilotService` → context/pipeline/authority/dispatch;
- validação final sem sobreposição: 198 testes / 1.707 assertions — PASS; inclui Conversational Intelligence 19/264, pipeline 43/271, deterministic + automation 75/712, context/proposal/state/authority 60/454 e duplicate webhook 1/6;
- Pint somente nos PHP tocados e `composer run lint:check` — PASS; frontend não alterado nesta rodada, portanto lint/build npm não executados; migration nova: nenhuma;
- blocker restante: somente smoke real pela Meta/WhatsApp com credenciais e tráfego reais; freeze automatizado desta rodada está verde.

---

## Copilot — consumo contextual do pending slot e CTA de detalhes — 2026-09-05

- opções candidatas já apresentadas agora entram no contrato canônico de `allowed_values/state_delta`; valor completo, alias/fragmento inequívoco e índice consomem o slot sem perder N8 Livre, acompanhamentos ou carnes anteriores;
- `vermelho` antes falhava por igualdade estrita; `macarrão vermelho` era recuperado como componente, mas o `ACOMPANHAMENTO` rejeitado permanecia no missing state. Ambos agora resolvem e avançam sem review;
- a tela Conversas expõe que já existe análise persistida em `AutomationEvent` e mostra `Ver detalhes` no bloco do Copiloto, independente de alerta e em modo automático ou manual; `Analisar conversa` permanece quando ainda não há análise;
- validação: reprodução vermelha confirmada; pós-correção 3 testes focados / 45 assertions e regressões Copilot 95 / 781 — PASS; Pint, `composer run lint:check`, lint/build frontend e `git diff --check` — PASS; migration nova: nenhuma;
- blocker restante: smoke real Meta do follow-up corrigido e smoke visual do CTA `Ver detalhes`.

---

## Fechamento operacional — detalhes, pagamento e taxa — 2026-09-05

- `Ver detalhes`: análise manual agora marca `last_ai_suggestion_at`; listagem completa, detalhe e polling incremental detectam análises persistidas, inclusive `AutomationEvent`, sem depender de alert e nos modos manual/automático;
- pagamento: projeções financeiras não substituem `ready_to_print`; confirmar Pix atualiza `Payment`, totais e financeiro, preserva o status operacional, histórico e idempotência, sem duplicar cobrança ou ampliar autoridade da IA;
- taxa por faixa: edição mantém string livre durante digitação, aceita vírgula/ponto, vazio para redigitar e normaliza no blur; envio permanece em centavos canônicos e o cálculo não mudou;
- validação diretamente afetada: 44 testes / 323 assertions — PASS; parser 0/6/6,5/6,50/6.50/10/10,90/vazio — PASS; lint/build frontend, Pint, `composer run lint:check` e `git diff --check` — PASS;
- migrations: nenhuma; blocker restante: smoke visual/runtime dos três fluxos no ambiente real.

---

## Entrega — validação condicional das regras de preço — 2026-09-05

- `per_km` exige `rate_per_km_cents >= 1` e dispensa faixas; `distance_bands` exclui o rate irrelevante, inclusive legado `0`, e exige ao menos uma faixa com distância positiva e taxa não negativa;
- faixa de 1 km / R$ 12,30 persiste como `1000` metros / `1230` centavos e mantém o cálculo canônico;
- validação: 3 testes focados / 36 assertions e regressões Delivery 13 / 114 — PASS; Pint, `composer run lint:check` e `git diff --check` — PASS; frontend não tocado; migration: nenhuma;
- blocker restante: somente smoke real de salvar novamente a regra por faixas.

---

## Lifecycle operacional Pedido → Impressão → Montagem → Entrega — 2026-09-06

- lifecycle canônico fechado sem nova state machine: `ready_to_print → printed → in_preparation → ready_for_pickup → out_for_delivery → finished`; `ready_for_pickup` continua sendo o estado de pronto tanto para entrega quanto para retirada;
- impressão: abrir a janela agora registra somente o início explícito (`PrintJob=printing`); `Confirmar impressão` é a confirmação humana, registra evento, preserva `Payment`/responsável e avança o pedido para preparo;
- idempotência: retry de início reutiliza a prévia/job em andamento; confirmar duas vezes não duplica evento/transição; reimpressão cria cópia auditável, mantém a confirmação anterior e não regride Order nem duplica Payment;
- montagem: Pedidos expõe `Confirmar montagem`, que reutiliza `DeliveryWorkflowService::markReady` e leva ao `ready_for_pickup` com histórico, sem finalizar;
- Entregas: o presenter projeta `quoted` como aguardando preparo e `ready` como pronto para sair; `Saiu para entrega` fica desabilitado antes de pronto e habilitado somente após montagem; atalhos `in_preparation → out_for_delivery` foram removidos e o service valida a mesma regra;
- pickup: confirmação de impressão → preparo → montagem → pronto para retirada → retirada/finalização, sem exigir despacho de delivery;
- consistência visual: status do pedido e status da comanda permanecem dimensões separadas; visualizar/reimprimir após confirmação não volta a comanda para “Aguardando impressão”;
- edição após impressão: invariantes existentes foram preservados — pedido pago/impresso/em preparo/pronto não aceita edição destrutiva de itens; consulta e reimpressão continuam disponíveis, sem criar versionamento paralelo nesta rodada;
- RBAC: visualizar usa `printing.view`; iniciar/confirmar impressão usa `printing.manage`; montagem/despacho continuam em `orders.manage`; usuário sem autoridade recebe 403 sem efeitos colaterais;
- validação sem sobreposição: workflows de Print/Delivery/Order 69 testes / 538 assertions; lifecycle sequencial/RBAC 4 / 74; Payment regression 13 / 117; proteção de autoexecução da IA 1 / 7; roles/permissões 4 / 40 — total 91 testes / 776 assertions, PASS;
- frontend: `npm run lint` PASS sem erros (um warning preexistente em `UserAvatar.tsx`); `npm run build` PASS (warning conhecido de chunk grande);
- Pint somente nos PHP relacionados e `composer run lint:check` — PASS; migration nova: nenhuma;
- blocker automatizado restante: nenhum; pendente somente smoke manual do lifecycle completo no navegador/impressora e reflexo imediato na tela Entregas.

---

## Fechamento final — entrega, detalhes do Copilot e checkbox — 2026-09-06

- pedido entregue: o backend já finalizava canonicamente `Order=finished`, `delivery_status=delivered`, histórico e `finished_at`; o pedido permanecia em Ativos porque `DeliveryPage` atualizava somente sua fila local e deixava o snapshot global de Pedidos obsoleto. A ação de saída/conclusão agora recarrega também o snapshot operacional, projetando imediatamente `finished/finalizado`, fora de Ativos e dentro de Concluídos;
- idempotência financeira: repetir a confirmação de entrega mantém uma única transição `delivery_finished`; `Payment` confirmado permanece intacto;
- Copilot: listagem incremental e detalhe já expunham `hasCopilotAnalysis`, mas o snapshot operacional usado no F5/reload omitia o campo. O snapshot agora consulta os eventos canônicos de decisão/sugestão e sempre envia booleano; o merge frontend também preserva uma análise já conhecida contra payload resumido, sem depender de alert e sem alterar a arquitetura/autoridade da IA;
- checkbox de aviso: o `width: 100%` global aplicado a inputs causava o controle gigante. Override local em Entregas fixa 18 × 18 px, alinhamento inline, área clicável pelo label, foco visível e mantém checked/unchecked controlado;
- validação sem sobreposição: lifecycle operacional 4 testes / 83 assertions; Copilot 18 / 100; polish operacional 7 / 71; Delivery 4 / 42; Payment 13 / 117 — total 46 testes / 413 assertions, PASS;
- frontend: `npm run lint` PASS sem erros (um warning preexistente em `UserAvatar.tsx`); `npm run build` PASS (warning conhecido de chunk grande);
- migration nova: nenhuma;
- blocker automatizado restante neste escopo: nenhum; pendente apenas smoke manual no navegador para retorno Entregas → Pedidos, F5/reseleção do Copilot e conferência visual/click do checkbox.

---

## V1 blocker definitivo — orquestração contextual e CTA persistente — 2026-09-07

- causa raiz do turno `como funciona essa N8 Livre?`: `CopilotLatestMessageIntentResolver` aceitava `funciona` isoladamente no fast path de horário; o winner `BUSINESS_HOURS_REQUEST` encerrava o turno antes do provider e `CopilotBusinessHoursReplyBuilder` emitia o template local de horário não configurado;
- precedência corrigida: horário determinístico agora exige evidência inequívoca de abertura/fechamento/horário; explicações, comparações e referências de produto com contexto recente chegam à camada semântica, depois são grounded pelo catálogo e passam pelos guards/authority existentes;
- conversation frame: continua limitado à janela configurada e inclui inbound/outbound, pending order, pending slot, último objetivo válido, opções canônicas e referências recentes; objetivo e referências agora são recuperados independentemente dos eventos read-only intermediários;
- referents: produtos que a própria resposta apresentou são persistidos como `conversation_references`; explicações reduzem o foco ao produto atual, o follow-up da Casa forma o par Casa/Livre e a comparação mantém esse par; `pode ser a livre` só aplica o `state_delta` canônico quando a opção é inequívoca;
- resposta comercial: listagem genérica ganhou orientação Casa versus Livre; explicações e comparações são formatadas com nome, preço, descrição, regra de montagem e cardinalidade de carnes reconstruídos do catálogo atual, nunca do texto livre do provider;
- modelo auditado em runtime: `gpt-5.6-luna`; não foi alterado;
- `Ver detalhes`: a causa real era a combinação de predicados duplicados entre snapshot/list/detail e `selectedConversation` alimentada por payloads resumidos/stale sem hidratação do detail após seleção; o estado local do resultado podia desaparecer ao desmontar/reselecionar o painel;
- fonte canônica: `ConversationCopilotAnalysisAvailability` centraliza o predicado de disponibilidade (`last_ai_suggestion_at` ou evento persistido compatível), o `withExists` e os tipos de evento; snapshot, list, detail e respostas de troca de modo usam a mesma regra;
- frontend: seleção/reseleção hidrata o detail canônico; análise manual busca o detail persistido antes de atualizar o snapshot; polling continua fazendo merge monotônico e não depende de alerta nem do state transitório do componente;
- integração real coberta sem OpenAI externa: a sequência `opa bom dia` → discovery → explicação Livre → explicação Casa → comparação → seleção Livre → buffet percorre `CopilotAutomationService`/`ConversationCopilotService`, persiste eventos/respostas, mantém N8 Livre e não cria `Order`, `Payment` ou review;
- validações: `CopilotConversationalIntelligenceTest` 24 testes / 351 assertions; `CopilotAutomationServiceTest` 33 / 332; `ConversationCopilotTest` 19 / 118; regressões determinísticas diretamente afetadas 6 / 59; `composer run lint:check` PASS; `npm run lint` PASS com 0 erros e 1 warning preexistente em `UserAvatar.tsx`; `npm run build` PASS com warning conhecido de chunk; `git diff --check` PASS;
- migration nova: nenhuma;
- blockers reais restantes: repetir o smoke pela Meta/WhatsApp e o ciclo visual initial load/F5/poll/reseleção no navegador real; antes de produção, rotacionar a credencial OpenAI que apareceu em saída local de diagnóstico desta sessão.

---

## V1 blocker final — Turn Loop orientado a estado e causalidade — 2026-09-08

- arquitetura final: o runtime segue `inbound persistido → context package → semantic turn interpretation → resolução canônica → validação de domínio → reducer incremental → next action → reply grounded → authority → dispatch causal`; não foi criada engine/state machine paralela;
- precedência: linguagem livre de pedido, continuação, correção, confirmação e mensagem geral passa pelo interpreter antes de um intent local genérico encerrar o turno; continuam determinísticos os greetings exatos, guards financeiros/administrativos, handoff explícito, webhooks/idempotência e respostas estruturalmente inequívocas a opção pendente, inclusive índice/ordinal resolvido;
- context package: draft durável, produto/slots, pending slot, último objetivo outbound, opções/referências recentes, inbound e outbound limitados com IDs/timestamps, estados fulfillment/payment e fatos canônicos são enviados ao interpreter; falha/baixa confiança do provider não apaga o draft nem reinicia a conversa;
- causa do discovery insuficiente: o ramo de order clarification listava essencialmente label/preço e consultava uma projeção incompleta da configuração. `CopilotProductDecisionFacts` agora projeta da configuração/catálogo atuais os fatos decisivos Casa/Livre, composição/montagem, allowance de carnes, adicionais e preço, reutilizados por discovery, menu e informação sem hardcode por SKU;
- causa do review prematuro: `UNRESOLVED_MEAT`/`AMBIGUOUS_MEAT` não eram recuperáveis na authority e a cardinalidade era interceptada antes do quote canônico. Missing slots ordinários agora geram a próxima pergunta grounded; excesso resolvível produz `MEAT_ALLOWANCE_EXCEEDED`; nenhum desses ramos cria alert de review;
- regras canônicas: catálogo/Product + `StructuredProductConfigurationService` definem produto, composição, disponibilidade e cardinalidade; `OrderItemSelectionValidator` + `TraditionalMarmitaBeefRuleService` calculam modos de carne, adicional padrão, churrasco e bife. O quote backend produzido por esse fluxo é projetado no draft validado e alimenta a constraint; o Copilot não recalcula a exceção por keyword;
- reducer: cada turno aplica somente delta validado sobre `pending_order_state`; perguntas informativas são explicitamente read-only (`state_delta=[]`); correção semântica de carne aceita apenas operação add/remove/replace/set grounded na mensagem e em carne canônica do dia, preservando produto, componentes e demais slots;
- next action/reply: perguntas informativas têm prioridade, depois constraints, próximo missing slot e lifecycle do pedido; `ResolvedTurn` registra goal, state summary, answers, canonical facts, applied changes, constraints, next question e prohibited claims. Reply do provider só sobrevive quando compatível; discovery, missing slot e constraints são recompostos pelo backend e marcados como tal no trace;
- causa do greeting stale: retry de evento multipart já falho podia reenviar sem testar se o inbound ainda era corrente; o dispatch também validava causalidade somente antes do conjunto, e a ordenação usava ID local em vez do timestamp de ocorrência. Agora retry e cada parte revalidam o watermark por `COALESCE(received_at, created_at), id`; turno superado é skipped e nunca enviado;
- rastreabilidade: cada `AutomationEvent` persiste `turn_id`, IDs Meta acionadores e IDs internos, state/pending antes, interpretação semântica, resolução canônica, delta, constraints, state depois, next action, flags de interpreter/naturalizer, reply, review reason e `stale_discarded`, sem segredo/token;
- cenário testemunha: discovery → N8 Livre → componentes sem carne → pergunta read-only de carnes → três carnes com quote/constraint → correção retirando churrasco percorreu webhook, jobs, automation, conversation, semantic adapter, validator, reducer, reply e dispatch; preservou o draft, não criou review/alert, `Order` ou `Payment`, e continuou normalmente;
- stale-turn: `oi` foi persistido/agendado; antes do job chegou `quero uma N8 Livre`; o job antigo ficou skipped sem outbound, o atual respondeu ligado ao inbound correto e retry do antigo não ressuscitou a saudação. Também há cobertura de inbound tardio por timestamp e invalidação entre partes de reply;
- testes verdes diretamente afetados: Turn Loop 2/41; Conversational Intelligence + Turn Loop 27/408; Automation Service + provider 55/438; context/pipeline/conversation/provider 98/602; safe clarification/proposal/intelligence 48/515; authority/workflow 44/474; regra canônica de carnes 8/16. OpenAI real não foi chamado;
- validação estática: Pint nos PHP tocados, `composer run lint:check` e `git diff --check` — PASS; frontend não foi tocado, portanto npm lint/build não foram executados;
- migration nova: nenhuma; modelo runtime `gpt-5.6-luna` preservado e agora recebe contexto estrutural suficiente para o contrato, restando validar qualidade linguística no smoke real;
- sinal vermelho fora deste Turn Loop: na execução ampla WhatsApp, 45 testes passaram e `WhatsAppProviderTest::test_systemic_meta_failures_are_global_and_deduplicated_per_account` continuou falhando apenas na projeção do alert global na listagem (alert é criado/deduplicado corretamente). Não foi alterado nesta rodada por não decorrer de missing slot/constraint/stale turn;
- blockers reais antes de produção: repetir o smoke Meta do cenário e da concorrência com credenciais/tráfego reais; triar separadamente a projeção do alert sistêmico acima; manter a rotação da credencial já registrada no checkpoint anterior.

---

## Turn Loop V1 — consolidação definitiva do estado de venda — 2026-09-08

- `AutomationEvent.payload.order_context` passou a ser o snapshot conversacional canônico de cada turno, inclusive nos turnos informativos/read-only: preserva draft validado, slots resolvidos/pendentes, constraints, confirmação do item, decisão sobre mais itens, fulfillment, endereço/localização, pagamento, referências, `phase`, `next_objective`, objetivo do assistente e situação efetiva de review;
- um único `CopilotTurnStateReducer` agora reduz estado anterior + delta semanticamente interpretado e validado; o renderer de resposta apenas apresenta o objetivo decidido, sem reconstruir pedido a partir do texto da IA e sem criar engine de pedido paralela;
- lifecycle explícito coberto: discovery/seleção → montagem/constraint → confirmação do item → mais itens → retirada/entrega → endereço/localização → cotação canônica → forma de pagamento → `WAIT_PAYMENT_PROOF`; confirmação e correção naturais usam o interpreter com backend como autoridade;
- perguntas de buffet/cardápio/preço permanecem read-only, respondem a dúvida e retomam a CTA pendente sem apagar produto, escolhas ou objetivo; missing slots, cardinalidade e constraints recuperáveis continuam automáticos e não abrem human review;
- ao materializar entrega, o fluxo reutiliza `OrderWorkflowService` e `DeliveryRoutingService`; o evento é atualizado com o snapshot do pedido ativo e avança para pagamento. A seleção de Pix reutiliza `PaymentWorkflowService`, cria somente pagamento aguardando comprovante, informa chave/total canônicos e nunca confirma valor pago;
- estado do turno ativo continua disponível após a criação da `Order`: o context builder combina o snapshot canônico anterior com a projeção atual do pedido, impedindo regressão para `CONFIRM_ITEM` e mantendo idempotência/stale guards existentes;
- cenário testemunha de 10 turnos passou ponta a ponta: pedido genérico → N8 Livre → buffet read-only → componentes → Almôndega e Porco → confirmação → sem mais itens → entrega → endereço/cotação → Pix; resultado final `WAIT_PAYMENT_PROOF`, `Payment=awaiting_proof`, `confirmed_amount_cents=0`, nenhum alert/review atual e nenhuma duplicação;
- validação: Turn Loop 4 testes / 129 assertions; conversa/inteligência 44 / 488; automação/idempotência/stale 36 / 349; contexto/authority 22 / 238; pipeline 43 / 271; regras de marmita + delivery + payment 34 / 247 — total sem sobreposição de 183 testes / 1.722 assertions, todos PASS; Pint nos PHP tocados, `composer run lint:check` e `git diff --check` — PASS;
- frontend não foi tocado e npm lint/build não foram executados; migration nova: nenhuma; commit/push/stash/reset/clean/troca de branch: nenhum;
- blockers externos permanecem: smoke real Meta/WhatsApp do fluxo e concorrência, triagem separada da projeção do alert sistêmico já registrada e rotação da credencial exposta anteriormente.

---

## Turn Loop V1 — fechamento causal do turno — 2026-09-09

- o inbound acionador agora é explícito em todo o caminho `CopilotAutomationService → ConversationCopilotService → ConversationCopilotContextBuilder`; ID interno/Meta e timestamp desse trigger formam o envelope do turno, mesmo quando webhook tardio encontra outbound ou evento posterior na conversa;
- `AutomationEvent` continua sendo memória operacional, mas objetivo, referência, pending state e opções anteriores são filtrados pelo boundary causal do inbound atual; evento posterior não retroage para completar ou redirecionar turno antigo, enquanto a projeção do `Order` ativo mantém a continuidade canônica necessária;
- o interpreter semântico é memoizado por turno: cada inbound pode fazer no máximo uma chamada lógica ao provider, incluindo recuperação/fallback; testes usam doubles/fakes e nenhuma chamada OpenAI real foi feita;
- depois da validação de domínio, um único `CopilotTurnStateReducer` decide estado canônico, `phase`, missing slots e `next_objective`; somente então o compositor final produz a resposta. Producers anteriores não podem reabrir produto, ressuscitar opções antigas ou substituir o objetivo decidido;
- `ASK_MEAT` usa exclusivamente as carnes canônicas vigentes e o estado novo; `ASK_PRODUCT` apresenta as marmitas reais; item inválido ou missing obrigatório nunca avança para `CONFIRM_ITEM`; confirmação curta não apaga pendência e candidato permanece separado de seleção confirmada;
- perguntas read-only devolvem draft externo vazio, preservam o pedido somente no envelope canônico, respondem a informação uma vez e retomam uma única CTA. O compositor limita o turno a uma mensagem ou, quando informação + continuação são ambas necessárias, no máximo duas;
- o resultado persistido sincroniza `reply_messages`, trace, envelope, `phase`, `next_objective`, objetivo e estado após efeitos canônicos; entrega/Pix continuam reutilizando os workflows existentes, sem confirmar pagamento e sem duplicar `Order`/`Payment`;
- alerts atuais são resolvidos somente quando têm causa explícita que deixou de existir; alert genérico sem causa e proteções financeiras/administrativas não são limpos por inferência;
- regressão testemunha de 14 turnos e venda completa de 10 turnos cobrem discovery, candidato/seleção, componentes/carnes, confirmação, read-only, fulfillment, endereço, cotação, Pix, boundary, webhook tardio, stale/retry e idempotência;
- validação final sem sobreposição entre os conjuntos listados: Turn Loop 7/308; Conversational Intelligence 25/369; Automation Service 36/349; deterministic intents 43/404; context boundary 19/138; authority 5/106; safe clarification/proposal 23/147; Payment 13/117; Delivery 4/42; pipeline/provider fake 62/360 — total 237 testes / 2.340 assertions, todos PASS;
- Pint nos PHP tocados, `composer run lint:check` e `git diff --check` — PASS; frontend e migrations não foram alterados; modelo runtime preservado; commit/push/stash/reset/clean/troca de branch: nenhum;
- blockers externos permanecem os já registrados: smoke real Meta/WhatsApp e concorrência, triagem separada da projeção do alert sistêmico e rotação da credencial anteriormente exposta.

---

## Copilot V1 — fechamento causal final de entidades, recovery e continuidade — 2026-09-10

- resolução canônica passou a reservar spans por identidade/prioridade e a preservar grafias compactas e separadas (`coca600`/`coca 600`) sem perder ambiguidades reais; nome canônico continua tendo precedência sobre alias de catálogo e alias inferido;
- `N8` sem variante permanece candidato Casa/Livre; somente referência explícita a Livre/Tradicional, preço canônico inequívoco ou a regra explícita `só/somente/apenas bife` pode groundedar `n8-tradicional`; testes antigos que pretendiam Livre foram tornados explícitos;
- recovery de múltiplos produtos deixou de resolver `product=n8` pela primeira entidade da mensagem inteira: cada N8 é recuperado pelo próprio segmento, itens explícitos são ordenados pela ordem do cliente e N5/N8 não desaparecem quando o provider omite, inverte ou sugere a variante errada;
- seleção diária de carnes conserva a diferença entre `AMBIGUOUS_MEAT` e `UNRESOLVED_MEAT`; múltiplas carnes válidas continuam multi-select, e uma escolha ambígua permanece candidata sem apagar carnes inequívocas do mesmo item;
- clarificações inválidas ou ainda ambíguas reidratam o produto pendente sem fabricar seleção; novo ciclo após pedido cancelado não herda salada/carne antigas, e item/produto irresolvido é descartado com missing causal em vez de permanecer como draft inválido;
- componentes explícitos, inclusive listas longas e fragmentos/índices, permanecem no estado incremental; perguntas de preço/cardápio continuam read-only e retomam a menor CTA necessária;
- respostas Pix e incompatibilidade Casa/Livre são consolidadas em uma única mensagem lógica; multipart permanece apenas quando as partes são distintas e intencionais, limitado pelo compositor existente;
- alerts continuam superseded/resolvidos apenas quando a causa concreta mudou; causas genéricas e proteções financeiras/administrativas não são limpas por inferência;
- pagamento, entrega e lifecycle reutilizam os workflows canônicos; proof nunca confirma pagamento, e nenhum fluxo desta rodada criou engine paralela, preço, desconto, migration ou alteração frontend;
- validação final sem sobreposição: Conversational Intelligence 25/369; Automation Service 36/349; Deterministic Intent 43/409; Pipeline 43/272; Turn Loop 9/373; Context Boundary 19/138; Authority 5/106; Safe Clarification + Proposal Delta 23/147; Conversation Copilot 19/121; Payment 13/117; Delivery 4/42; Operational Lifecycle 4/83; Print Workflow 17/182; Canonical Entity Resolver 3/10 — total 263 testes / 2.718 assertions, todos PASS;
- OpenAI real não foi chamado; o modelo runtime não foi alterado; Pint nos PHP relacionados, `composer run lint:check` e `git diff --check` passaram;
- commit, push, stash, reset, clean e troca de branch: nenhum; working tree dirty preexistente preservado;
- blocker automatizado restante neste escopo: nenhum. Permanecem apenas os itens externos já registrados: smoke real Meta/WhatsApp e concorrência, triagem separada da projeção do alert sistêmico e rotação da credencial anteriormente exposta.

---

## V1.0.0 FREEZE CANDIDATE — READY — 2026-09-11

- branch auditada: `feature/production-operational-polish`;
- escopo V1 auditado: CRM operacional, dashboard, conversas, caixa, pedidos, cardápio, entregas, pagamentos/Pix, financeiro, clientes, relatórios, configurações, usuários/permissões, perfil/empresa, WhatsApp/API, IA e automação, Labia, impressão, workflows, Copilot, documentação, testes e migrations acumuladas;
- suíte backend integral final: **PASS** — 764 testes / 7.133 assertions via `php -d memory_limit=512M vendor/bin/phpunit`, sem alterar configuração permanente;
- os quatro blockers originais e os dois blockers finais foram corrigidos e revalidados; não resta blocker automatizado conhecido para o gate de freeze;
- lint backend (`composer run lint:check`): PASS;
- frontend: `npm ci` PASS; `npm run lint` PASS com 1 warning não bloqueante em `UserAvatar.tsx`; `npm run build` PASS com warning de chunk grande;
- `git diff --check`: PASS;
- migrations: as sete migrations acumuladas de identidade/suporte/horários especiais/weight pricing/seller attribution/seller eligibility/permission_user estão executadas no banco local e pendem de `php artisan migrate --force` em produção;
- secrets: `.env` permanece ignorado e nenhum secret real foi encontrado em arquivo versionável; placeholders dos `.env.example` permanecem vazios;
- blockers do freeze automatizado: nenhum; nenhum staging ou commit de freeze foi realizado;
- pendências externas antes de produção: rotação manual obrigatória da credencial OpenAI anteriormente exposta; smoke real Meta/WhatsApp e concorrência; smokes manuais de UI/runtime, staging e Epson;
- smoke de produção permanece pendente; nenhum push, merge, tag ou deploy foi realizado.

---

## Correção dos blockers originais do freeze V1.0.0 — 2026-09-11

- os quatro failures originais foram reproduzidos isoladamente: `AiAutomationSettingsTest` (1), `ConversationCopilotEvaluationTest` (2) e `CopilotOnlineEvaluateCommandTest` (1);
- Coca-Cola 2L: o resolvedor canônico por spans recebia aliases compactos sem as tokenizações humanas equivalentes (`coca2l` versus `coca 2l`), caindo indevidamente na resposta de família; os aliases agora preservam formas humanas e compactas, unidades, conectores e plurais explícitos sem voltar ao matcher antigo por substring;
- Evaluation AI-010 e outros cenários N8 Livre do mesmo dataset ainda usavam `N8` sem variante, contrariando Candidate != Confirmed; os inputs desses cenários foram alinhados para mencionar Livre explicitamente, preservando os objetivos de carne/bife/cardinalidade;
- evaluation cross-tenant: `CopilotOrderDraftValidator` produzia `UNRESOLVED_MENU_ITEM`, mas o recovery de `UNKNOWN` substituía a análise e apagava o warning; o recovery agora mantém os warnings de validação, além da clarificação customer-facing;
- Online Evaluate status 1 era consequência dos casos de evaluation incluídos no smoke; voltou a status 0 sem bypass ou mudança no contrato do comando;
- grupos focados finais: 20 testes / 694 assertions — PASS;
- regressões diretamente relacionadas (canonical resolver, deterministic intents, conversational intelligence, turn loop, automation, pipeline, evaluation, online command e sandbox): 180 testes / 2.480 assertions — PASS;
- `php -d memory_limit=512M artisan test` não propagou o limite ao subprocesso PHPUnit e ainda encerrou em 128 MB, depois de 336 testes / 3.336 assertions PASS; o entrypoint direto confirmou 512 MB sem alterar configuração permanente;
- suíte integral via `php -d memory_limit=512M vendor/bin/phpunit`: 764 testes, 762 PASS, 2 FAIL e 7.116 assertions; não houve novo esgotamento de memória;
- os dois failures integrais restantes reproduzem isoladamente e estão fora dos quatro blockers autorizados: `SolRestaurantMenuRecoveryCommandTest` não restaura/encontra `n9-tradicional`, e `WhatsAppProviderTest` não projeta o alert sistêmico Meta esperado;
- estado do freeze global permanece **BLOQUEADO** por esses dois failures adicionais; os quatro blockers originais desta rodada estão corrigidos;
- nenhuma migration ou alteração frontend; OpenAI real não foi chamada; modelo runtime e `.env` não foram alterados; nenhum stage, commit, push, merge, tag ou deploy foi realizado.

---

## Correção dos dois blockers finais do freeze V1.0.0 — 2026-09-11

- Menu Recovery: o comando já restaurava o catálogo canônico versionado, incluindo `N9 Livre` por R$ 19,00; a única divergência era a expectativa obsoleta de R$ 18,00 em `SolRestaurantMenuRecoveryCommandTest`, contrariando `SolRestaurantProductCatalogSeeder`, `SolRestaurantOfficialMenuSeederTest` e `V1_PRODUCT_RULES.md`. A expectativa foi alinhada sem alterar regra comercial, seeder ou runtime;
- idempotência do recovery foi reforçada: uma segunda execução com `--force-official` preserva as quantidades de produtos, grupos de opção e vínculos de componentes, sem duplicar registros/regras; o arquivo focado passou com 4 testes / 26 assertions;
- WhatsApp sistêmico: classificação, criação e deduplicação já estavam corretas no domínio, usando `company_id` mais `whatsapp-systemic:{whatsapp_account_id|default}:{error_code}` e alert sem conversa; a listagem operacional excluía indevidamente todo alert com `conversation_id = null`, tornando o incidente global invisível;
- a projeção global agora inclui alerts acionáveis da empresa mesmo sem conversa, mantendo o filtro canônico por `company_id`; falhas conversacionais continuam com chave por conversa, e falhas transitórias continuam classificadas separadamente;
- cobertura reforçada prova: duas mensagens com o mesmo erro sistêmico na mesma conta geram um alert; outra conta da mesma empresa gera outro incidente; outra empresa permanece isolada. `WhatsAppProviderTest` passou com 69 testes / 428 assertions;
- regressões relacionadas: menu/catálogo/aliases/grounding 193 testes / 1.997 assertions e WhatsApp/webhook/retry/stale/deduplicação/Automation 124 testes / 919 assertions — todos PASS;
- gate integral final: `php -d memory_limit=512M vendor/bin/phpunit` — **764 testes / 7.133 assertions, todos PASS**, sem erro de memória ou failure mascarado;
- Pint nos PHP tocados, `composer run lint:check` e `git diff --check` — PASS; frontend não foi tocado nesta rodada e permanece com a validação anterior; nenhuma migration foi criada ou alterada;
- status atual: **V1.0.0 FREEZE CANDIDATE — READY**. Permanecem externos ao gate automatizado: smoke de staging/Meta/Epson e rotação obrigatória da credencial OpenAI antes de produção;
- nenhum stage, commit, push, merge, tag, deploy, stash, reset, clean ou troca de branch foi realizado.

---

## Múltiplos endereços, snapshots de entrega e Maps — 2026-09-12

### Arquitetura final

- `Customer` continua usando a relação canônica `hasMany(CustomerAddress)`; não foi criada tabela paralela nem foram adicionados campos legados ao cliente;
- `CustomerAddressBookService` centraliza criação, edição, remoção e troca de padrão sob transação e lock do cliente; o primeiro endereço vira padrão e o banco impede dois padrões para o mesmo cliente;
- os payloads operacionais mantêm `address` como compatibilidade (endereço padrão) e passam a expor `addresses[]` completo;
- o modal Clientes permite listar, adicionar, editar, remover e definir o padrão, com CEP, label e os campos operacionais já existentes;
- a criação de pedido permite escolher explicitamente um endereço salvo ou usar endereço temporário; o temporário só é salvo no cadastro quando a equipe marca essa opção;
- a tela Entregas permite trocar o destino por outro endereço salvo e também salvar uma correção manual no cadastro quando isso é explícito;
- `Order.delivery_address_snapshot` é a fonte canônica para geocoding, rota, distância, taxa, mapa, impressão e histórico; `delivery_address_id` é apenas referência de proveniência;
- editar ou remover `CustomerAddress` não altera pedidos anteriores. A remoção só é bloqueada para vínculos legados sem snapshot; referências com snapshot podem ser anuladas pelas FKs existentes sem perda histórica;
- localização/endereço recebido pelo WhatsApp passa a ser snapshot temporário do pedido e não cria endereço reutilizável silenciosamente. Endereços salvos aparecem no contexto como candidatos, com política de confirmação explícita;
- Maps preserva a separação existente: chave server apenas no backend, browser key em `VITE_DELIVERY_GOOGLE_BROWSER_API_KEY` e providers fake em dev/test. O fallback sem configuração continua operacional.

### Migration

- criada `2026_09_12_000013_harden_customer_address_defaults.php`;
- backfill aditivo: corrige `company_id` a partir do cliente, preenche label ausente com `Principal`, preserva o primeiro default válido (ou escolhe deterministicamente o primeiro endereço) e remove defaults duplicados;
- índice parcial único `customer_addresses_one_default_per_customer` garante no máximo um endereço padrão por cliente em PostgreSQL e SQLite;
- não foi executado `migrate:fresh`, `refresh`, `wipe` ou qualquer operação destrutiva. A migration deve seguir o runbook normal, com backup e `php artisan migrate --force` somente no deploy aprovado.

### Arquivos principais alterados

- backend: controllers de Customer/Order/Delivery, `CustomerAddressBookService`, `DeliveryWorkflowService`, `DeliveryRoutingService`, presenter operacional, contexto/automação do Copilot, rotas e testes focados;
- frontend: tipos e `crm.service.ts`, `CustomerEditor`, fluxo de novo pedido em `App`/`OperationalModalContent`, tela/CSS de Entregas e estilos globais;
- documentação: este checkpoint e `docs/operations/PRODUCTION_DEPLOYMENT.md` com o procedimento externo de Maps.

### Validação

- grupos diretamente afetados (Customer/Order/Delivery/Copilot/turn loop): 71 testes / 898 assertions — PASS;
- grupo final Customer/Delivery/Operational: 34 testes / 253 assertions — PASS;
- suíte backend integral com limite temporário de 512 MB: **774 testes / 7.182 assertions — PASS**;
- `composer run lint:check`: PASS;
- `npm run build`: PASS, apenas warning preexistente de chunk grande;
- `npm run lint`: PASS após correção do novo código, mantendo apenas o warning preexistente em `UserAvatar.tsx`;
- `git diff --check`: PASS;
- nenhuma chamada real a Google, OpenAI ou Meta; nenhum `.env`, servidor, Cloudflare ou infraestrutura foi alterado.

### Riscos e próximos gates

- executar a migration primeiro em staging aprovado e repetir smoke de Clientes → Pedido recorrente → Entrega → rota/taxa;
- validar manualmente as restrições das duas chaves Google, origem do restaurante e renderização Maps/Places/Routes no domínio de staging;
- confirmar no smoke WhatsApp que endereço padrão é apenas sugerido e que clientes com múltiplos endereços recebem a pergunta de escolha;
- o freeze automatizado está verde, mas esta atualização posterior ao hash `5e0cb664c0f63a0fc203b7b82c6415c3545859c8` exige novo smoke/staging antes de qualquer promoção;
- nenhum commit, push, merge, tag ou deploy foi realizado.

---

## Comanda sem componente duplicado e ovo estruturado na N5 — 2026-09-12

### Causa e prevenção da duplicação

- o fluxo estruturado atual já persiste arroz, feijão, salada e carne como composição/opções do `OrderItem` da marmita; ele não cria um segundo item para esses componentes;
- a duplicação observada exige uma linha legada ou malformada persistida separadamente como `OrderItem` de valor zero, com nome igual ao componente já incluído no item pai. A impressão enumerava todos os itens persistidos e, por isso, também exibia essa linha autônoma;
- `OrderWorkflowService::assertNotDuplicatedIncludedComponent` agora protege tanto inclusão quanto edição: somente um candidato de preço unitário zero, sem opção paga, é comparado aos componentes incluídos nos demais itens do mesmo pedido. A identidade é normalizada para ASCII, minúsculas e caracteres alfanuméricos;
- a comparação usa `selected_components` e apenas opções marcadas como incluídas no preço (`included_in_unit_price=true`) e originadas de `daily_menu_component` ou `product_group_component`, excluindo opções com `addition_code`;
- em coincidência exata, a operação falha antes de persistir. Produto gratuito/cortesia com outro nome, item independente não relacionado, produto de preço positivo e item com adicional pago continuam permitidos;
- a regressão cobre N8 Livre com Feijão tradicional e Arroz branco: ambos permanecem somente na composição e tentativas de linhas separadas de R$ 0,00 não são persistidas. Também comprova a permanência de cortesia real, componente gratuito não relacionado, item independente pago e item de base zero com opção paga.

### Compatibilidade de comandas históricas

- `PrintWorkflowService::printableOrderItems` filtra somente a forma histórica malformada: linha com preço unitário, opções e total todos zerados, sem opções próprias, cujo nome normalizado coincide com componente estruturado incluído em outro item do mesmo pedido;
- a proteção vale no payload de impressão e no ticket humano, sem alterar ou apagar a persistência histórica;
- a regressão injeta linhas históricas para Arroz branco, Feijão tradicional, Salada de macarrão e Filé de frango na chapa e comprova uma única ocorrência de cada nome dentro da N8 Livre; uma Cortesia da casa de valor zero continua impressa.

### N5 Casa + ovo

- ovo foi modelado no catálogo como adicional estruturado opcional da N5 Casa, grupo `adicionais`, componente `ovo-frito`, código de domínio `extra_egg` e preço de R$ 2,00 por unidade; não foi criado produto avulso genérico nem alterado o preço base da N5;
- a quantidade do adicional fica na metadata da opção (`addition_code`, `per_unit_quantity`, `line_quantity` e `unit_price_cents`), e o total é `R$ 2,00 × ovos por unidade × quantidade da linha`;
- pedido manual, edição, snapshot, presenter e impressão preservam quantidade e valor. N5 sem ovo mantém o preço base; um ovo soma R$ 2,00 e dois ovos somam R$ 4,00;
- N8 e N9 rejeitam `extra_egg`; o Copilot mantém `extra_egg: 0` como shape neutro e só converte menções explicitamente ancoradas na mensagem (`N5 com ovo`, `N5 mais um ovo`, `N5 com 2 ovos`) em adicional pago, sem transformar inferência ou observação livre silenciosamente;
- criada a migration aditiva `2026_09_12_000014_configure_n5_egg_addition.php` para aplicar a configuração em instalações existentes; nenhum banco externo ou ambiente foi alterado.

### Validação final posterior à proteção no workflow

- Orders + Printing: **70 testes / 551 assertions — PASS**;
- catálogo/seeders: **35 testes / 479 assertions — PASS**;
- Copilot focado no ovo estruturado: **2 testes / 18 assertions — PASS**; regressões relacionadas de provider/evaluation/online evaluation também passaram;
- endereços, Maps e lifecycle operacional, sem alteração na implementação concluída: **27 testes / 223 assertions — PASS** com limite temporário de 512 MB;
- suíte backend integral da versão final: `php -d memory_limit=512M vendor/bin/phpunit` — **781 testes / 7.276 assertions — PASS**;
- `composer run lint:check`: PASS;
- `npm run lint`: PASS sem erros, mantendo apenas o warning preexistente em `UserAvatar.tsx`;
- `npm run build`: PASS, mantendo apenas o aviso não bloqueante de chunk grande;
- `git diff --check`: PASS após esta atualização documental;
- nenhum `.env`, staging, produção ou servidor foi alterado; nenhum stage, commit, push, merge, tag ou deploy foi realizado.
