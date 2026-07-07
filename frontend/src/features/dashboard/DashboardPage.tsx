import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { StatCard } from '../../components/ui/StatCard'
import { StatusBadge } from '../../components/ui/StatusBadge'
import type { Conversation, Order, RouteKey } from '../../types/crm'
import { formatCurrency, formatPercent } from '../../utils/formatters'

type DashboardPageProps = {
  orders: Order[]
  conversations: Conversation[]
  onNavigate: (route: RouteKey) => void
}

export function DashboardPage({ conversations, onNavigate, orders }: DashboardPageProps) {
  const openOrders = orders.filter((order) => order.status !== 'finalizado' && order.status !== 'cancelado')
  const total = orders.reduce((sum, order) => sum + order.total, 0)
  const waitingPrint = orders.filter((order) => order.printStatus !== 'impresso').length

  return (
    <PageContainer>
      <PageHeader
        actions={
          <Button icon="orders" onClick={() => onNavigate('pedidos')} variant="primary">
            Abrir fila
          </Button>
        }
        description="Visao operacional para atendimento, pedidos, pagamentos e impressao."
        title="Dashboard"
      />

      <div className="stats-grid">
        <StatCard icon="chat" label="Conversas ativas" trend={formatPercent(20)} value={`${conversations.length}`} />
        <StatCard icon="orders" label="Pedidos em fluxo" trend={formatPercent(12)} value={`${openOrders.length}`} />
        <StatCard icon="finance" label="Total em pedidos" trend={formatPercent(8)} value={formatCurrency(total)} />
        <StatCard icon="printer" label="Comandas pendentes" tone="warning" value={`${waitingPrint}`} />
      </div>

      <div className="dashboard-grid">
        <Card className="sales-card">
          <SectionTitle eyebrow="Ultimas horas" title="Fluxo operacional" />
          <div className="line-chart" aria-label="Grafico visual de fluxo operacional">
            <span style={{ height: '24%' }} />
            <span style={{ height: '38%' }} />
            <span style={{ height: '32%' }} />
            <span style={{ height: '56%' }} />
            <span style={{ height: '48%' }} />
            <span style={{ height: '72%' }} />
            <span style={{ height: '64%' }} />
            <span style={{ height: '82%' }} />
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

        <Card className="activity-card">
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
