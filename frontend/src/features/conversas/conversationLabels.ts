import type { ConversationQuickReplyCategory } from '../../types/crm'

const quickReplyCategoryLabels: Record<ConversationQuickReplyCategory, string> = {
  greeting: 'Saudação',
  menu: 'Cardápio',
  order: 'Pedido',
  address: 'Endereço',
  payment: 'Pagamento',
  payment_proof: 'Comprovante',
  unavailable_product: 'Produto indisponível',
  human_support: 'Atendimento humano',
  closing: 'Encerramento',
}

export function quickReplyCategoryLabel(category: ConversationQuickReplyCategory): string {
  return quickReplyCategoryLabels[category]
}
