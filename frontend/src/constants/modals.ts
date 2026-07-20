import type { AppModal } from '../types/crm'

export function modalTitle(modal: AppModal): string {
  switch (modal) {
    case 'confirm-payment':
      return 'Confirmar pagamento Pix'
    case 'cancel-order':
      return 'Cancelar pedido'
    case 'change-status':
      return 'Alterar status do pedido'
    case 'edit-item':
      return 'Editar item do pedido'
    case 'mark-unavailable':
      return 'Marcar item indisponivel'
    case 'add-product':
      return 'Adicionar produto'
    case 'add-user':
      return 'Adicionar usuario'
    case 'toggle-ai':
      return 'Alternar IA/manual da conversa'
    case 'print-preview':
      return 'Previa de comanda'
    case 'print-error':
      return 'Erro de impressao'
    case 'whatsapp-error':
      return 'Erro de WhatsApp/API'
    default:
      return 'Confirmacao'
  }
}

export function modalDescription(modal: AppModal): string {
  switch (modal) {
    case 'confirm-payment':
      return 'Comprovantes e credito precisam de conferencia humana antes de liberar o pedido.'
    case 'cancel-order':
      return 'Esta acao altera o fluxo operacional e deve registrar motivo.'
    case 'toggle-ai':
      return 'Escolha como esta conversa deve seguir. A confirmacao humana continua obrigatoria em decisoes sensiveis.'
    case 'print-preview':
      return 'Previa HTML gerada pelo backend. Impressao fisica real ainda depende de configuracao local.'
    case 'print-error':
      return 'A comanda nao deve liberar preparo sem impressao ou autorizacao manual.'
    case 'whatsapp-error':
      return 'Configuracao tecnica; nao e tela operacional de conversa.'
    default:
      return 'Revise o impacto antes de confirmar.'
  }
}
