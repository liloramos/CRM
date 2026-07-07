export type RouteKey =
  | 'login'
  | 'cadastro'
  | 'dashboard'
  | 'conversas'
  | 'pedidos'
  | 'cardapio'
  | 'entregas'
  | 'pagamentos'
  | 'financeiro'
  | 'clientes'
  | 'relatorios'
  | 'configuracoes'
  | 'whatsapp'
  | 'ia'
  | 'perfil'

export type BadgeTone =
  | 'brand'
  | 'success'
  | 'warning'
  | 'danger'
  | 'info'
  | 'manual'
  | 'neutral'

export type OrderStatus =
  | 'novo'
  | 'em_conferencia'
  | 'aguardando_pagamento'
  | 'comprovante_recebido'
  | 'pagamento_confirmado'
  | 'pronto_para_imprimir'
  | 'impresso'
  | 'em_preparo'
  | 'pronto'
  | 'saiu_para_entrega'
  | 'finalizado'
  | 'cancelado'
  | 'manual'

export type PaymentStatus = 'pendente' | 'parcial' | 'pago' | 'credito' | 'revisao_humana'
export type FulfillmentType = 'retirada' | 'entrega' | 'balcao'
export type AutomationMode = 'ia' | 'manual' | 'atencao'
export type PrintStatus = 'aguardando' | 'imprimindo' | 'impresso' | 'reimpressao' | 'erro'

export type CustomerSummary = {
  id: string
  name: string
  phoneLabel: string
  tags: string[]
  creditBalance: number
  notes: string[]
  preferences: string[]
}

export type OrderItem = {
  id: string
  name: string
  quantity: number
  unitPrice: number
  notes: string
  beneficiary: string
  additions: string[]
  unavailable?: boolean
}

export type Order = {
  id: string
  code: string
  customer: CustomerSummary
  status: OrderStatus
  paymentStatus: PaymentStatus
  fulfillmentType: FulfillmentType
  printStatus: PrintStatus
  channel: 'WhatsApp' | 'Manual' | 'Balcao'
  createdLabel: string
  pickupPerson?: string
  deliveryLabel?: string
  generalNotes: string
  kitchenNotes: string
  total: number
  paid: number
  creditUsed: number
  amountDue: number
  items: OrderItem[]
  history: Array<{
    id: string
    title: string
    description: string
    timeLabel: string
  }>
}

export type ConversationMessage = {
  id: string
  sender: 'customer' | 'attendant' | 'ai'
  body: string
  timeLabel: string
}

export type Conversation = {
  id: string
  customer: CustomerSummary
  mode: AutomationMode
  unread: number
  statusLabel: string
  lastMessage: string
  messages: ConversationMessage[]
  linkedOrderId?: string
}

export type Product = {
  id: string
  category: string
  name: string
  description: string
  price: number
  available: boolean
  tags: string[]
}

export type DeliveryTask = {
  id: string
  orderCode: string
  type: FulfillmentType
  status: string
  recipient: string
  routeLabel: string
}

export type FinanceEntry = {
  id: string
  label: string
  status: PaymentStatus
  amount: number
  method: string
}

export type IntegrationStatus = {
  id: string
  title: string
  status: 'online' | 'warning' | 'offline'
  description: string
}

export type AppModal =
  | 'confirm-payment'
  | 'cancel-order'
  | 'change-status'
  | 'edit-item'
  | 'mark-unavailable'
  | 'add-product'
  | 'add-user'
  | 'toggle-ai'
  | 'print-error'
  | 'whatsapp-error'
  | null
