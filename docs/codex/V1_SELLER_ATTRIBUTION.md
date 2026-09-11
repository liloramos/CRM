# V1 Seller / Attendant Attribution

## Objetivo

Registrar quem efetivamente realizou/assumiu uma venda ou atendimento presencial sem exigir troca de login a cada venda.

---

## 1. Contexto operacional

O Restaurante Sol pode operar um computador com login compartilhado de atendimento.

Isso é aceitável para velocidade da V1.

Mesmo assim, o negócio precisa responder:

`Quem atendeu / registrou esta venda?`

---

## 2. UX V1

No Caixa/venda de balcão:

```text
Responsável / Atendente:
[ Beatriz ▾ ]
```

Nomes inicialmente conhecidos:

- Beatriz
- Larissa
- Helton
- Calebe

A lista deve preferencialmente ser configurável por empresa.

Não hardcodar esses nomes como regra de domínio.

---

## 3. Regras

- seleção rápida;
- não exigir login individual;
- opcional por padrão, salvo decisão posterior;
- persistir no Order/metadata/histórico canônico;
- preservar tenant isolation;
- não alterar preço;
- não alterar Payment;
- não apagar origem do pedido;
- não substituir `created_by` técnico se existir.

---

## 4. Três conceitos distintos

Não confundir:

### Login técnico

Usuário autenticado no CRM.

### Responsável/atendente

Pessoa que fez/assumiu a venda.

### Origem

Exemplo:

- WhatsApp automático;
- balcão/manual.

Um pedido pode ter:

`Origem: WhatsApp`

e depois:

`Responsável: Larissa`

sem reescrever a origem.

---

## 5. WhatsApp

Pedidos automáticos não precisam receber vendedor fictício.

Quando humano assumir/intervier e o fluxo suportar atribuição:

registrar responsável sem apagar:

- automation events;
- origem WhatsApp;
- histórico.

---

## 6. Persistência

Antes de migration:

1. procurar campo/metadata existente;
2. procurar estrutura de settings/configuração;
3. reutilizar audit/history existente.

Criar migration somente se necessário e com semântica clara.

---

## 7. Configuração da lista

Preferência V1:

lista simples por empresa.

Não construir módulo de RH.

Pós-V1 podem existir:

- User link;
- métricas;
- comissão;
- relatório por atendente.

---

## 8. Acceptance

- venda com Beatriz;
- venda com Larissa;
- venda sem responsável;
- reabrir preserva;
- finalizar preserva;
- cancelar preserva histórico;
- login compartilhado não troca responsável;
- origem permanece correta;
- tenant isolation.
