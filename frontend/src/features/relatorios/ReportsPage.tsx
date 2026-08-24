import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Card, SectionTitle } from '../../components/ui/Card'
import { StatCard } from '../../components/ui/StatCard'

export function ReportsPage() {
  return (
    <PageContainer>
      <PageHeader
        description="Indicadores para acompanhar atendimento, pedidos, impressão e pagamentos."
        title="Relatorios"
      />
      <div className="stats-grid">
        <StatCard icon="chat" label="Tempo de resposta" tone="info" value="2m 45s" />
        <StatCard icon="orders" label="Pedidos conferidos" tone="success" value="86%" />
        <StatCard icon="printer" label="Falhas de impressão" tone="warning" value="3" />
        <StatCard icon="payment" label="Pix em conferência" tone="warning" value="4" />
      </div>
      <Card>
        <SectionTitle title="Leitura operacional" />
        <div className="report-bars">
          <span style={{ width: '86%' }}>Pedidos conferidos</span>
          <span style={{ width: '64%' }}>Conversas resolvidas</span>
          <span style={{ width: '52%' }}>Comandas impressas no prazo</span>
          <span style={{ width: '38%' }}>Pagamentos aguardando conferência</span>
        </div>
      </Card>
    </PageContainer>
  )
}
