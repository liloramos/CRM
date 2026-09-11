# IA & Automação — Sandbox Contract V1

## Objetivo

A área `IA e Automação > Testar comportamento da IA` deve ser um ambiente read-only confiável para verificar como o Copilot responderia usando o estado REAL atual do restaurante.

Não deve ser um demo fake.

---

## 1. Problema observado em QA

Foi perguntado:

`quanto custa a coca 2l?`

O simulador respondeu, em essência:

`Essa opção não consta no cardápio disponível de hoje...`

e ofereceu somente algumas marmitas.

Isso contradiz o catálogo operacional observado, onde bebidas existem.

O comportamento precisa ser investigado no código.

Não assumir a causa sem teste.

Possíveis classes de causa:

- contexto do sandbox reduzido demais;
- filtro de "available today" inadequado para bebidas;
- leitura de categorias divergente do Copilot live;
- snapshot/cache antigo;
- builder/resolver específico do sandbox;
- catálogo canônico não compartilhado integralmente.

---

## 2. Regra principal

Sandbox e Copilot live devem compartilhar as mesmas fontes comerciais canônicas.

Diferença permitida:

- sandbox não envia WhatsApp;
- não cria Conversation real;
- não cria/atualiza Order;
- não cria/atualiza Payment;
- não altera operação.

Diferença NÃO permitida:

- preço diferente;
- produto diferente;
- disponibilidade comercial divergente sem motivo explícito;
- regras de Casa/Livre diferentes;
- buffet/carnes diferentes.

---

## 3. Modo read-only

O simulador pode:

- construir contexto real;
- chamar provider;
- normalizar;
- ground;
- aplicar authority policy;
- produzir classificação;
- produzir reply/reply_messages;
- mostrar reasoning operacional seguro/resumo de action.

Não pode:

- persistir mutação operacional;
- enviar Meta;
- confirmar pagamento;
- criar pedido real;
- criar cliente fake;
- alterar alertas reais.

---

## 4. Consultas de produto/preço

Perguntas como:

- `quanto custa a coca 2l?`
- `tem H2O?`
- `qual o preço da N8 Livre?`
- `quais bebidas tem?`

devem consultar o catálogo real atual.

Se item existe mas está inativo/indisponível:

responder essa condição de forma correta.

Se existe e está ativo:

não responder `não consta` por falha de contexto.

---

## 5. Real-time consistency

Após editar catálogo:

o sandbox deve refletir a alteração na próxima simulação.

Sem restart/deploy/prompt change.

Ver:

`CATALOG_REALTIME_CONSISTENCY.md`

---

## 6. Paridade com Live

Criar testes de contrato:

mesma pergunta + mesmo tenant + mesmo snapshot comercial

→ fatos comerciais equivalentes entre:

- sandbox;
- live Copilot service.

A linguagem pode variar.

Preço/produto/disponibilidade não.

---

## 7. Resultado visível

A UI atual exibe campos como:

- Resposta proposta;
- Classificação;
- Precisa de humano?;
- Ação proposta;
- Motivo;
- Modo atual.

Manter linguagem operacional clara.

Para falha de grounding/contexto:

não mascarar como simples `produto inexistente` se o sistema sabe que ocorreu erro técnico.

---

## 8. Testes obrigatórios

- bebida ativa encontrada;
- bebida inativa tratada como indisponível;
- preço muda → sandbox muda;
- produto novo aparece;
- categoria nova aparece quando aplicável;
- N8 Casa/Livre;
- buffet/carnes;
- Pix público;
- sandbox não persiste Order/Payment/Conversation;
- tenant isolation;
- parity live vs sandbox nos fatos.
