import { useEffect, useState } from 'react'
import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { DatePickerField } from '../../components/ui/DatePickerField'
import { EmptyState, ErrorState, LoadingState } from '../../components/ui/States'
import { StatCard } from '../../components/ui/StatCard'
import { getOperationalReport } from '../../services/crm.service'
import type { OperationalReport } from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

type PeriodPreset = 'today' | '7days' | '30days' | 'custom'

export function ReportsPage() {
  const [preset, setPreset] = useState<PeriodPreset>('7days')
  const [range, setRange] = useState(() => periodFor('7days'))
  const [report, setReport] = useState<OperationalReport | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    let active = true
    void getOperationalReport(range.from, range.to)
      .then((data) => { if (active) setReport(data) })
      .catch((reason) => { if (active) setError(reason instanceof Error ? reason.message : 'Não foi possível carregar os relatórios.') })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [range, reloadKey])

  function selectPreset(next: Exclude<PeriodPreset, 'custom'>) {
    setLoading(true)
    setError(null)
    setPreset(next)
    setRange(periodFor(next))
  }

  function updateRange(update: (current: { from: string; to: string }) => { from: string; to: string }) {
    setLoading(true)
    setError(null)
    setPreset('custom')
    setRange(update)
  }

  return (
    <PageContainer density="wide">
      <PageHeader description="Indicadores calculados exclusivamente a partir dos registros operacionais do período." title="Relatórios" />
      <Card className="reports-filter-card">
        <div className="reports-period-actions" aria-label="Período do relatório">
          {(['today', '7days', '30days'] as const).map((value) => <Button key={value} onClick={() => selectPreset(value)} size="sm" variant={preset === value ? 'primary' : 'ghost'}>{periodLabel(value)}</Button>)}
        </div>
        <div className="reports-custom-period">
          <DatePickerField label="De" value={range.from} onChange={(value) => updateRange((current) => ({ ...current, from: value }))} />
          <DatePickerField label="Até" value={range.to} onChange={(value) => updateRange((current) => ({ ...current, to: value }))} />
        </div>
      </Card>

      {loading ? <LoadingState description="Calculando indicadores a partir do banco de dados." title="Gerando relatório" /> : null}
      {error ? <ErrorState actionLabel="Tentar novamente" description={error} onAction={() => { setLoading(true); setError(null); setReloadKey((value) => value + 1) }} title="Relatório indisponível" /> : null}
      {!loading && !error && report ? <ReportContent report={report} /> : null}
    </PageContainer>
  )
}

function ReportContent({ report }: { report: OperationalReport }) {
  const metrics = report.metrics

  return (
    <>
      <div className="stats-grid reports-stats-grid">
        <StatCard icon="chat" label="Tempo médio de resposta" showActionIndicator={false} showDecoration={false} tone="info" trend={report.sufficiency.responseTime ? `${metrics.responseSampleCount} resposta(s) medidas` : 'Não há pares de mensagem e resposta'} value={metrics.averageResponseSeconds === null ? 'Sem dados suficientes' : formatDuration(metrics.averageResponseSeconds)} />
        <StatCard icon="orders" label="Pedidos pagos" showActionIndicator={false} showDecoration={false} tone="success" trend={`${metrics.ordersCompleted} concluído(s) · ${metrics.ordersCancelled} cancelado(s)`} value={report.sufficiency.orders ? String(metrics.ordersPaid) : 'Sem dados suficientes'} />
        <StatCard icon="printer" label="Falhas de impressão" showActionIndicator={false} showDecoration={false} tone="warning" trend={report.sufficiency.printing ? `${metrics.printJobsPrinted} de ${metrics.printJobsGenerated} comanda(s) impressa(s)` : 'Nenhuma comanda gerada'} value={report.sufficiency.printing ? String(metrics.printFailures) : 'Sem dados suficientes'} />
        <StatCard icon="payment" label="Pix em conferência" showActionIndicator={false} showDecoration={false} tone="warning" trend={report.sufficiency.payments ? `${metrics.paymentsConfirmed} pagamento(s) confirmado(s)` : 'Nenhum pagamento registrado'} value={report.sufficiency.payments ? String(metrics.pixAwaitingReview) : 'Sem dados suficientes'} />
      </div>

      <div className="reports-summary-grid">
        <Card><SectionTitle title="Pedidos" /><ReportFacts facts={[['Criados', metrics.ordersCreated], ['Concluídos', metrics.ordersCompleted], ['Cancelados', metrics.ordersCancelled], ['Ticket médio', metrics.averageTicket === null ? 'Sem dados suficientes' : formatCurrency(metrics.averageTicket)]]} /></Card>
        <Card><SectionTitle title="Pagamentos" /><ReportFacts facts={[['Faturamento confirmado', formatCurrency(metrics.confirmedRevenue)], ['Confirmados', metrics.paymentsConfirmed], ['Pendentes', metrics.paymentsPending], ['Pix em conferência', metrics.pixAwaitingReview]]} /></Card>
        <Card><SectionTitle title="Conversas" /><ReportFacts facts={[['Iniciadas', metrics.conversationsStarted], ['Automatizadas', metrics.conversationsAutomated], ['Revisões humanas', metrics.humanReviewEvents], ['Amostras de resposta', metrics.responseSampleCount]]} /></Card>
      </div>

      <div className="reports-chart-grid">
        <Card className="reports-chart-wide"><SectionTitle title="Faturamento por dia" /><DailyBars data={report.charts.revenueByDay} currency /></Card>
        <Card><SectionTitle title="Pedidos por status" /><CategoryBars data={report.charts.ordersByStatus} labeler={orderStatusLabel} /></Card>
        <Card><SectionTitle title="Formas de pagamento" /><CategoryBars data={report.charts.paymentsByMethod} labeler={paymentMethodLabel} /></Card>
        <Card className="reports-chart-wide"><SectionTitle title="Volume de pedidos" /><DailyBars data={report.charts.ordersByDay} /></Card>
      </div>
    </>
  )
}

function ReportFacts({ facts }: { facts: Array<[string, string | number]> }) {
  return <div className="report-facts">{facts.map(([label, value]) => <div key={label}><span>{label}</span><strong>{value}</strong></div>)}</div>
}

function DailyBars({ currency = false, data }: { currency?: boolean; data: Array<{ date: string; value: number }> }) {
  const max = Math.max(0, ...data.map((item) => item.value))
  if (max === 0) return <EmptyState description="O banco não possui movimentos suficientes neste período." title="Sem dados para o gráfico" />

  return <div className="daily-bars" role="img" aria-label="Gráfico diário"><div className="daily-bars__plot">{data.map((item) => <div className="daily-bars__column" key={item.date} title={`${formatDay(item.date)}: ${currency ? formatCurrency(item.value) : item.value}`}><span style={{ height: `${Math.max(4, (item.value / max) * 100)}%` }} /><small>{formatDay(item.date)}</small></div>)}</div></div>
}

function CategoryBars({ data, labeler }: { data: Array<{ label: string; value: number }>; labeler: (label: string) => string }) {
  const max = Math.max(0, ...data.map((item) => item.value))
  if (max === 0) return <EmptyState description="Nenhum registro real foi encontrado neste período." title="Sem dados para o gráfico" />

  return <div className="category-bars">{data.map((item) => <div className="category-bars__row" key={item.label}><div><span>{labeler(item.label)}</span><strong>{item.value}</strong></div><div><span style={{ width: `${(item.value / max) * 100}%` }} /></div></div>)}</div>
}

function periodFor(preset: Exclude<PeriodPreset, 'custom'>) {
  const to = new Date()
  const from = new Date()
  if (preset === '7days') from.setDate(from.getDate() - 6)
  if (preset === '30days') from.setDate(from.getDate() - 29)
  return { from: localDate(from), to: localDate(to) }
}

function localDate(date: Date): string {
  const offset = date.getTimezoneOffset() * 60_000
  return new Date(date.getTime() - offset).toISOString().slice(0, 10)
}

function periodLabel(preset: Exclude<PeriodPreset, 'custom'>): string {
  return preset === 'today' ? 'Hoje' : preset === '7days' ? '7 dias' : '30 dias'
}

function formatDuration(seconds: number): string {
  const minutes = Math.floor(seconds / 60)
  const remaining = seconds % 60
  return minutes > 0 ? `${minutes}m ${remaining.toString().padStart(2, '0')}s` : `${remaining}s`
}

function formatDay(value: string): string {
  const [, month, day] = value.split('-')
  return `${day}/${month}`
}

function orderStatusLabel(value: string): string {
  return ({ draft: 'Rascunho', awaiting_payment: 'Aguardando pagamento', payment_confirmed: 'Pagamento confirmado', ready_to_print: 'Pronto para imprimir', printed: 'Impresso', in_preparation: 'Em preparo', ready_for_pickup: 'Pronto', out_for_delivery: 'Em entrega', finished: 'Finalizado', cancelled: 'Cancelado' } as Record<string, string>)[value] ?? value
}

function paymentMethodLabel(value: string): string {
  return ({ pix: 'Pix', cash: 'Dinheiro', debit_card: 'Cartão de débito', credit_card: 'Cartão de crédito', customer_credit: 'Crédito do cliente', mixed: 'Misto', other: 'Outro' } as Record<string, string>)[value] ?? value
}
