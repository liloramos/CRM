import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { DataTable, type DataTableColumn } from '../../components/ui/DataTable'
import type { AppModal, FinanceEntry } from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

type FinancePageProps = {
  entries: FinanceEntry[]
  mode: 'pagamentos' | 'financeiro'
  onOpenModal: (modal: AppModal) => void
}

const columns: DataTableColumn<FinanceEntry>[] = [
  {
    key: 'label',
    header: 'Movimento',
    render: (entry) => (
      <div className="table-main">
        <strong>{entry.label}</strong>
        <span>{entry.method}</span>
      </div>
    ),
  },
  { key: 'status', header: 'Status', render: (entry) => <Badge tone={entry.status === 'pago' ? 'success' : 'warning'}>{entry.status}</Badge> },
  { key: 'amount', header: 'Valor', align: 'right', render: (entry) => formatCurrency(entry.amount) },
]

export function FinancePage({ entries, mode, onOpenModal }: FinancePageProps) {
  const title = mode === 'pagamentos' ? 'Pagamentos / Pix' : 'Financeiro'

  return (
    <PageContainer>
      <PageHeader
        actions={
          <Button icon="check" onClick={() => onOpenModal('confirm-payment')} variant="primary">
            Confirmar comprovante
          </Button>
        }
        description="Pagamentos, comprovantes e credito com conferencia humana."
        title={title}
      />
      <div className="split-grid">
        <Card>
          <SectionTitle title="Movimentos recentes" />
          <DataTable columns={columns} data={entries} getRowKey={(entry) => entry.id} />
        </Card>
        <Card className="credit-card">
          <SectionTitle title="Credito do cliente" />
          <strong>{formatCurrency(6)}</strong>
          <p>Credito e diferencas devem ser rastreaveis e conferidos pelo atendente.</p>
          <Button icon="payment" variant="secondary">
            Ver historico
          </Button>
        </Card>
      </div>
    </PageContainer>
  )
}
