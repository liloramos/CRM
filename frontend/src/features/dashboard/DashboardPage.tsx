import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { StatCard } from '../../components/ui/StatCard'
import { StatusBadge } from '../../components/ui/StatusBadge'
import { PaymentMethodBreakdown } from '../financeiro/PaymentMethodBreakdown'
import type { Conversation, DailyFinancialSummary, Order, PaymentMethodSummary, RouteKey } from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

type DashboardPageProps = {
  orders: Order[]
  conversations: Conversation[]
  financialSummary: DailyFinancialSummary
  paymentMethods: PaymentMethodSummary[]
  onNavigate: (route: RouteKey) => void
}

export function DashboardPage({ conversations, financialSummary, onNavigate, orders, paymentMethods }: DashboardPageProps) {
  const openOrders = orders.filter((order) => order.status !== 'finalizado' && order.status !== 'cancelado')
  const waitingPrint = orders.filter((order) => order.printStatus !== 'impresso').length
  const chartValues = [
    { label: 'Pedidos', value: financialSummary.ordersCount },
    { label: 'Pagos', value: financialSummary.paidOrders },
    { label: 'Pendentes', value: financialSummary.pendingOrders },
    { label: 'Conversas', value: conversations.length },
    { label: 'Comandas', value: waitingPrint },
  ]
  const maxChartValue = Math.max(...chartValues.map((item) => item.value), 1)

  return (
    <PageContainer>
      <PageHeader
        actions={
          <div className="inline-actions">
            <Button icon="orders" onClick={() => onNavigate('pedidos')} variant="primary">
              Abrir fila
            </Button>
            <Button icon="finance" onClick={() => onNavigate('financeiro')} variant="secondary">
              Financeiro
            </Button>
          </div>
        }
        description="Visao operacional para atendimento, pedidos, pagamentos e impressao."
        title="Dashboard"
      />

      <div className="stats-grid dashboard-stats-grid">
        <StatCard icon="chat" label="Conversas ativas" value={`${conversations.length}`} />
        <StatCard icon="orders" label="Pedidos em fluxo" trend={`${financialSummary.paidOrders}/${financialSummary.ordersCount} pagos`} value={`${openOrders.length}`} />
        <StatCard icon="finance" label="Faturamento confirmado" tone="success" value={formatCurrency(financialSummary.confirmedRevenue)} />
        <StatCard icon="payment" label="Pendencias" tone="warning" value={formatCurrency(financialSummary.pendingAmount)} />
        <StatCard icon="printer" label="Comandas pendentes" tone="warning" value={`${waitingPrint}`} />
      </div>

      <div className="dashboard-grid">
        <Card className="sales-card">
          <SectionTitle eyebrow={financialSummary.dateLabel} title="Resumo financeiro do dia" />
          <div className="line-chart" aria-label="Grafico simples com dados operacionais do dia">
            {chartValues.map((item) => (
              <span
                aria-label={`${item.label}: ${item.value}`}
                key={item.label}
                style={{ height: `${Math.max(18, (item.value / maxChartValue) * 100)}%` }}
                title={`${item.label}: ${item.value}`}
              />
            ))}
          </div>
          <div className="dashboard-money-grid">
            <div>
              <span>Bruto</span>
              <strong>{formatCurrency(financialSummary.grossRevenue)}</strong>
            </div>
            <div>
              <span>Pix</span>
              <strong>{formatCurrency(financialSummary.pixAmount)}</strong>
            </div>
            <div>
              <span>Credito usado</span>
              <strong>{formatCurrency(financialSummary.creditUsed)}</strong>
            </div>
            <div>
              <span>Lucro simples</span>
              <strong>{formatCurrency(financialSummary.netProfit)}</strong>
            </div>
          </div>
        </Card>

        <Card>
          <SectionTitle title="Pedidos por status" />
          <div className="status-list">
            {orders.map((order) => (
              <button className="status-list__item" key={order.id} onClick={() => onNavigate('pedidos')} type="button">
                <span>{order.code}</span>
                <StatusBadge status={order.status} type="order" />
              </button>
            ))}
          </div>
        </Card>

        <Card>
          <SectionTitle title="Pagamentos do dia" />
          <PaymentMethodBreakdown methods={paymentMethods} />
        </Card>

        <Card className="activity-card dashboard-card-wide">
          <SectionTitle title="Atividades recentes" />
          <div className="activity-list">
            {orders.flatMap((order) =>
              order.history.slice(0, 2).map((entry) => (
                <div className="activity-item" key={`${order.id}-${entry.id}`}>
                  <span className="activity-item__dot" />
                  <div>
                    <strong>{entry.title}</strong>
                    <p>{entry.description}</p>
                  </div>
                  <small>{entry.timeLabel}</small>
                </div>
              )),
            )}
          </div>
        </Card>
      </div>
    </PageContainer>
  )
}
