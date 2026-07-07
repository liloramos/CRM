import { StatCard } from '../../components/ui/StatCard'
import type { DailyFinancialSummary } from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

type FinancialSummaryCardsProps = {
  summary: DailyFinancialSummary
}

export function FinancialSummaryCards({ summary }: FinancialSummaryCardsProps) {
  return (
    <div className="stats-grid finance-summary-grid">
      <StatCard icon="finance" label="Faturamento confirmado" tone="success" value={formatCurrency(summary.confirmedRevenue)} />
      <StatCard icon="payment" label="Pix confirmado" value={formatCurrency(summary.pixAmount)} />
      <StatCard icon="alert" label="Pendencias" tone="warning" value={formatCurrency(summary.pendingAmount)} />
      <StatCard icon="reports" label="Lucro simples" tone="info" value={formatCurrency(summary.netProfit)} />
    </div>
  )
}
