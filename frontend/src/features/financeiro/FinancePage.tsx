import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { DataTable, type DataTableColumn } from '../../components/ui/DataTable'
import type {
  AppModal,
  BadgeTone,
  DailyFinancialSummary,
  ExpenseEntry,
  FinanceEntry,
  PaymentMethodSummary,
} from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'
import { CustomerCreditPanel } from './CustomerCreditPanel'
import { ExpenseList } from './ExpenseList'
import { FinancialSummaryCards } from './FinancialSummaryCards'
import { PaymentMethodBreakdown } from './PaymentMethodBreakdown'

type FinancePageProps = {
  entries: FinanceEntry[]
  expenses: ExpenseEntry[]
  paymentMethods: PaymentMethodSummary[]
  summary: DailyFinancialSummary
  mode: 'pagamentos' | 'financeiro'
  onOpenModal: (modal: AppModal) => void
}

const statusLabels: Record<FinanceEntry['status'], string> = {
  credito: 'Credito',
  pago: 'Pago',
  parcial: 'Parcial',
  pendente: 'Pendente',
  revisao_humana: 'Revisao humana',
}

function financeStatusTone(status: FinanceEntry['status']): BadgeTone {
  if (status === 'pago') return 'success'
  if (status === 'pendente' || status === 'parcial') return 'warning'
  if (status === 'revisao_humana') return 'manual'
  return 'info'
}

const columns: DataTableColumn<FinanceEntry>[] = [
  {
    key: 'label',
    header: 'Movimento',
    render: (entry) => (
      <div className="table-main">
        <strong>
          {entry.orderCode} - {entry.label}
        </strong>
        <span>{entry.description}</span>
        <small>{entry.createdLabel}</small>
      </div>
    ),
  },
  { key: 'method', header: 'Forma', render: (entry) => <span className="muted-text">{entry.method}</span> },
  {
    key: 'status',
    header: 'Status',
    render: (entry) => <Badge tone={financeStatusTone(entry.status)}>{statusLabels[entry.status]}</Badge>,
  },
  { key: 'received', header: 'Recebido', align: 'right', render: (entry) => formatCurrency(entry.receivedAmount) },
  { key: 'pending', header: 'Pendente', align: 'right', render: (entry) => formatCurrency(entry.pendingAmount) },
  { key: 'amount', header: 'Total', align: 'right', render: (entry) => formatCurrency(entry.amount) },
]

export function FinancePage({ entries, expenses, mode, onOpenModal, paymentMethods, summary }: FinancePageProps) {
  const title = mode === 'pagamentos' ? 'Pagamentos / Pix' : 'Financeiro'
  const description =
    mode === 'pagamentos'
      ? 'Comprovantes, Pix, pendencias e credito com conferencia humana.'
      : 'Resumo financeiro basico do dia, sem relatorio fiscal ou contabilidade avancada.'

  return (
    <PageContainer>
      <PageHeader
        actions={
          <Button icon="check" onClick={() => onOpenModal('confirm-payment')} variant="primary">
            Confirmar comprovante
          </Button>
        }
        description={description}
        title={title}
      />

      <FinancialSummaryCards summary={summary} />

      <div className="finance-dashboard-grid">
        <Card className="finance-table-card">
          <SectionTitle title="Movimentos recentes" />
          <DataTable columns={columns} data={entries} getRowKey={(entry) => entry.id} />
        </Card>

        <div className="finance-side-stack">
          <Card>
            <SectionTitle eyebrow={summary.dateLabel} title="Formas de pagamento" />
            <PaymentMethodBreakdown methods={paymentMethods} />
          </Card>
          <CustomerCreditPanel summary={summary} />
        </div>

        <Card>
          <SectionTitle title="Despesas simples" />
          <ExpenseList expenses={expenses} />
        </Card>

        <Card className="finance-note-card">
          <SectionTitle title="Indicadores do dia" />
          <div className="finance-meta-grid">
            <div>
              <span>Pedidos</span>
              <strong>{summary.ordersCount}</strong>
            </div>
            <div>
              <span>Ticket medio</span>
              <strong>{formatCurrency(summary.averageTicket)}</strong>
            </div>
            <div>
              <span>Faturamento bruto</span>
              <strong>{formatCurrency(summary.grossRevenue)}</strong>
            </div>
            <div>
              <span>Despesas</span>
              <strong>{formatCurrency(summary.expensesAmount)}</strong>
            </div>
          </div>
          <p className="muted-text">Exportacao para planilha e relatorios avancados ficam preparados para modulo futuro.</p>
        </Card>
      </div>
    </PageContainer>
  )
}
