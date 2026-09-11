export function orderDeletionReasonLabel(reason: string): string {
  switch (reason) {
    case 'order_not_found':
      return 'pedido não encontrado para esta empresa'
    case 'status_not_eligible':
      return 'pedido não cancelado'
    case 'preparation_started':
      return 'preparo iniciado'
    case 'payment_confirmed':
      return 'pagamento confirmado'
    case 'payment_review_pending':
      return 'pagamento ou comprovante aguardando revisao'
    case 'financial_movement':
      return 'crédito, refund ou movimentação financeira vinculada'
    case 'print_confirmed':
      return 'impressão física confirmada'
    case 'delivery_started':
      return 'entrega ou retirada iniciada'
    case 'conversation_linked':
      return 'pedido vinculado a conversa'
    default:
      return reason.replace(/_/g, ' ')
  }
}
