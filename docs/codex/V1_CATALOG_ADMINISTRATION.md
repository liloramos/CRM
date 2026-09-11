# V1 Catalog Administration — Restaurante Sol

## Objetivo

Permitir que a equipe administre o catálogo real do restaurante sem depender de código, seed novo, prompt da IA ou intervenção técnica.

Este contrato cobre:

- cadastro de produto;
- edição;
- ativação/inativação;
- exclusão segura/arquivamento;
- categorias;
- produtos de balcão;
- bebidas;
- itens de cardápio;
- disponibilidade;
- integração com o Copilot.

---

## 1. Problema observado

Na V1 atual existe uma entrada explícita para:

`Novo produto de balcão`

Isso atende doces, geladinhos e itens de venda rápida, mas não substitui um CRUD administrativo geral.

Foi observado que uma bebida nova, como uma H2O lata, acaba sendo cadastrada no contexto de "produto de balcão" por falta de uma entrada geral de produto.

Também foi observado que não existe fluxo operacional claro para:

- criar produto comum;
- criar categoria nova;
- excluir produto com segurança.

---

## 2. Regra de produto

A V1 deve ter um fluxo administrativo de:

`+ Novo produto`

independente do atalho específico de produto de balcão.

Campos mínimos, conforme o domínio atual suportar:

- nome;
- categoria;
- preço;
- descrição;
- status ativo/inativo;
- disponibilidade/dias;
- tipo/uso operacional;
- regras específicas quando aplicáveis.

Não criar campos duplicados se o domínio já tiver equivalentes.

---

## 3. Produto de balcão

`Produto de balcão` continua válido como conceito operacional.

Ele deve ser:

- um tipo/uso do produto;
- ou um fluxo rápido especializado;

mas não a única forma de cadastrar um produto novo.

Exemplos de balcão:

- doces;
- geladinhos;
- itens rápidos;
- outros itens vendidos diretamente no Caixa.

---

## 4. Categoria

A V1 deve permitir:

- criar categoria;
- renomear categoria;
- ordenar/apresentar conforme estrutura existente;
- inativar quando aplicável;
- excluir de forma segura.

Categoria é fonte canônica para:

- Cardápio;
- Caixa;
- Copilot;
- filtros;
- respostas customer-facing.

---

## 5. Exclusão segura de produto

Não usar hard-delete cego.

### Produto nunca referenciado

Se não houver dependência histórica relevante:

- pode permitir exclusão permanente, se a arquitetura atual suportar com segurança.

### Produto já referenciado por pedido/histórico

Não apagar fisicamente de forma que destrua:

- OrderItem;
- histórico;
- relatórios;
- auditoria;
- impressão antiga;
- financeiro.

Nesse caso:

- arquivar/inativar;
- remover do catálogo operacional;
- impedir novas vendas;
- preservar snapshot/histórico existente.

A UI pode apresentar `Excluir`, mas o backend deve decidir se a operação é hard-delete segura ou archive/inactivate.

---

## 6. Exclusão segura de categoria

Categoria com produtos não deve sofrer cascade destrutivo silencioso.

Opções seguras:

- impedir e explicar;
- exigir mover produtos;
- inativar;
- usar estratégia canônica já existente.

Categoria vazia e sem dependências pode ser removida se o domínio permitir.

---

## 7. Ativo x excluído

Conceitos distintos:

### Inativo

- continua cadastrado;
- pode ser reativado;
- não deve ser oferecido como disponível.

### Arquivado/excluído logicamente

- removido do uso operacional normal;
- preservado para histórico quando necessário.

Não usar inativo como único substituto sem antes verificar se a semântica atual do sistema já atende.

---

## 8. Disponibilidade

Alterações de:

- status;
- dias;
- menu do dia;
- categoria;
- regra de disponibilidade;

devem refletir no catálogo canônico usado pelo Copilot e telas operacionais.

---

## 9. Permissões

Criação/edição/exclusão de catálogo é ação administrativa.

Respeitar RBAC existente.

Não expor mutação de catálogo ao Copilot customer-facing.

---

## 10. Auditabilidade

Mudanças administrativas relevantes devem preservar, conforme arquitetura atual:

- quem alterou;
- quando;
- valor anterior/novo quando já houver mecanismo;
- histórico suficiente para diagnóstico.

Não criar sistema de auditoria paralelo se já existe history/event log aplicável.

---

## 11. UX mínima V1

Na área administrativa de Cardápio deve existir caminho claro para:

- Novo produto;
- Nova categoria;
- Editar produto;
- Ativar/Inativar;
- Excluir/Arquivar com segurança.

O usuário não deve precisar "fingir" que uma bebida é produto de balcão para cadastrá-la.

---

## 12. Integração com IA

Qualquer produto ativo e comercialmente disponível no catálogo canônico deve poder aparecer no contexto do Copilot sem:

- editar prompt;
- alterar código;
- redeploy;
- reiniciar worker manualmente.

Detalhes em:

`CATALOG_REALTIME_CONSISTENCY.md`

---

## 13. Acceptance

- criar bebida nova;
- editar preço;
- mudar categoria;
- inativar;
- reativar;
- excluir produto nunca usado;
- arquivar produto já usado;
- criar categoria;
- impedir cascade destrutivo;
- Copilot refletir mudança atual;
- histórico de pedido antigo permanecer íntegro.
