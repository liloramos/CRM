import { useState } from 'react'
import { Drawer } from '../../components/ui/Drawer'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { ErrorState, EmptyState } from '../../components/ui/States'
import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { StatCard } from '../../components/ui/StatCard'
import { StatusBadge } from '../../components/ui/StatusBadge'
import { PaymentMethodBreakdown } from '../financeiro/PaymentMethodBreakdown'
import type {
  BadgeTone,
  Conversation,
  DailyFinancialSummary,
  FinanceEntry,
  Order,
  OrderStatus,
  PaymentMethod,
  PaymentStatus,
  PaymentMethodSummary,
  RouteKey,
  SnapshotSource,
} from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

type DashboardPageProps = {
  orders: Order[]
  conversations: Conversation[]
  financeEntries: FinanceEntry[]
  financialSummary: DailyFinancialSummary
  paymentMethods: PaymentMethodSummary[]
  source: SnapshotSource
  isLoading: boolean
  error: string | null
  onNavigate: (route: RouteKey) => void
  onNewOrder: () => void
  onRetry: () => void
}

type AttentionItem = {
  id: string
  title: string
  description: string
  tone: 'warning' | 'danger' | 'info'
  actionLabel: string
  route: RouteKey
}

type DashboardDetail =
  | 'conversations'
  | 'orders'
  | 'revenue'
  | 'payment-pending'
  | 'prints'
  | 'summary'
  | 'status'
  | 'payments'
  | 'attention'
  | 'activity'

const CLOSED_ORDER_STATUSES: OrderStatus[] = ['finalizado', 'cancelado']
const PAYMENT_ATTENTION_STATUSES = ['pendente', 'parcial', 'revisao_humana']

const STATUS_GROUPS: Array<{ status: OrderStatus; label: string }> = [
  { status: 'novo', label: 'Novos / rascunhos' },
  { status: 'em_conferencia', label: 'Em conferência' },
  { status: 'aguardando_pagamento', label: 'Aguardando pagamento' },
  { status: 'comprovante_recebido', label: 'Comprovante recebido' },
  { status: 'pagamento_confirmado', label: 'Pagamento confirmado' },
  { status: 'pronto_para_imprimir', label: 'Pronto para imprimir' },
  { status: 'impresso', label: 'Comanda impressa' },
  { status: 'em_preparo', label: 'Em preparo' },
  { status: 'pronto', label: 'Pronto' },
  { status: 'saiu_para_entrega', label: 'Saiu para entrega' },
  { status: 'finalizado', label: 'Finalizado' },
  { status: 'cancelado', label: 'Cancelado' },
]

const ORDER_STATUS_TONES: Record<OrderStatus, BadgeTone> = {
  novo: 'neutral',
  em_conferencia: 'manual',
  aguardando_pagamento: 'warning',
  comprovante_recebido: 'manual',
  pagamento_confirmado: 'success',
  pronto_para_imprimir: 'warning',
  impresso: 'info',
  em_preparo: 'info',
  pronto: 'success',
  saiu_para_entrega: 'info',
  finalizado: 'success',
  cancelado: 'danger',
  manual: 'manual',
}

const PAYMENT_STATUS_LABELS: Record<PaymentStatus, string> = {
  pendente: 'Pendente',
  parcial: 'Parcial',
  pago: 'Confirmado',
  credito: 'Credito',
  revisao_humana: 'Revisao humana',
}

const PAYMENT_STATUS_TONES: Record<PaymentStatus, BadgeTone> = {
  pendente: 'warning',
  parcial: 'warning',
  pago: 'success',
  credito: 'manual',
  revisao_humana: 'manual',
}

export function DashboardPage({
  conversations,
  error,
  financeEntries,
  financialSummary,
  isLoading,
  onNavigate,
  onNewOrder,
  onRetry,
  orders,
  paymentMethods,
  source,
}: DashboardPageProps) {
  const [activeDetail, setActiveDetail] = useState<DashboardDetail | null>(null)
  const hasTrustedData = source === 'api'
  const safeOrders = hasTrustedData ? orders : []
  const safeConversations = hasTrustedData ? conversations : []
  const safeFinanceEntries = hasTrustedData ? financeEntries : []
  const safePaymentMethods = hasTrustedData ? paymentMethods.filter((method) => method.count > 0 || method.amount > 0) : []
  const operationalOrders = safeOrders.filter((order) => !CLOSED_ORDER_STATUSES.includes(order.status))
  const paymentAttentionOrders = operationalOrders.filter(
    (order) => PAYMENT_ATTENTION_STATUSES.includes(order.paymentStatus) || order.amountDue > 0,
  )
  const waitingPrintOrders = operationalOrders.filter((order) => order.printStatus !== 'impresso')
  const printErrorOrders = operationalOrders.filter((order) => order.printStatus === 'erro')
  const reviewConversations = safeConversations.filter((conversation) => conversation.mode === 'atencao')
  const hasAnyOperation =
    operationalOrders.length > 0 ||
    safeConversations.length > 0 ||
    safePaymentMethods.length > 0 ||
    financialSummary.confirmedRevenue > 0 ||
    financialSummary.pendingAmount > 0

  const attentionItems: AttentionItem[] = [
    financialSummary.pendingAmount > 0 || paymentAttentionOrders.length > 0
      ? {
          id: 'payments',
          title: 'Pagamentos aguardando conferência',
          description: `${paymentAttentionOrders.length} pedido(s) com valor ou status financeiro pendente.`,
          tone: 'warning',
          actionLabel: 'Ver pagamentos',
          route: 'pagamentos',
        }
      : null,
    waitingPrintOrders.length > 0
      ? {
          id: 'print',
          title: 'Comandas aguardando impressão',
          description: `${waitingPrintOrders.length} pedido(s) em fluxo ainda sem impressão concluída.`,
          tone: printErrorOrders.length > 0 ? 'danger' : 'warning',
          actionLabel: 'Abrir fila',
          route: 'pedidos',
        }
      : null,
    reviewConversations.length > 0
      ? {
          id: 'conversations',
          title: 'Conversas pedindo atenção humana',
          description: `${reviewConversations.length} conversa(s) com fallback ou revisão manual.`,
          tone: 'info',
          actionLabel: 'Abrir conversas',
          route: 'conversas',
        }
      : null,
  ].filter(Boolean) as AttentionItem[]

  const allStatusGroups = STATUS_GROUPS.map((group) => ({
    ...group,
    count: safeOrders.filter((order) => order.status === group.status).length,
    tone: ORDER_STATUS_TONES[group.status],
  })).filter((group) => group.count > 0)
  const paymentSituationGroups = (Object.keys(PAYMENT_STATUS_LABELS) as PaymentStatus[])
    .map((status) => ({
      id: status,
      label: PAYMENT_STATUS_LABELS[status],
      count: safeOrders.filter((order) => order.paymentStatus === status).length,
      amount: safeOrders
        .filter((order) => order.paymentStatus === status)
        .reduce((sum, order) => sum + (status === 'pago' ? order.paid : order.amountDue), 0),
      tone: PAYMENT_STATUS_TONES[status],
    }))
    .filter((group) => group.count > 0 || group.amount > 0)
  const confirmedPaymentMethods = buildConfirmedPaymentMethods(safeFinanceEntries)

  const recentActivity = safeOrders
    .flatMap((order) =>
      order.history.slice(0, 2).map((entry) => ({
        ...entry,
        orderCode: order.code,
        key: `${order.id}-${entry.id}`,
      })),
    )
    .slice(0, 6)

  if (isLoading) {
    return (
      <PageContainer>
        <DashboardHeader onNavigate={onNavigate} onNewOrder={onNewOrder} />
        <DashboardSkeleton />
      </PageContainer>
    )
  }

  if (!hasTrustedData) {
    return (
      <PageContainer>
        <DashboardHeader onNavigate={onNavigate} onNewOrder={onNewOrder} />
        <Card className="dashboard-state-card">
          <ErrorState
            actionLabel="Tentar novamente"
            description="Verifique sua conexão e tente novamente."
            onAction={onRetry}
            title="Não foi possível atualizar o painel"
          />
        </Card>
      </PageContainer>
    )
  }

  return (
    <PageContainer density="wide">
      <DashboardHeader onNavigate={onNavigate} onNewOrder={onNewOrder} />

      {error ? (
        <div className="dashboard-alert" role="status">
          <div>
            <strong>Atualização não concluída</strong>
            <p>{error}</p>
          </div>
          <Button icon="arrow" onClick={onRetry} size="sm" variant="secondary">
            Tentar novamente
          </Button>
        </div>
      ) : null}

      {!hasAnyOperation ? (
        <Card className="dashboard-state-card">
          <EmptyState
            actionLabel="Criar novo pedido"
            description="A operação ainda não registrou pedidos, conversas ou pagamentos no período atual."
            onAction={onNewOrder}
            title="Sem movimentações operacionais"
          />
        </Card>
      ) : null}

      <div className="stats-grid dashboard-stats-grid">
        <StatCard
          detailLabel="Ver conversas"
          icon="chat"
          label="Conversas recentes"
          onClick={safeConversations.length > 0 ? () => setActiveDetail('conversations') : undefined}
          showActionIndicator={false}
          showDecoration={false}
          trend={safeConversations.length > 0 ? 'Atendimentos mais recentes' : 'Nenhuma conversa recente'}
          value={`${safeConversations.length}`}
        />
        <StatCard
          detailLabel="Ver pedidos"
          icon="orders"
          label="Pedidos em fluxo"
          onClick={operationalOrders.length > 0 ? () => setActiveDetail('orders') : undefined}
          showDecoration={false}
          trend={operationalOrders.length > 0 ? 'Exclui finalizados e cancelados' : 'Nenhum pedido em andamento'}
          value={`${operationalOrders.length}`}
        />
        <StatCard
          detailLabel="Ver resumo"
          icon="finance"
          label="Faturamento confirmado"
          onClick={financialSummary.confirmedRevenue > 0 || safePaymentMethods.length > 0 ? () => setActiveDetail('revenue') : undefined}
          showDecoration={false}
          tone="success"
          trend="Somente pagamentos confirmados"
          value={formatCurrency(financialSummary.confirmedRevenue)}
        />
        <StatCard
          detailLabel="Ver pendências"
          icon="payment"
          label="Pagamentos pendentes"
          onClick={paymentAttentionOrders.length > 0 ? () => setActiveDetail('payment-pending') : undefined}
          showDecoration={false}
          tone={financialSummary.pendingAmount > 0 ? 'warning' : 'info'}
          trend={financialSummary.pendingAmount > 0 ? `${paymentAttentionOrders.length} pedido(s) para conferir` : 'Nenhum pagamento pendente'}
          value={formatCurrency(financialSummary.pendingAmount)}
        />
        <StatCard
          detailLabel="Ver comandas"
          icon="printer"
          label="Comandas pendentes"
          onClick={waitingPrintOrders.length > 0 ? () => setActiveDetail('prints') : undefined}
          showDecoration={false}
          tone={waitingPrintOrders.length > 0 ? 'warning' : 'info'}
          trend={waitingPrintOrders.length > 0 ? 'Impressão necessária antes do preparo' : 'Nenhuma comanda aguardando impressão'}
          value={`${waitingPrintOrders.length}`}
        />
      </div>

      <div className="dashboard-ops-grid">
        <Card className="dashboard-summary-card">
          <SectionTitle
            action={
              <Button icon="arrow" onClick={() => setActiveDetail('summary')} size="sm" variant="secondary">
                Detalhar
              </Button>
            }
            eyebrow={financialSummary.dateLabel}
            title="Resumo operacional"
          />
          <div className="dashboard-summary-list">
            <DashboardSummaryItem label="Pedidos registrados" value={`${safeOrders.length}`} />
            <DashboardSummaryItem label="Pedidos pagos" value={`${financialSummary.paidOrders}`} />
            <DashboardSummaryItem label="Valor pendente" value={formatCurrency(financialSummary.pendingAmount)} />
            <DashboardSummaryItem label="Pix confirmado" value={formatCurrency(financialSummary.pixAmount)} />
            <DashboardSummaryItem label="Crédito usado" value={formatCurrency(financialSummary.creditUsed)} />
            <DashboardSummaryItem label="Ticket médio" value={formatCurrency(financialSummary.averageTicket)} />
          </div>
        </Card>

        <Card className="dashboard-attention-card">
          <SectionTitle
            action={attentionItems.length > 0 ? (
              <Button icon="arrow" onClick={() => setActiveDetail('attention')} size="sm" variant="secondary">
                Detalhar
              </Button>
            ) : null}
            title="Atenção agora"
          />
          {attentionItems.length > 0 ? (
            <div className="attention-list">
              {attentionItems.map((item) => (
                <button className={`attention-item attention-item--${item.tone}`} key={item.id} onClick={() => onNavigate(item.route)} type="button">
                  <div>
                    <strong>{item.title}</strong>
                    <p>{item.description}</p>
                  </div>
                  <Badge tone={item.tone} size="sm">
                    {item.actionLabel}
                  </Badge>
                </button>
              ))}
            </div>
          ) : (
            <DashboardEmptyMessage
              description="Nenhum pagamento, comanda ou conversa exige ação imediata no momento."
              title="Operação sem alertas"
            />
          )}
        </Card>

        <Card className="dashboard-status-card">
          <SectionTitle
            action={allStatusGroups.length > 0 ? (
              <Button icon="arrow" onClick={() => setActiveDetail('status')} size="sm" variant="secondary">
                Analisar
              </Button>
            ) : null}
            title="Pedidos por status"
          />
          {allStatusGroups.length > 0 ? (
            <div className="status-list">
              {allStatusGroups.map((group) => (
                <button className="status-list__item dashboard-status-row" key={group.status} onClick={() => onNavigate('pedidos')} type="button">
                  <span>{group.label}</span>
                  <div>
                    <strong>{group.count}</strong>
                    <StatusBadge status={group.status} type="order" />
                  </div>
                </button>
              ))}
            </div>
          ) : (
            <DashboardEmptyMessage description="Nenhum pedido em andamento." title="Fila vazia" />
          )}
        </Card>

        <Card className="dashboard-payments-card">
          <SectionTitle
            action={paymentSituationGroups.length > 0 || confirmedPaymentMethods.length > 0 ? (
              <Button icon="arrow" onClick={() => setActiveDetail('payments')} size="sm" variant="secondary">
                Detalhar
              </Button>
            ) : null}
            title="Pagamentos"
          />
          {safePaymentMethods.length > 0 ? (
            <PaymentMethodBreakdown methods={safePaymentMethods} />
          ) : (
            <DashboardEmptyMessage description="Nenhum pagamento registrado no período atual." title="Sem pagamentos" />
          )}
        </Card>

        <Card className="dashboard-card-wide">
          <SectionTitle
            action={recentActivity.length > 0 ? (
              <Button icon="arrow" onClick={() => setActiveDetail('activity')} size="sm" variant="secondary">
                Ver timeline
              </Button>
            ) : null}
            title="Atividades recentes"
          />
          {recentActivity.length > 0 ? (
            <div className="activity-list dashboard-activity-list">
              {recentActivity.map((entry) => (
                <div className="activity-item" key={entry.key}>
                  <span className="activity-item__dot" />
                  <div>
                    <strong>{entry.title}</strong>
                    <p>
                      {entry.orderCode} · {entry.description}
                    </p>
                  </div>
                  <small>{entry.timeLabel}</small>
                </div>
              ))}
            </div>
          ) : (
            <DashboardEmptyMessage description="Ainda não há histórico de status para exibir." title="Sem atividades recentes" />
          )}
        </Card>
      </div>

      <DashboardDetailDrawer
        activeDetail={activeDetail}
        allStatusGroups={allStatusGroups}
        attentionItems={attentionItems}
        conversations={safeConversations}
        confirmedPaymentMethods={confirmedPaymentMethods}
        financialSummary={financialSummary}
        financeEntries={safeFinanceEntries}
        onClose={() => setActiveDetail(null)}
        onNavigate={onNavigate}
        orders={operationalOrders}
        paymentAttentionOrders={paymentAttentionOrders}
        paymentSituationGroups={paymentSituationGroups}
        recentActivity={recentActivity}
        safeOrders={safeOrders}
        waitingPrintOrders={waitingPrintOrders}
      />
    </PageContainer>
  )
}

function DashboardHeader({ onNavigate, onNewOrder }: Pick<DashboardPageProps, 'onNavigate' | 'onNewOrder'>) {
  return (
    <PageHeader
      actions={
        <div className="inline-actions">
          <Button icon="plus" onClick={onNewOrder} variant="primary">
            Novo pedido
          </Button>
          <Button icon="orders" onClick={() => onNavigate('pedidos')} variant="secondary">
            Abrir fila
          </Button>
          <Button icon="finance" onClick={() => onNavigate('financeiro')} variant="secondary">
            Financeiro
          </Button>
        </div>
      }
      description="Acompanhe pedidos, pagamentos e atendimento em tempo real."
      eyebrow="Painel operacional"
      title="Dashboard"
    />
  )
}

type DashboardDetailDrawerProps = {
  activeDetail: DashboardDetail | null
  allStatusGroups: Array<{ status: OrderStatus; label: string; count: number; tone: BadgeTone }>
  attentionItems: AttentionItem[]
  conversations: Conversation[]
  confirmedPaymentMethods: PaymentMethodSummary[]
  financialSummary: DailyFinancialSummary
  financeEntries: FinanceEntry[]
  orders: Order[]
  paymentAttentionOrders: Order[]
  paymentSituationGroups: Array<{ id: PaymentStatus; label: string; count: number; amount: number; tone: BadgeTone }>
  recentActivity: Array<{
    id: string
    title: string
    description: string
    timeLabel: string
    orderCode: string
    key: string
  }>
  safeOrders: Order[]
  waitingPrintOrders: Order[]
  onClose: () => void
  onNavigate: (route: RouteKey) => void
}

function DashboardDetailDrawer({
  activeDetail,
  allStatusGroups,
  attentionItems,
  conversations,
  confirmedPaymentMethods,
  financialSummary,
  financeEntries,
  onClose,
  onNavigate,
  orders,
  paymentAttentionOrders,
  paymentSituationGroups,
  recentActivity,
  safeOrders,
  waitingPrintOrders,
}: DashboardDetailDrawerProps) {
  const [selectedStatus, setSelectedStatus] = useState<OrderStatus | null>(null)
  const [selectedPaymentStatus, setSelectedPaymentStatus] = useState<PaymentStatus | null>(null)
  const detailConfig = activeDetail ? dashboardDetailConfig(activeDetail) : null
  const confirmedEntries = financeEntries.filter((entry) => entry.status === 'pago' && entry.receivedAmount > 0)
  const statusOrders = selectedStatus ? safeOrders.filter((order) => order.status === selectedStatus) : safeOrders
  const paymentOrders = selectedPaymentStatus
    ? safeOrders.filter((order) => order.paymentStatus === selectedPaymentStatus)
    : safeOrders.filter((order) => paymentSituationGroups.some((group) => group.id === order.paymentStatus))
  const drawerFooter = activeDetail ? <DashboardDrawerFooter activeDetail={activeDetail} onClose={onClose} onNavigate={onNavigate} /> : null

  return (
    <Drawer
      description={detailConfig?.description ?? ''}
      footer={drawerFooter}
      onClose={onClose}
      open={activeDetail !== null}
      title={detailConfig?.title ?? 'Detalhes operacionais'}
    >
      {activeDetail === 'conversations' ? (
        <DetailList
          emptyDescription="As conversas aparecerão aqui conforme novos atendimentos forem iniciados."
          emptyTitle="Nenhuma conversa recente"
          items={conversations.slice(0, 12).map((conversation) => ({
            id: conversation.id,
            title: conversation.customer.name,
            meta: formatConversationStatus(conversation.statusLabel),
            description: conversation.lastMessage,
            badge: getConversationModeLabel(conversation.mode),
            badgeTone: getConversationModeTone(conversation.mode),
          }))}
        />
      ) : null}

      {activeDetail === 'summary' ? (
        <div className="drawer-summary">
          <div className="drawer-metric-grid">
            <DashboardSummaryItem label="Pedidos registrados" value={`${safeOrders.length}`} />
            <DashboardSummaryItem label="Pedidos pagos" value={`${financialSummary.paidOrders}`} />
            <DashboardSummaryItem label="Valor pendente" value={formatCurrency(financialSummary.pendingAmount)} />
            <DashboardSummaryItem label="Faturamento confirmado" value={formatCurrency(financialSummary.confirmedRevenue)} />
            <DashboardSummaryItem label="Pix confirmado" value={formatCurrency(financialSummary.pixAmount)} />
            <DashboardSummaryItem label="Ticket médio" value={formatCurrency(financialSummary.averageTicket)} />
          </div>
          {allStatusGroups.length > 0 ? (
            <DonutChart
              items={allStatusGroups.map((group) => ({
                id: group.status,
                label: group.label,
                value: group.count,
                tone: group.tone,
              }))}
              title="Distribuição dos pedidos"
              valueFormatter={(value) => `${value} pedido(s)`}
            />
          ) : (
            <DashboardEmptyMessage
              description="Os pedidos aparecerão aqui assim que forem criados."
              title="Ainda não há pedidos suficientes para gerar esta análise."
            />
          )}
          {confirmedPaymentMethods.length > 0 ? (
            <PaymentMethodBreakdown methods={confirmedPaymentMethods} />
          ) : (
            <DashboardEmptyMessage description="Nenhum pagamento confirmado no período atual." title="Sem detalhamento financeiro" />
          )}
        </div>
      ) : null}

      {activeDetail === 'orders' ? (
        <DetailList
          emptyDescription="Nenhum pedido em andamento para detalhar."
          items={orders.slice(0, 12).map((order) => ({
            id: order.id,
            title: `${order.code} · ${order.customer.name}`,
            meta: `${order.createdLabel} · ${formatCurrency(order.total)}`,
            description: `Status: ${order.status}. Pagamento: ${order.paymentStatus}.`,
            badge: order.fulfillmentType,
            badgeTone: ORDER_STATUS_TONES[order.status],
          }))}
        />
      ) : null}

      {activeDetail === 'status' ? (
        <div className="drawer-summary">
          {allStatusGroups.length > 0 ? (
            <>
              <DonutChart
                items={allStatusGroups.map((group) => ({
                  id: group.status,
                  label: group.label,
                  value: group.count,
                  tone: group.tone,
                }))}
                onSelect={(status) => setSelectedStatus(status as OrderStatus)}
                selectedId={selectedStatus}
                title="Pedidos por status"
                valueFormatter={(value) => `${value} pedido(s)`}
              />
              {selectedStatus ? (
                <Button icon="close" onClick={() => setSelectedStatus(null)} size="sm" variant="secondary">
                  Limpar filtro
                </Button>
              ) : null}
              <DetailList
                emptyDescription="Nenhum pedido encontrado para o status selecionado."
                items={statusOrders.slice(0, 12).map((order) => ({
                  id: order.id,
                  title: `${order.code} · ${order.customer.name}`,
                  meta: `${order.createdLabel} · ${formatCurrency(order.total)}`,
                  description: `Status: ${order.status}. Pagamento: ${order.paymentStatus}.`,
                  badge: order.status,
                  badgeTone: ORDER_STATUS_TONES[order.status],
                }))}
              />
            </>
          ) : (
            <DashboardEmptyMessage
              description="Os pedidos aparecerão na análise quando houver movimentação real."
              title="Ainda não há pedidos suficientes para gerar esta análise."
            />
          )}
        </div>
      ) : null}

      {activeDetail === 'revenue' ? (
        <div className="drawer-summary">
          <div className="drawer-metric-grid">
            <DashboardSummaryItem label="Faturamento confirmado" value={formatCurrency(financialSummary.confirmedRevenue)} />
            <DashboardSummaryItem label="Pedidos pagos" value={`${financialSummary.paidOrders}`} />
            <DashboardSummaryItem label="Pix confirmado" value={formatCurrency(financialSummary.pixAmount)} />
            <DashboardSummaryItem label="Ticket médio" value={formatCurrency(financialSummary.averageTicket)} />
          </div>
          {confirmedPaymentMethods.length > 0 ? (
            <PaymentMethodBreakdown methods={confirmedPaymentMethods} />
          ) : (
            <DashboardEmptyMessage description="Nenhum método de pagamento confirmado no período." title="Sem detalhamento financeiro" />
          )}
          <DetailList
            emptyDescription="Nenhum pagamento confirmado para listar."
            items={confirmedEntries.slice(0, 10).map((entry) => ({
              id: entry.id,
              title: `${entry.orderCode} · ${entry.label}`,
              meta: `${entry.createdLabel} · ${formatCurrency(entry.receivedAmount)}`,
              description: entry.description,
              badge: formatPaymentMethod(entry.paymentMethod),
              badgeTone: PAYMENT_METHOD_TONES[entry.paymentMethod],
            }))}
          />
        </div>
      ) : null}

      {activeDetail === 'payment-pending' ? (
        <DetailList
          emptyDescription="Nenhum pagamento pendente para detalhar."
          items={paymentAttentionOrders.slice(0, 12).map((order) => ({
            id: order.id,
            title: `${order.code} · ${order.customer.name}`,
            meta: formatCurrency(order.amountDue),
            description: `Status financeiro: ${order.paymentStatus}. Conferência humana preservada.`,
            badge: order.paymentStatus,
            badgeTone: PAYMENT_STATUS_TONES[order.paymentStatus],
          }))}
        />
      ) : null}

      {activeDetail === 'payments' ? (
        <div className="drawer-summary">
          {paymentSituationGroups.length > 0 ? (
            <>
              <DonutChart
                items={paymentSituationGroups.map((group) => ({
                  id: group.id,
                  label: group.label,
                  value: group.count,
                  tone: group.tone,
                }))}
                onSelect={(status) => setSelectedPaymentStatus(status as PaymentStatus)}
                selectedId={selectedPaymentStatus}
                title="Por situação"
                valueFormatter={(value) => `${value} pedido(s)`}
              />
              {selectedPaymentStatus ? (
                <Button icon="close" onClick={() => setSelectedPaymentStatus(null)} size="sm" variant="secondary">
                  Limpar filtro
                </Button>
              ) : null}
              <DetailList
                emptyDescription="Nenhum pagamento encontrado para a situação selecionada."
                items={paymentOrders.slice(0, 12).map((order) => ({
                  id: order.id,
                  title: `${order.code} · ${order.customer.name}`,
                  meta: `${formatCurrency(order.paid)} pago · ${formatCurrency(order.amountDue)} pendente`,
                  description: `Status financeiro: ${PAYMENT_STATUS_LABELS[order.paymentStatus]}.`,
                  badge: PAYMENT_STATUS_LABELS[order.paymentStatus],
                  badgeTone: PAYMENT_STATUS_TONES[order.paymentStatus],
                }))}
              />
            </>
          ) : (
            <DashboardEmptyMessage description="Nenhum pagamento registrado no período atual." title="Sem pagamentos" />
          )}

          {confirmedPaymentMethods.length > 0 ? (
            <DonutChart
              items={confirmedPaymentMethods.map((method) => ({
                id: method.method,
                label: method.label,
                value: method.amount,
                tone: method.tone,
              }))}
              title="Por forma confirmada"
              valueFormatter={formatCurrency}
            />
          ) : (
            <DashboardEmptyMessage description="Nenhuma forma de pagamento confirmada no período." title="Sem formas confirmadas" />
          )}
        </div>
      ) : null}

      {activeDetail === 'attention' ? (
        <DetailList
          emptyDescription="Nenhuma ação exige atenção neste momento."
          items={attentionItems.map((item) => ({
            id: item.id,
            title: item.title,
            meta: item.actionLabel,
            description: item.description,
            badge: item.tone === 'danger' ? 'Urgente' : item.tone === 'warning' ? 'Aguardando' : 'Acompanhar',
            badgeTone: item.tone,
          }))}
        />
      ) : null}

      {activeDetail === 'activity' ? (
        recentActivity.length > 0 ? (
          <div className="drawer-timeline">
            {recentActivity.map((entry) => (
              <article className="drawer-timeline__item" key={entry.key}>
                <span className="drawer-timeline__marker" />
                <div>
                  <strong>{entry.title}</strong>
                  <p>
                    {entry.orderCode} · {entry.description}
                  </p>
                  <small>{entry.timeLabel}</small>
                </div>
              </article>
            ))}
          </div>
        ) : (
          <DashboardEmptyMessage description="As movimentações dos pedidos aparecerão aqui." title="Sem atividades recentes" />
        )
      ) : null}

      {activeDetail === 'prints' ? (
        <DetailList
          emptyDescription="Nenhuma comanda pendente para detalhar."
          items={waitingPrintOrders.slice(0, 12).map((order) => ({
            id: order.id,
            title: `${order.code} · ${order.customer.name}`,
            meta: order.createdLabel,
            description: `Status de impressão: ${order.printStatus}.`,
            badge: order.printStatus,
            badgeTone: order.printStatus === 'erro' ? 'danger' : 'warning',
          }))}
        />
      ) : null}
    </Drawer>
  )
}

function dashboardDetailConfig(detail: DashboardDetail): { title: string; description: string } {
  switch (detail) {
    case 'conversations':
      return {
        title: 'Conversas recentes',
        description: 'Atendimentos mais recentes disponíveis para a operação.',
      }
    case 'summary':
      return {
        title: 'Resumo operacional',
        description: 'Visão consolidada dos pedidos, pagamentos e comandas do período.',
      }
    case 'orders':
      return {
        title: 'Pedidos em fluxo',
        description: 'Pedidos ainda não finalizados nem cancelados.',
      }
    case 'status':
      return {
        title: 'Pedidos por status',
        description: 'Distribuição real dos pedidos carregados no painel.',
      }
    case 'revenue':
      return {
        title: 'Faturamento confirmado',
        description: 'Pagamentos confirmados e métodos utilizados no período.',
      }
    case 'payments':
      return {
        title: 'Pagamentos',
        description: 'Situação financeira e formas de pagamento confirmadas.',
      }
    case 'payment-pending':
      return {
        title: 'Pagamentos pendentes',
        description: 'Pedidos que ainda exigem conferência financeira.',
      }
    case 'attention':
      return {
        title: 'Atenção agora',
        description: 'Itens que pedem acompanhamento operacional imediato.',
      }
    case 'activity':
      return {
        title: 'Atividades recentes',
        description: 'Linha do tempo das últimas mudanças registradas nos pedidos.',
      }
    case 'prints':
      return {
        title: 'Comandas pendentes',
        description: 'Pedidos que ainda não concluíram o fluxo de impressão.',
      }
  }
}

type DetailListItem = {
  id: string
  title: string
  meta: string
  description: string
  badge: string
  badgeTone?: BadgeTone
}

function DetailList({
  emptyDescription,
  emptyTitle = 'Nada para detalhar agora',
  items,
}: {
  emptyDescription: string
  emptyTitle?: string
  items: DetailListItem[]
}) {
  if (items.length === 0) {
    return <DashboardEmptyMessage description={emptyDescription} title={emptyTitle} />
  }

  return (
    <div className="drawer-detail-list">
      {items.map((item) => (
        <article className="drawer-detail-item" key={item.id}>
          <div>
            <strong>{item.title}</strong>
            <span>{item.meta}</span>
            <p>{item.description}</p>
          </div>
          <Badge tone={item.badgeTone ?? 'neutral'} size="sm">
            {item.badge}
          </Badge>
        </article>
      ))}
    </div>
  )
}

function DashboardDrawerFooter({
  activeDetail,
  onClose,
  onNavigate,
}: {
  activeDetail: DashboardDetail
  onClose: () => void
  onNavigate: (route: RouteKey) => void
}) {
  function navigate(route: RouteKey) {
    onClose()
    onNavigate(route)
  }

  if (activeDetail === 'activity') {
    return null
  }

  if (activeDetail === 'conversations') {
    return (
      <Button icon="arrow" onClick={() => navigate('conversas')} variant="primary">
        Abrir conversas
      </Button>
    )
  }

  if (activeDetail === 'orders' || activeDetail === 'status' || activeDetail === 'prints') {
    return (
      <Button icon="arrow" onClick={() => navigate('pedidos')} variant="primary">
        Abrir pedidos
      </Button>
    )
  }

  if (activeDetail === 'attention') {
    return (
      <div className="drawer-actions">
        <Button icon="orders" onClick={() => navigate('pedidos')} variant="secondary">
          Abrir pedidos
        </Button>
        <Button icon="finance" onClick={() => navigate('pagamentos')} variant="primary">
          Abrir pagamentos
        </Button>
      </div>
    )
  }

  return (
    <div className="drawer-actions">
      <Button icon="orders" onClick={() => navigate('pedidos')} variant="secondary">
        Abrir pedidos
      </Button>
      <Button icon="finance" onClick={() => navigate('financeiro')} variant="primary">
        Abrir financeiro
      </Button>
    </div>
  )
}

type DonutChartItem = {
  id: string
  label: string
  value: number
  tone: BadgeTone
}

function DonutChart({
  items,
  onSelect,
  selectedId,
  title,
  valueFormatter = (value) => `${value}`,
}: {
  items: DonutChartItem[]
  title: string
  selectedId?: string | null
  valueFormatter?: (value: number) => string
  onSelect?: (id: string) => void
}) {
  const chartItems = items.filter((item) => item.value > 0)
  const total = chartItems.reduce((sum, item) => sum + item.value, 0)
  const radius = 44
  const circumference = 2 * Math.PI * radius

  if (total <= 0) {
    return (
      <DashboardEmptyMessage
        description="Os dados aparecerão quando houver movimentação real no período."
        title="Ainda não há dados suficientes para gerar esta análise."
      />
    )
  }

  const chartSegments = chartItems.map((item, index) => {
    const length = (item.value / total) * circumference
    const previousLength = chartItems
      .slice(0, index)
      .reduce((sum, previousItem) => sum + (previousItem.value / total) * circumference, 0)

    return {
      item,
      length,
      offset: -previousLength,
    }
  })

  return (
    <section className="dashboard-chart" aria-label={title}>
      <div className="dashboard-chart__header">
        <strong>{title}</strong>
        <span>{valueFormatter(total)} no total</span>
      </div>
      <div className="dashboard-chart__body">
        <svg className="dashboard-donut" viewBox="0 0 120 120" role="img" aria-label={`${title}: ${valueFormatter(total)} no total`}>
          <circle cx="60" cy="60" fill="none" r={radius} stroke="rgba(255, 255, 255, 0.08)" strokeWidth="14" />
          {chartSegments.map(({ item, length, offset }) => {
            return (
              <circle
                className="dashboard-donut__segment"
                cx="60"
                cy="60"
                fill="none"
                key={item.id}
                r={radius}
                stroke={toneColor(item.tone)}
                strokeDasharray={`${length} ${circumference - length}`}
                strokeDashoffset={offset}
                strokeLinecap="round"
                strokeWidth="14"
              >
                <title>{`${item.label}: ${valueFormatter(item.value)}`}</title>
              </circle>
            )
          })}
          <text className="dashboard-donut__total" dominantBaseline="middle" textAnchor="middle" x="60" y="56">
            {valueFormatter(total)}
          </text>
          <text className="dashboard-donut__caption" dominantBaseline="middle" textAnchor="middle" x="60" y="73">
            total
          </text>
        </svg>
        <div className="dashboard-chart__legend">
          {chartItems.map((item) => {
            const percent = Math.round((item.value / total) * 100)
            const isActive = selectedId === item.id
            const legendContent = (
              <>
                <span className="dashboard-chart__dot" style={{ backgroundColor: toneColor(item.tone) }} />
                <span>
                  <strong>{item.label}</strong>
                  <small>
                    {valueFormatter(item.value)} · {percent}%
                  </small>
                </span>
              </>
            )

            return onSelect ? (
              <button
                className={`dashboard-chart__legend-row ${isActive ? 'is-active' : ''}`}
                key={item.id}
                onClick={() => onSelect(item.id)}
                type="button"
              >
                {legendContent}
              </button>
            ) : (
              <div className="dashboard-chart__legend-row" key={item.id}>
                {legendContent}
              </div>
            )
          })}
        </div>
      </div>
    </section>
  )
}

const PAYMENT_METHOD_LABELS: Record<PaymentMethod, string> = {
  pix: 'Pix',
  dinheiro: 'Dinheiro',
  cartao: 'Cartão',
  credito_cliente: 'Crédito interno',
  misto: 'Misto',
  a_confirmar: 'A confirmar',
}

const PAYMENT_METHOD_TONES: Record<PaymentMethod, BadgeTone> = {
  pix: 'brand',
  dinheiro: 'success',
  cartao: 'info',
  credito_cliente: 'manual',
  misto: 'warning',
  a_confirmar: 'neutral',
}

function buildConfirmedPaymentMethods(entries: FinanceEntry[]): PaymentMethodSummary[] {
  const confirmedEntries = entries.filter((entry) => entry.status === 'pago' && entry.receivedAmount > 0)
  const total = confirmedEntries.reduce((sum, entry) => sum + entry.receivedAmount, 0)
  const groups = confirmedEntries.reduce<Map<PaymentMethod, { amount: number; count: number }>>((map, entry) => {
    const current = map.get(entry.paymentMethod) ?? { amount: 0, count: 0 }
    current.amount += entry.receivedAmount
    current.count += 1
    map.set(entry.paymentMethod, current)
    return map
  }, new Map())

  return Array.from(groups.entries()).map(([method, data]) => ({
    method,
    label: formatPaymentMethod(method),
    amount: data.amount,
    count: data.count,
    percentage: total > 0 ? Math.round((data.amount / total) * 100) : 0,
    tone: PAYMENT_METHOD_TONES[method],
  }))
}

function formatPaymentMethod(method: PaymentMethod): string {
  return PAYMENT_METHOD_LABELS[method]
}

function getConversationModeLabel(mode: Conversation['mode']): string {
  if (mode === 'manual') {
    return 'Manual'
  }

  if (mode === 'atencao') {
    return 'Aguardando intervenção'
  }

  return 'IA assistida'
}

function getConversationModeTone(mode: Conversation['mode']): BadgeTone {
  if (mode === 'manual') {
    return 'manual'
  }

  if (mode === 'atencao') {
    return 'warning'
  }

  return 'info'
}

function formatConversationStatus(status: string): string {
  const labels: Record<string, string> = {
    active: 'Ativa',
    closed: 'Encerrada',
    pending: 'Pendente',
    paused: 'Pausada',
    manual_takeover: 'Atendimento manual',
    fallback_required: 'Aguardando intervenção',
    open: 'Aberta',
  }

  return labels[status] ?? status
}

function toneColor(tone: BadgeTone): string {
  const colors: Record<BadgeTone, string> = {
    brand: 'var(--dashboard-tone-brand)',
    success: 'var(--dashboard-tone-success)',
    warning: 'var(--dashboard-tone-warning)',
    danger: 'var(--dashboard-tone-danger)',
    info: 'var(--dashboard-tone-info)',
    manual: 'var(--dashboard-tone-manual)',
    neutral: 'var(--dashboard-tone-neutral)',
  }

  return colors[tone]
}

function DashboardSummaryItem({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <span>{label}</span>
      <strong>{value}</strong>
    </div>
  )
}

function DashboardEmptyMessage({ description, title }: { description: string; title: string }) {
  return (
    <div className="dashboard-empty-message">
      <strong>{title}</strong>
      <p>{description}</p>
    </div>
  )
}

function DashboardSkeleton() {
  return (
    <div className="dashboard-skeleton" aria-label="Carregando dashboard operacional">
      <div className="stats-grid dashboard-stats-grid">
        {Array.from({ length: 5 }).map((_, index) => (
          <div className="dashboard-skeleton-card" key={index}>
            <span />
            <strong />
            <p />
          </div>
        ))}
      </div>
      <div className="dashboard-ops-grid">
        {Array.from({ length: 5 }).map((_, index) => (
          <div className={index === 4 ? 'dashboard-skeleton-panel dashboard-card-wide' : 'dashboard-skeleton-panel'} key={index}>
            <span />
            <p />
            <p />
            <p />
          </div>
        ))}
      </div>
    </div>
  )
}
