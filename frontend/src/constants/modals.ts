import type { AppModal } from '../types/crm'

export function modalTitle(modal: AppModal): string {
  switch (modal) {
    case 'new-order':
      return 'Novo pedido'
    case 'delete-draft':
      return 'Excluir rascunho'
    case 'delete-order-permanent':
      return 'Excluir pedido definitivamente'
    case 'delete-orders-bulk':
      return 'Excluir pedidos selecionados'
    case 'cleanup-test-orders':
      return 'Limpar pedidos de teste'
    case 'confirm-payment':
      return 'Confirmar pagamento'
    case 'void-payment':
      return 'Anular confirmação do pagamento'
    case 'cancel-order':
      return 'Cancelar pedido'
    case 'change-status':
      return 'Alterar status do pedido'
    case 'edit-item':
      return 'Editar item do pedido'
    case 'mark-unavailable':
      return 'Marcar item indisponível'
    case 'add-product':
      return 'Adicionar produto'
    case 'add-user':
      return 'Adicionar usuário'
    case 'toggle-ai':
      return 'Alternar IA/manual da conversa'
    case 'print-preview':
      return 'Prévia de comanda'
    case 'print-error':
      return 'Erro de impressão'
    case 'whatsapp-error':
      return 'Erro de WhatsApp/API'
    default:
      return 'Confirmação'
  }
}

export function modalDescription(modal: AppModal): string {
  switch (modal) {
    case 'new-order':
      return 'Selecione um cliente cadastrado, digite um cliente avulso ou cadastre um novo.'
    case 'confirm-payment':
      return 'Comprovantes e crédito precisam de conferência humana antes de liberar o pedido.'
    case 'void-payment':
      return 'Esta ação corrige apenas o estado interno do CRM. Ela não realiza reembolso.'
    case 'cancel-order':
      return 'Esta ação altera o fluxo operacional e deve registrar motivo.'
    case 'delete-draft':
      return 'Somente rascunho vazio, sem pagamento, itens ou impressão, pode ser apagado.'
    case 'delete-order-permanent':
    case 'delete-orders-bulk':
      return 'Exclusão administrativa definitiva, disponível somente para pedidos cancelados e financeiramente zerados.'
    case 'cleanup-test-orders':
      return 'Limpeza ampla de desenvolvimento, protegida por permissão, ambiente, flag e confirmação.'
    case 'toggle-ai':
      return 'Escolha como esta conversa deve seguir. A confirmação humana continua obrigatória em decisões sensíveis.'
    case 'print-preview':
      return 'Prévia HTML gerada pelo backend. Impressão física real ainda depende de configuração local.'
    case 'print-error':
      return 'A comanda não deve liberar preparo sem impressão ou autorização manual.'
    case 'whatsapp-error':
      return 'Configuração técnica; não é tela operacional de conversa.'
    default:
      return 'Revise o impacto antes de confirmar.'
  }
}
