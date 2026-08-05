import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Card, SectionTitle } from '../../components/ui/Card'
import { StatCard } from '../../components/ui/StatCard'

export function ReportsPage() {
  return (
    <PageContainer>
      <PageHeader
        description="Indicadores para acompanhar atendimento comercial, prospecção e performance."
        title="Relatórios"
      />
      <div className="stats-grid">
        <StatCard icon="chat" label="Tempo de resposta" tone="info" value="2m 45s" />
        <StatCard icon="orders" label="Leads revisados" tone="success" value="86%" />
        <StatCard icon="printer" label="Exportações pendentes" tone="warning" value="3" />
        <StatCard icon="payment" label="Oportunidades em revisão" tone="warning" value="4" />
      </div>
      <Card>
        <SectionTitle title="Leitura operacional" />
        <div className="report-bars">
          <span style={{ width: '86%' }}>Leads revisados</span>
          <span style={{ width: '64%' }}>Conversas resolvidas</span>
          <span style={{ width: '52%' }}>Exportações entregues no prazo</span>
          <span style={{ width: '38%' }}>Oportunidades com revisão humana</span>
        </div>
      </Card>
    </PageContainer>
  )
}
