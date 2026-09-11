# System Assistant Contract — V1

## Objetivo

O menu `Assistente` é o assistente interno do CRM.

Ele NÃO é o Copilot customer-facing do WhatsApp.

Sua função é ajudar a equipe a usar o sistema, localizar funções e responder perguntas operacionais internas com base no estado e permissões reais.

---

## 1. Problema observado

Pergunta:

`como eu vejo o preço de um produto?`

Resultado observado:

`Não consegui consultar o Assistente agora. Você ainda pode acessar as áreas pelo menu.`

Isso mostra que o Assistente ainda não está confiável para perguntas operacionais simples.

A causa deve ser investigada no código; não assumir se é provider, knowledge builder, rota, exception ou contexto.

---

## 2. Escopo do Assistente

Deve conseguir explicar, conforme permissões do usuário:

- como consultar/editar cardápio;
- como encontrar preço de produto;
- como cadastrar produto/categoria quando a feature existir;
- como confirmar Pix;
- como consultar entregas;
- como localizar cliente;
- como usar Caixa;
- como localizar pedido;
- como operar configurações permitidas.

---

## 3. Conhecimento

O Assistente deve usar duas classes de conhecimento:

### Knowledge de produto/sistema

- feature catalog;
- rotas/páginas;
- ações disponíveis;
- regras de uso;
- permissões.

### Estado read-only quando necessário

Exemplo:

`qual o preço atual da Coca 2L?`

Se o Assistente for autorizado a responder dados operacionais atuais:

- consultar fonte canônica read-only;
- respeitar tenant/RBAC.

Se a intenção for apenas:

`como vejo o preço de um produto?`

responder procedimento e CTA para Cardápio.

---

## 4. CTAs

Quando possível, responder com ação navegável existente.

Exemplo:

`Você pode consultar em Cardápio > Produtos e preços.`

CTA:

`Abrir Cardápio`

Ação deve passar pelo Action Registry/RBAC existente.

Não inventar rota.

---

## 5. RBAC

O Assistente nunca pode orientar/abrir ação que o perfil não pode acessar.

Se o usuário não possui permissão:

explicar de forma segura.

---

## 6. Falha segura

Se provider/backend falhar:

- não quebrar UI;
- não inventar resposta;
- apresentar fallback útil.

Mas fallback genérico não deve ocorrer em perguntas simples cobertas pelo feature catalog.

---

## 7. Respostas dinâmicas

Evitar responder tudo com frases pré-programadas genéricas.

A resposta deve combinar:

- intenção;
- feature catalog;
- rota/action real;
- estado read-only quando necessário.

---

## 8. Relação com catálogo

Depois da implementação do CRUD geral:

perguntas como:

- `como adiciono uma bebida?`
- `como altero o preço da Coca?`
- `como crio uma categoria?`

devem apontar para o fluxo real atual.

A documentação/knowledge do Assistente deve acompanhar as features existentes.

---

## 9. Real-time

Se responder fatos atuais do catálogo:

seguir `CATALOG_REALTIME_CONSISTENCY.md`.

Não usar preço hardcoded em knowledge text.

---

## 10. Segurança

Assistente interno não recebe automaticamente autoridade de mutação.

Ações protegidas devem usar CTAs/workflows/RBAC já existentes.

---

## 11. Acceptance

- `como eu vejo o preço de um produto?`
- `como altero o cardápio de amanhã?`
- `onde confirmo um Pix?`
- `como vejo as entregas de hoje?`
- `como encontro um cliente?`
- `como adiciono uma bebida?` após feature existir
- CTA correto;
- RBAC;
- fallback seguro;
- sem erro genérico em perguntas cobertas;
- estado/catalog real quando solicitado.
