# Auditoria UX/UI - V1

## O que está aprovado visualmente

- Login com formulário à esquerda e painel de valor à direita.
- Cadastro com perfis de acesso.
- Dashboard operacional.
- Conversas com chat central e pedido atual.
- Pedidos com tabela e painel lateral.
- Cardápio com categorias, produtos, disponibilidade e preview.
- Entregas com status e detalhe de rota.
- Financeiro com KPIs, transações e resumo.
- Perfil do usuário.
- Modal de confirmação Pix.

## Riscos de UX

- Configurações não devem concentrar tudo em uma tela só.
- Dados técnicos devem ficar em seções administrativas.
- Atendente precisa ver apenas o que usa no almoço corrido.
- Operação deve priorizar velocidade, clareza e baixa chance de erro.

## Decisões de navegação

Sidebar principal recomendada:

```txt
Dashboard
Conversas
Pedidos
Clientes
Cardápio
Entregas
Financeiro
Relatórios
Configurações
```

Configurações deve ser hub com subtelas:

```txt
Geral
Usuários e Permissões
Atendimento
Pagamentos / Pix
Entrega
Impressão
WhatsApp / API
IA e Automação
Aparência e Marca
Segurança
```
