import { Card, SectionTitle } from '../../components/ui/Card'
import type { DailyFinancialSummary } from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

type CustomerCreditPanelProps = {
  summary: DailyFinancialSummary
}

export function CustomerCreditPanel({ summary }: CustomerCreditPanelProps) {
  return (
    <Card className="credit-card">
      <SectionTitle title="Crédito de clientes" />
      <strong>{formatCurrency(summary.customerCreditBalance)}</strong>
      <p>Saldo e uso de crédito ficam destacados para conferência manual antes de aplicar em novos pedidos.</p>
      <div className="finance-meta-grid">
        <div>
          <span>Usado hoje</span>
          <strong>{formatCurrency(summary.creditUsed)}</strong>
        </div>
        <div>
          <span>Pedidos pagos</span>
          <strong>{summary.paidOrders}</strong>
        </div>
      </div>
    </Card>
  )
}
