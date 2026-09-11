import { useEffect, useMemo, useState } from 'react'
import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { DataTable, type DataTableColumn } from '../../components/ui/DataTable'
import { DatePickerField } from '../../components/ui/DatePickerField'
import { SelectField } from '../../components/ui/SelectField'
import { EmptyState, ErrorState, LoadingState } from '../../components/ui/States'
import { getFinancialOverview } from '../../services/crm.service'
import type { AppModal, DailyFinancialSummary, ExpenseEntry, FinanceEntry, FinancialMovement, FinancialOverview, PaymentMethodSummary } from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'
import { FinancialSummaryCards } from './FinancialSummaryCards'

type FinancePageProps = {
  entries: FinanceEntry[]
  expenses: ExpenseEntry[]
  canConfirmPayment: boolean
  canReviewPaymentProof: boolean
  canManageFinance: boolean
  isPaymentActionBusy: boolean
  paymentFeedback: string | null
  paymentMethods: PaymentMethodSummary[]
  summary: DailyFinancialSummary
  mode: 'pagamentos' | 'financeiro'
  onOpenModal: (modal: AppModal) => void
  onApprovePaymentProof: (entry: FinanceEntry) => Promise<void>
  onConfirmPayment: (entry: FinanceEntry) => void
  onOpenConversation: (conversationId: string) => void
  onOpenOrder: (orderId: string) => void
  onOpenPermanentDelete: (orderId: string) => void
  onRejectPaymentProof: (entry: FinanceEntry, reason: string) => Promise<void>
  onOpenVoidPayment: (orderId: string, paymentId?: string) => void
}

type FinancialFilters = { from: string; to: string; method: string; status: string; search: string }

const paymentStatusLabels: Record<FinanceEntry['status'], string> = {
  credito: 'Crédito', anulado: 'Anulado', cancelado: 'Cancelado', pago: 'Confirmado', parcial: 'Parcial',
  pendente: 'Pendente', revisao_humana: 'Conferência necessária',
}

const methodLabels: Record<string, string> = {
  pix: 'Pix', cash: 'Dinheiro', debit_card: 'Cartão de débito', credit_card: 'Cartão de crédito',
  customer_credit: 'Crédito do cliente', mixed: 'Misto', other: 'Outro',
}

export function FinancePage(props: FinancePageProps) {
  return props.mode === 'pagamentos' ? <PaymentsOperations {...props} /> : <FinancialManagement {...props} />
}

function PaymentsOperations({
  canConfirmPayment,
  canReviewPaymentProof,
  entries,
  isPaymentActionBusy,
  onApprovePaymentProof,
  onConfirmPayment,
  onOpenConversation,
  onOpenOrder,
  onRejectPaymentProof,
  paymentFeedback,
  summary,
}: FinancePageProps) {
  const [paymentView, setPaymentView] = useState<'pending' | 'concluded' | 'cancelled' | 'all'>('pending')
  const [paymentDate, setPaymentDate] = useState('')
  const queue = useMemo(() => [...entries]
    .filter((entry) => (!paymentDate || entry.createdAt?.slice(0, 10) === paymentDate) && (paymentView === 'all' || (paymentView === 'pending' ? ['pendente', 'parcial', 'revisao_humana'].includes(entry.status) : paymentView === 'concluded' ? entry.status === 'pago' : ['anulado', 'cancelado'].includes(entry.status))))
    .sort((first, second) => paymentPriority(first.status) - paymentPriority(second.status)), [entries, paymentDate, paymentView])
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const selected = queue.find((entry) => entry.id === selectedId) ?? null
  const hasPendingProof = selected?.proof?.status === 'received'
  const hasReviewableProof = Boolean(hasPendingProof && selected?.canReviewProof)
  const canRunPrimaryAction = Boolean(selected && (hasPendingProof ? hasReviewableProof && canReviewPaymentProof : canConfirmPayment && selected.canConfirmPayment))
  const columns: DataTableColumn<FinanceEntry>[] = [
    { key: 'order', header: 'Pedido / cliente', render: (entry) => <div className="table-main"><strong>{entry.orderCode} · {entry.customerName}</strong><span>{entry.customerPhone ?? 'Sem telefone cadastrado'}</span><small>{entry.createdLabel}</small></div> },
    { key: 'method', header: 'Forma', render: (entry) => entry.method },
    { key: 'status', header: 'Situação', render: (entry) => <Badge tone={entry.status === 'pago' ? 'success' : entry.status === 'revisao_humana' ? 'manual' : entry.status === 'pendente' || entry.status === 'parcial' ? 'warning' : 'neutral'}>{paymentStatusLabels[entry.status]}</Badge> },
    { key: 'proof', header: 'Comprovante', render: (entry) => proofStateLabel(entry.proof?.status) },
    { key: 'total', header: 'Total', align: 'right', render: (entry) => formatCurrency(entry.amount) },
    { key: 'received', header: 'Recebido', align: 'right', render: (entry) => formatCurrency(entry.receivedAmount) },
    { key: 'pending', header: 'Pendente', align: 'right', render: (entry) => formatCurrency(entry.pendingAmount) },
    { key: 'reason', header: 'Motivo operacional', render: (entry) => <span className="payments-reason">{entry.description}</span> },
  ]

  async function runPrimaryAction() {
    if (!selected || !canRunPrimaryAction) return
    if (hasReviewableProof) {
      if (!window.confirm(`Aprovar o comprovante do pedido ${selected.orderCode}, cliente ${selected.customerName}?`)) return
      await onApprovePaymentProof(selected)
      return
    }
    onConfirmPayment(selected)
  }

  async function rejectSelectedProof() {
    if (!selected?.proof || !selected.conversationId || !canReviewPaymentProof) return
    const reason = window.prompt(`Motivo da rejeição do comprovante do pedido ${selected.orderCode}, cliente ${selected.customerName}:`)
    if (!reason?.trim()) return
    await onRejectPaymentProof(selected, reason.trim())
  }

  return (
    <PageContainer density="wide">
      <PageHeader
        actions={<Button disabled={!canRunPrimaryAction || isPaymentActionBusy} icon="check" onClick={() => void runPrimaryAction()} variant="primary">{hasReviewableProof ? 'Aprovar comprovante selecionado' : 'Registrar pagamento selecionado'}</Button>}
        description="Fila diária para conferir comprovantes, Pix e pagamentos pendentes."
        title="Pagamentos / Pix"
      />
      <FinancialSummaryCards summary={summary} />
      {paymentFeedback ? <p className="payments-feedback" role="status">{paymentFeedback}</p> : null}
      <Card className="payments-operations-card">
        <div className="financial-period-actions" aria-label="Segmentação de pagamentos">{([['pending', 'Pendentes'], ['concluded', 'Concluídos'], ['cancelled', 'Cancelados/anulados'], ['all', 'Todos']] as const).map(([value, label]) => <Button key={value} onClick={() => { setPaymentView(value); setSelectedId(null) }} size="sm" variant={paymentView === value ? 'primary' : 'ghost'}>{label}</Button>)}<DatePickerField label="Data" onChange={(value) => { setPaymentDate(value); setSelectedId(null) }} value={paymentDate} /></div>
        <SectionTitle eyebrow={`${queue.length} registro(s)`} title="O que precisa ser conferido agora?" />
        {queue.length > 0 ? <DataTable columns={columns} data={queue} getRowKey={(entry) => entry.id} isRowSelected={(entry) => entry.id === selectedId} onRowSelect={(entry) => setSelectedId(entry.id)} /> : <EmptyState description="Nenhum pagamento aguardando conferência." title="Fila de pagamentos vazia" />}
      </Card>
      <Card className="payments-detail-panel" aria-live="polite">
        <SectionTitle eyebrow="Pagamento selecionado" title={selected ? `${selected.orderCode} · ${selected.customerName}` : 'Selecione uma linha da fila'} />
        {selected ? (
          <>
            <dl className="payments-detail-grid">
              <div><dt>Cliente</dt><dd>{selected.customerName}<small>{selected.customerPhone ?? 'Sem telefone cadastrado'}</small></dd></div>
              <div><dt>Pedido</dt><dd>{selected.orderCode}<small>{selected.createdLabel}</small></dd></div>
              <div><dt>Pagamento</dt><dd>{selected.method}<small>{paymentStatusLabels[selected.status]}</small></dd></div>
              <div><dt>Valores</dt><dd>{formatCurrency(selected.amount)}<small>{formatCurrency(selected.receivedAmount)} recebido · {formatCurrency(selected.pendingAmount)} pendente</small></dd></div>
              <div><dt>Comprovante</dt><dd>{proofStateLabel(selected.proof?.status)}<small>{selected.proof?.receivedAt ? formatDateTime(selected.proof.receivedAt) : 'Sem data de recebimento'}</small>{selected.proof?.mediaUrl ? <a href={selected.proof.mediaUrl} rel="noreferrer" target="_blank">Abrir comprovante</a> : null}</dd></div>
            </dl>
            <div className="payments-items"><strong>Itens do pedido</strong>{selected.items.length > 0 ? <ul>{selected.items.map((item) => <li key={item.id}><span>{item.quantity}× {item.name}</span><strong>{formatCurrency(item.totalPrice)}</strong></li>)}</ul> : <span>Nenhum item projetado para este pedido.</span>}</div>
            <p className="payments-operational-reason"><strong>Motivo operacional:</strong> {selected.description}</p>
            <div className="payments-detail-actions">
              <Button disabled={!canRunPrimaryAction || isPaymentActionBusy} icon="check" onClick={() => void runPrimaryAction()}>{hasReviewableProof ? 'Aprovar comprovante' : 'Registrar pagamento'}</Button>
              {hasReviewableProof ? <Button disabled={!canReviewPaymentProof || isPaymentActionBusy} onClick={() => void rejectSelectedProof()} variant="secondary">Rejeitar comprovante</Button> : null}
              <Button onClick={() => onOpenOrder(selected.orderId)} variant="ghost">Ver pedido</Button>
              {selected.conversationId ? <Button onClick={() => onOpenConversation(selected.conversationId!)} variant="ghost">Ver conversa</Button> : null}
            </div>
          </>
        ) : <EmptyState description="A seleção explícita evita confirmar o pagamento de outro pedido por engano." title="Nenhum pagamento selecionado" />}
      </Card>
    </PageContainer>
  )
}

function FinancialManagement({ canManageFinance, onOpenVoidPayment }: FinancePageProps) {
  const [overview, setOverview] = useState<FinancialOverview | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [filters, setFilters] = useState<FinancialFilters>(() => ({ from: localDate(), to: localDate(), method: '', status: 'concluded', search: '' }))
  const [reloadKey, setReloadKey] = useState(0)
  const [selectedMovementId, setSelectedMovementId] = useState<string | null>(null)
  const selectedMovement = overview?.movements.find((movement) => movement.id === selectedMovementId) ?? null

  useEffect(() => {
    let active = true
    void getFinancialOverview(filters)
      .then((data) => { if (active) setOverview(data) })
      .catch((reason) => { if (active) setError(reason instanceof Error ? reason.message : 'Não foi possível carregar o Financeiro.') })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [filters, reloadKey])

  function updateFilters(update: (current: FinancialFilters) => FinancialFilters) {
    setLoading(true)
    setError(null)
    setFilters(update)
  }

  const columns: DataTableColumn<FinancialMovement>[] = [
    { key: 'date', header: 'Data/hora', render: (movement) => formatDateTime(movement.occurredAt) },
    { key: 'origin', header: 'Origem', render: (movement) => originLabel(movement.origin) },
    { key: 'order', header: 'Pedido / cliente', render: (movement) => <div className="table-main"><strong>{movement.orderCode ?? 'Sem pedido'}</strong><span>{movement.customerName}</span>{movement.notes ? <small>{movement.notes}</small> : null}</div> },
    { key: 'type', header: 'Tipo', render: (movement) => movement.type === 'void' ? 'Anulação' : 'Pagamento' },
    { key: 'method', header: 'Forma', render: (movement) => methodLabels[movement.method] ?? movement.method },
    { key: 'status', header: 'Status', render: (movement) => <Badge tone={movement.status === 'confirmed' ? 'success' : movement.status === 'voided' || movement.status === 'cancelled' ? 'neutral' : movement.status === 'rejected' ? 'danger' : 'warning'}>{financialStatusLabel(movement.status)}</Badge> },
    { key: 'amount', header: 'Recebido', align: 'right', render: (movement) => movement.totalAmount !== undefined ? <><strong>{formatCurrency(movement.amount)}</strong><small> de {formatCurrency(movement.totalAmount)}</small></> : formatCurrency(movement.amount) },
    { key: 'operator', header: 'Operador', render: (movement) => movement.operatorName ?? 'Sistema' },
    { key: 'actions', header: 'Ações', align: 'right', render: (movement) => <div className="table-actions"><Button onClick={() => setSelectedMovementId(movement.id)} size="sm" variant="ghost">Detalhes</Button></div> },
  ]

  return (
    <PageContainer density="wide">
      <PageHeader description="Controle e auditoria dos movimentos financeiros reais do restaurante." title="Financeiro" />
      <Card className="financial-filter-card">
        <div className="financial-filters">
          <DatePickerField label="De" value={filters.from} onChange={(value) => updateFilters((current) => ({ ...current, from: value }))} />
          <DatePickerField label="Até" value={filters.to} onChange={(value) => updateFilters((current) => ({ ...current, to: value }))} />
          <SelectField label="Forma" value={filters.method} onChange={(value) => updateFilters((current) => ({ ...current, method: value }))} options={[{ value: '', label: 'Todas' }, ...Object.entries(methodLabels).map(([value, label]) => ({ value, label }))]} />
          <SelectField label="Visão" value={filters.status} onChange={(value) => updateFilters((current) => ({ ...current, status: value }))} options={[{ value: 'concluded', label: 'Concluídos' }, { value: 'pending_group', label: 'Pendentes' }, { value: 'cancelled_group', label: 'Cancelados/anulados' }, { value: '', label: 'Todos' }]} />
          <label className="financial-filters__search"><span>Cliente ou pedido</span><input placeholder="Buscar" value={filters.search} onChange={(event) => updateFilters((current) => ({ ...current, search: event.target.value }))} /></label>
        </div>
        <div className="financial-period-actions">
          <Button onClick={() => setQuickPeriod(0, updateFilters)} size="sm" variant="ghost">Hoje</Button>
          <Button onClick={() => setQuickPeriod(6, updateFilters)} size="sm" variant="ghost">7 dias</Button>
          <Button onClick={() => setQuickPeriod(29, updateFilters)} size="sm" variant="ghost">30 dias</Button>
        </div>
      </Card>

      {loading ? <LoadingState description="Consolidando pagamentos e créditos do período." title="Carregando movimentos" /> : null}
      {error ? <ErrorState actionLabel="Tentar novamente" description={error} onAction={() => { setLoading(true); setError(null); setReloadKey((value) => value + 1) }} title="Financeiro indisponível" /> : null}
      {!loading && !error && overview ? (
        <>
          <div className="financial-management-summary">
            <FinancialMetric label="Faturamento confirmado" value={formatCurrency(overview.summary.confirmedRevenue)} />
            <FinancialMetric label="Pendente" value={formatCurrency(overview.summary.pendingAmount)} />
            <FinancialMetric label="Créditos de clientes" value={formatCurrency(overview.summary.creditBalance)} />
            <FinancialMetric label="Anulações" value={`${overview.summary.voidedCount} · ${formatCurrency(overview.summary.voidedAmount)}`} />
            <FinancialMetric label="Ticket médio" value={overview.summary.averageTicket === null ? 'Sem dados suficientes' : formatCurrency(overview.summary.averageTicket)} />
          </div>
          <Card className="finance-table-card">
            <SectionTitle eyebrow={`${overview.summary.movementCount} venda(s) ou movimento(s)`} title="Vendas e movimentações" />
            {overview.movements.length > 0 ? <DataTable columns={columns} data={overview.movements} getRowKey={(movement) => movement.id} isRowSelected={(movement) => movement.id === selectedMovementId} onRowSelect={(movement) => setSelectedMovementId(movement.id)} /> : <EmptyState description="Sem movimentações para os filtros selecionados." title="Sem movimentações" />}
          </Card>
          {selectedMovement ? <FinancialMovementDetails canManageFinance={canManageFinance} movement={selectedMovement} onOpenVoidPayment={onOpenVoidPayment} /> : null}
        </>
      ) : null}
    </PageContainer>
  )
}

function FinancialMovementDetails({ canManageFinance, movement, onOpenVoidPayment }: { canManageFinance: boolean; movement: FinancialMovement; onOpenVoidPayment: (orderId: string, paymentId?: string) => void }) {
  if (!movement.details) return <Card className="finance-table-card"><SectionTitle eyebrow="Movimento avulso" title="Detalhes" /><p>{movement.notes ?? 'Movimento financeiro sem pedido relacionado.'}</p></Card>

  return <Card className="finance-table-card">
    <SectionTitle eyebrow={movement.orderCode ?? 'Venda'} title="Detalhes financeiros" />
    <dl className="payments-detail-grid">
      <div><dt>Itens do pedido</dt><dd>{formatCurrency(movement.details.items)}</dd></div>
      <div><dt>Taxa de entrega</dt><dd>{formatCurrency(movement.details.deliveryFee)}</dd></div>
      <div><dt>Ajustes</dt><dd>{formatCurrency(movement.details.adjustments)}</dd></div>
      {movement.details.creditUsed > 0 ? <div><dt>Créditos utilizados</dt><dd>{formatCurrency(movement.details.creditUsed)}</dd></div> : null}
      <div><dt>Total</dt><dd><strong>{formatCurrency(movement.details.total)}</strong></dd></div>
      <div><dt>Recebido</dt><dd>{formatCurrency(movement.details.received)}</dd></div>
    </dl>
    <div className="payments-items"><strong>Pagamentos relacionados</strong><ul>{movement.details.payments.map((payment) => <li key={payment.id}><span>{methodLabels[payment.method] ?? payment.method} · {financialStatusLabel(payment.status)}{payment.operatorName ? ` · ${payment.operatorName}` : ''}</span><strong>{formatCurrency(payment.amount)}</strong>{payment.canVoid && canManageFinance && movement.orderId ? <Button onClick={() => onOpenVoidPayment(movement.orderId!, payment.id)} size="sm" variant="ghost">Anular</Button> : null}</li>)}</ul></div>
  </Card>
}

function FinancialMetric({ label, value }: { label: string; value: string }) {
  return <Card className="financial-metric"><span>{label}</span><strong>{value}</strong></Card>
}

function paymentPriority(status: FinanceEntry['status']): number {
  return status === 'revisao_humana' ? 0 : status === 'pendente' || status === 'parcial' ? 1 : 2
}

function proofStateLabel(status: string | undefined): string {
  return ({ received: 'Recebido · aguardando análise', accepted: 'Aprovado', rejected: 'Rejeitado' } as Record<string, string>)[status ?? ''] ?? 'Não recebido'
}

function localDate(date = new Date()): string {
  const offset = date.getTimezoneOffset() * 60_000
  return new Date(date.getTime() - offset).toISOString().slice(0, 10)
}

function setQuickPeriod(daysBack: number, setter: (update: (current: FinancialFilters) => FinancialFilters) => void) {
  const to = new Date()
  const from = new Date()
  from.setDate(from.getDate() - daysBack)
  setter((current) => ({ ...current, from: localDate(from), to: localDate(to) }))
}

function formatDateTime(value: string | null): string {
  if (!value) return 'Data não informada'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' })
}

function financialStatusLabel(status: string): string {
  return ({ pending: 'Pendente', partial: 'Parcialmente pago', partially_voided: 'Parcialmente anulado', awaiting_proof: 'Aguardando comprovante', proof_received: 'Comprovante recebido', confirmed: 'Confirmado', rejected: 'Rejeitado', cancelled: 'Cancelado', voided: 'Anulado' } as Record<string, string>)[status] ?? status
}

function originLabel(origin: string): string {
  return ({ whatsapp: 'WhatsApp', counter: 'Balcão', manual: 'Manual', phone: 'Telefone' } as Record<string, string>)[origin] ?? origin
}
