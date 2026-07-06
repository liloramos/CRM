# Front-end Guidelines — ChatBotCRM / Sol Restaurante

> Documento de referência para implementação do front-end do ChatBotCRM.
>
> As imagens geradas durante a concepção visual devem ser usadas apenas como inspiração estética e estrutural. Regras de negócio, nomes, preços, produtos, datas, endereços e valores oficiais devem vir dos documentos do projeto.

---

## 1. Objetivo

Este documento define o padrão visual, estrutural e técnico do front-end do ChatBotCRM, garantindo que o sistema seja:

- fácil de usar por uma equipe de restaurante;
- visualmente consistente com a identidade do Sol Restaurante;
- simples de manter e alterar manualmente;
- organizado para evolução futura como CRM SaaS;
- preparado para integração com API, IA, WhatsApp, impressão e pagamentos.

O front deve ser bonito, moderno e profissional, mas sem sacrificar clareza. A prioridade é permitir que atendentes consigam operar pedidos, conversas, pagamentos e impressão com segurança e rapidez.

---

## 2. Identidade visual

### 2.1 Direção estética

A identidade visual aprovada segue estes princípios:

- tema escuro premium;
- fundo preto/cinza grafite;
- detalhes em laranja/amarelo;
- aparência moderna, SaaS, limpa e profissional;
- bordas arredondadas;
- cards com glow ou sombra sutil;
- navegação lateral fixa;
- ícones finos;
- hierarquia visual clara;
- interface simples para uso operacional em restaurante.

### 2.2 Cores sugeridas

Centralize as cores em tokens. Não espalhe hexadecimais aleatórios pelos componentes.

```ts
export const colors = {
  background: '#08090B',
  surface: '#111318',
  surfaceSoft: '#171A20',
  border: '#272A32',
  textPrimary: '#F7F7F8',
  textSecondary: '#A5A8B1',
  textMuted: '#6F7480',
  brandPrimary: '#FFB300',
  brandSecondary: '#FF8C00',
  success: '#22C55E',
  warning: '#F59E0B',
  danger: '#EF4444',
  info: '#3B82F6',
};
```

### 2.3 Tipografia

Preferir uma fonte moderna e legível, como Inter, Geist, Nunito Sans ou similar.

Regras:

- títulos grandes e objetivos;
- textos secundários com menor contraste;
- valores financeiros com destaque;
- status e ações sempre fáceis de ler;
- evitar textos longos dentro de cards operacionais.

---

## 3. Organização recomendada de pastas

A estrutura abaixo deve ser usada como referência para manter o front compreensível e fácil de alterar.

```txt
src/
  app/
    App.tsx
    routes.tsx
    providers.tsx

  components/
    ui/
      Button.tsx
      Input.tsx
      Card.tsx
      Badge.tsx
      Modal.tsx
      Tabs.tsx
      DataTable.tsx
      EmptyState.tsx
      LoadingState.tsx
      ErrorState.tsx
      StatusBadge.tsx
      IconButton.tsx

    layout/
      AppShell.tsx
      Sidebar.tsx
      Topbar.tsx
      PageHeader.tsx
      PageContainer.tsx

  constants/
    colors.ts
    routes.ts
    menu.ts
    orderStatus.ts
    paymentStatus.ts
    deliveryStatus.ts

  features/
    dashboard/
    pedidos/
    conversas/
    clientes/
    cardapio/
    entregas/
    pagamentos/
    financeiro/
    relatorios/
    configuracoes/

  mocks/
    pedidos.mock.ts
    clientes.mock.ts
    produtos.mock.ts
    conversas.mock.ts
    pagamentos.mock.ts
    usuarios.mock.ts

  services/
    api.ts
    pedidos.service.ts
    clientes.service.ts
    conversas.service.ts
    pagamentos.service.ts

  types/
    pedido.ts
    cliente.ts
    produto.ts
    conversa.ts
    pagamento.ts
    usuario.ts
    entrega.ts

  utils/
    formatCurrency.ts
    formatDate.ts
    formatPhone.ts
    getStatusColor.ts
```

---

## 4. Princípios de implementação

### 4.1 Código simples e editável

O projeto deve ser implementado para que o desenvolvedor consiga abrir os arquivos e entender rapidamente o que alterar.

Evitar:

- componentes muito grandes;
- lógica complexa direto no JSX;
- estilos duplicados;
- valores mágicos espalhados;
- nomes genéricos como `Component1`, `Box`, `Thing`, `Data`;
- dados mockados diretamente dentro das páginas.

Preferir:

- componentes pequenos;
- nomes claros;
- constantes centralizadas;
- tipos TypeScript bem definidos;
- mocks em arquivos separados;
- componentes de layout reutilizáveis;
- comentários curtos apenas quando ajudarem a entender uma regra.

### 4.2 Separação entre visual e regra de negócio

O front pode iniciar com mocks, mas deve estar preparado para receber dados reais da API.

As telas não devem depender de textos fictícios das imagens geradas. As referências visuais servem para:

- posição dos elementos;
- hierarquia visual;
- componentes necessários;
- estilo geral;
- padrão de cards, tabelas e modais.

As regras definitivas devem vir dos documentos oficiais do projeto.

---

## 5. Componentes base obrigatórios

### 5.1 Layout

- `AppShell`: estrutura principal com sidebar e área de conteúdo.
- `Sidebar`: navegação lateral.
- `Topbar`: busca, notificações, usuário e ações globais.
- `PageHeader`: título, descrição e ações da página.
- `PageContainer`: largura, espaçamento e grid base.

### 5.2 UI base

- `Button`
- `Input`
- `Select`
- `Textarea`
- `Card`
- `Badge`
- `StatusBadge`
- `Modal`
- `Tabs`
- `DataTable`
- `EmptyState`
- `LoadingState`
- `ErrorState`
- `StatCard`
- `ActionCard`

### 5.3 Componentes de domínio

- `OrderSummaryCard`
- `OrderItemsTable`
- `CustomerSummaryCard`
- `CustomerPreferencesCard`
- `PaymentStatusCard`
- `DeliveryStatusCard`
- `PrintPreviewCard`
- `ConversationList`
- `ConversationPanel`
- `MenuProductCard`

---

## 6. Tipos principais do domínio

Os tipos abaixo devem orientar os modelos do front. Ajustar conforme o backend evoluir.

```ts
export type PedidoStatus =
  | 'novo'
  | 'aceito'
  | 'aguardando_pagamento'
  | 'pago'
  | 'em_preparo'
  | 'pronto'
  | 'saiu_para_entrega'
  | 'entregue'
  | 'cancelado'
  | 'erro_impressao';

export type FormaPagamento = 'pix' | 'dinheiro' | 'cartao' | 'credito_cliente' | 'misto';

export type TipoEntrega = 'retirada' | 'entrega' | 'consumo_local';

export interface ClienteResumo {
  id: string;
  nome: string;
  telefone?: string;
  tags?: string[];
  saldoCredito?: number;
  observacoesImportantes?: string[];
}

export interface PessoaRetirada {
  nome?: string;
  observacao?: string;
}

export interface ItemPedido {
  id: string;
  produtoId: string;
  nome: string;
  quantidade: number;
  precoUnitario: number;
  total: number;
  observacoes?: string;
  preferencias?: string[];
  adicionais?: Array<{
    nome: string;
    valor: number;
  }>;
}

export interface Pedido {
  id: string;
  codigo: string;
  cliente: ClienteResumo;
  status: PedidoStatus;
  tipoEntrega: TipoEntrega;
  pessoaRetirada?: PessoaRetirada;
  itens: ItemPedido[];
  subtotal: number;
  taxaEntrega: number;
  desconto: number;
  creditoUtilizado: number;
  total: number;
  formaPagamento?: FormaPagamento;
  observacaoGeral?: string;
  observacaoCozinha?: string;
  criadoEm: string;
  atualizadoEm: string;
}
```

---

## 7. Telas previstas

### 7.1 Operacionais

- Dashboard
- Conversas / Atendimento
- Pedidos
- Detalhes do pedido
- Novo pedido manual
- Comanda / Prévia de impressão
- Cardápio
- Entregas
- Pagamentos / Pix
- Financeiro
- Clientes
- Perfil do cliente

### 7.2 Configurações

- Configurações Geral
- Configurações Hub
- Usuários e Permissões
- IA e Automação
- Aparência e Marca
- Segurança
- WhatsApp / API Técnico

### 7.3 Análise

- Relatórios

---

## 8. Modais previstas

- Confirmar pagamento Pix
- Cancelar pedido
- Alterar status do pedido
- Editar item do pedido
- Marcar item indisponível
- Adicionar novo produto
- Adicionar usuário
- Alternar IA/manual
- Erro de impressão
- Erro de WhatsApp/API

---

## 9. Estados de interface

Toda tela de listagem deve prever:

- estado com dados;
- estado vazio;
- estado carregando;
- estado de erro;
- estado sem conexão;
- estado filtrado sem resultado.

Exemplo:

```tsx
if (isLoading) return <LoadingState title="Carregando pedidos..." />;
if (isError) return <ErrorState title="Não foi possível carregar os pedidos" />;
if (pedidos.length === 0) return <EmptyState title="Nenhum pedido encontrado" />;
```

---

## 10. Padrão visual de status

Status precisam ser fáceis de identificar por cor, texto e ícone.

Exemplo:

```ts
export const ORDER_STATUS_CONFIG = {
  novo: { label: 'Novo', color: 'info' },
  aceito: { label: 'Aceito', color: 'success' },
  aguardando_pagamento: { label: 'Aguardando pagamento', color: 'warning' },
  pago: { label: 'Pago', color: 'success' },
  em_preparo: { label: 'Em preparo', color: 'warning' },
  pronto: { label: 'Pronto', color: 'success' },
  saiu_para_entrega: { label: 'Saiu para entrega', color: 'info' },
  entregue: { label: 'Entregue', color: 'success' },
  cancelado: { label: 'Cancelado', color: 'danger' },
  erro_impressao: { label: 'Erro de impressão', color: 'danger' },
};
```

---

## 11. Regras de UX para restaurante

- Sempre mostrar a próxima ação principal de forma destacada.
- Evitar que o atendente precise procurar informação crítica.
- Usar confirmações em ações destrutivas.
- Mostrar histórico e observações do cliente perto do pedido.
- Destacar restrições alimentares e preferências recorrentes.
- Permitir edição rápida de item e observações.
- Deixar impressão da comanda como parte do fluxo operacional.
- Não esconder erro de impressão ou erro de WhatsApp/API.
- Sempre indicar se o pedido está pago, pendente, em crédito ou misto.

---

## 12. Impressão no fluxo operacional

A impressão da comanda não é apenas uma configuração técnica.

Ela faz parte do fluxo final do pedido:

1. pedido recebido;
2. pedido conferido;
3. pagamento confirmado ou marcado como pendente/crédito;
4. comanda gerada;
5. comanda impressa;
6. pedido enviado para preparo/cozinha.

A configuração de impressoras pode existir no Hub, mas o botão/etapa de imprimir deve estar visível no fluxo operacional.

---

## 13. Dados mockados

Os dados mockados devem ficar apenas em `src/mocks` e devem ser claramente fictícios.

Não usar:

- telefone real;
- CPF real;
- endereço real;
- localização real;
- chave Pix real;
- prints ou comprovantes reais;
- conversa real exportada do WhatsApp.

---

## 14. Checklist antes de finalizar uma tela

- A tela segue o tema visual aprovado?
- Os dados fictícios estão centralizados em mocks?
- Componentes foram reutilizados?
- Existe estado vazio?
- Existe estado de carregamento?
- Existe estado de erro?
- A tela é simples para atendente usar?
- O código está fácil de entender?
- Não há dados sensíveis?
- Build/lint/test passam?

---

## 15. Arquivos que o desenvolvedor deve estudar primeiro

Quando alguém novo for mexer no front, estudar nesta ordem:

1. `src/app/routes.tsx`
2. `src/components/layout/AppShell.tsx`
3. `src/constants/menu.ts`
4. `src/constants/colors.ts`
5. `src/components/ui/Button.tsx`
6. `src/components/ui/Card.tsx`
7. `src/types/pedido.ts`
8. `src/mocks/pedidos.mock.ts`
9. `src/features/pedidos/`
10. `src/features/conversas/`
