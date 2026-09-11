import type { BadgeTone, Order, OrderStatus, PaymentStatus, PrintStatus } from '../../types/crm'

export type OrderQueueFilter = 'active' | 'finished' | 'cancelled' | 'all'

type BadgeView = {
  label: string
  tone: BadgeTone
}

type OrderOperationalState = {
  isCancelled: boolean
  isFinished: boolean
  isDraft: boolean
  isInActiveQueue: boolean
  canReceiveItems: boolean
  canConfirmPayment: boolean
  canChangeStatus: boolean
  canCancel: boolean
  canPrint: boolean
  canDeleteDraft: boolean
  statusBadge: BadgeView
  paymentBadge: BadgeView
  printBadge: BadgeView
}

const LOCKED_ORDER_STATUSES: OrderStatus[] = [
  'impresso',
  'em_preparo',
  'pronto',
  'saiu_para_entrega',
  'finalizado',
  'cancelado',
]

export function getOrderOperationalState(order: Order): OrderOperationalState {
  const isCancelled = order.status === 'cancelado' || order.backendStatus === 'cancelled'
  const isFinished = order.status === 'finalizado' || order.backendStatus === 'finished'
  const isDraft = order.backendStatus === 'draft' || order.status === 'novo'
  const hasItems = order.items.length > 0
  const hasFinancialMovement = order.paid > 0 || order.creditUsed > 0
  const hasPrintMovement = order.printStatus !== 'aguardando'
  const canReceiveItems = !LOCKED_ORDER_STATUSES.includes(order.status) && order.paymentStatus !== 'pago'
  const canConfirmPayment = !isCancelled && order.amountDue > 0
  const canChangeStatus = !isCancelled && order.availableTransitions.length > 0
  const canCancel = !isCancelled && order.availableTransitions.some((transition) => transition.status === 'cancelled')
  const canPrint = !isCancelled && hasItems
  const canDeleteDraft = isDraft && !hasItems && !hasFinancialMovement && !hasPrintMovement && order.total <= 0

  return {
    isCancelled,
    isFinished,
    isDraft,
    isInActiveQueue: !isCancelled && !isFinished,
    canReceiveItems,
    canConfirmPayment,
    canChangeStatus,
    canCancel,
    canPrint,
    canDeleteDraft,
    statusBadge: orderStatusBadge(order.status),
    paymentBadge: isCancelled ? cancelledPaymentBadge(order.paymentStatus) : paymentStatusBadge(order.paymentStatus),
    printBadge: isCancelled ? { label: 'Não aplicável', tone: 'neutral' } : printStatusBadge(order.printStatus),
  }
}

export function orderMatchesQueueFilter(order: Order, filter: OrderQueueFilter): boolean {
  const state = getOrderOperationalState(order)

  switch (filter) {
    case 'active':
      return state.isInActiveQueue
    case 'finished':
      return state.isFinished
    case 'cancelled':
      return state.isCancelled
    case 'all':
      return true
  }
}

export function isOrderInActiveQueue(order: Order): boolean {
  return getOrderOperationalState(order).isInActiveQueue
}

function cancelledPaymentBadge(status: PaymentStatus): BadgeView {
  if (status === 'pago' || status === 'credito') {
    return { label: 'Pago antes do cancelamento', tone: 'neutral' }
  }

  if (status === 'parcial') {
    return { label: 'Pagamento parcial cancelado', tone: 'neutral' }
  }

  return { label: 'Não pago - pedido cancelado', tone: 'neutral' }
}

function orderStatusBadge(status: OrderStatus): BadgeView {
  switch (status) {
    case 'novo':
      return { label: 'Novo', tone: 'warning' }
    case 'em_conferencia':
      return { label: 'Em conferencia', tone: 'info' }
    case 'aguardando_pagamento':
      return { label: 'Aguardando pagamento', tone: 'warning' }
    case 'comprovante_recebido':
      return { label: 'Comprovante recebido', tone: 'info' }
    case 'pagamento_confirmado':
      return { label: 'Pagamento confirmado', tone: 'success' }
    case 'pronto_para_imprimir':
      return { label: 'Pronto para imprimir', tone: 'brand' }
    case 'impresso':
      return { label: 'Impresso', tone: 'success' }
    case 'em_preparo':
      return { label: 'Em preparo', tone: 'warning' }
    case 'pronto':
      return { label: 'Pronto', tone: 'success' }
    case 'saiu_para_entrega':
      return { label: 'Saiu para entrega', tone: 'info' }
    case 'finalizado':
      return { label: 'Finalizado', tone: 'success' }
    case 'cancelado':
      return { label: 'Cancelado', tone: 'danger' }
    case 'manual':
      return { label: 'Manual', tone: 'manual' }
  }
}

function paymentStatusBadge(status: PaymentStatus): BadgeView {
  switch (status) {
    case 'anulado':
      return { label: 'Anulado', tone: 'neutral' }
    case 'cancelado':
      return { label: 'Cancelado', tone: 'neutral' }
    case 'pago':
      return { label: 'Pago', tone: 'success' }
    case 'parcial':
      return { label: 'Parcial', tone: 'warning' }
    case 'credito':
      return { label: 'Crédito', tone: 'manual' }
    case 'revisao_humana':
      return { label: 'Revisao', tone: 'info' }
    case 'pendente':
      return { label: 'Pendente', tone: 'warning' }
  }
}

function printStatusBadge(status: PrintStatus): BadgeView {
  switch (status) {
    case 'imprimindo':
      return { label: 'Imprimindo', tone: 'info' }
    case 'impresso':
      return { label: 'Impresso', tone: 'success' }
    case 'reimpressao':
      return { label: 'Reimpressão solicitada', tone: 'manual' }
    case 'erro':
      return { label: 'Falha na impressão', tone: 'danger' }
    case 'aguardando':
      return { label: 'Aguardando impressão', tone: 'warning' }
  }
}
