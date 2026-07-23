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
export type PaymentMethod = 'pix' | 'dinheiro' | 'cartao' | 'credito_cliente' | 'misto' | 'a_confirmar'
export type FulfillmentApiType = 'pickup' | 'delivery' | 'counter'

export type MenuOption = {
  id: string
  name: string
  type: string
  groupCode: string
  groupLabel: string
  priceDelta: number
  required: boolean
  availableToday: boolean
  dailyReason?: string | null
}

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
  deliveryFee: number
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
  options: MenuOption[]
}

export type EffectiveAvailabilityStatus = 'available' | 'unavailable' | 'sold_out'

export type EffectiveAvailabilitySource =
  | 'product_override'
  | 'global_availability'
  | 'component_default'
  | 'product_default'
  | 'daily_menu_override'
  | 'product_service_day'

export type EffectiveAvailability = {
  status: EffectiveAvailabilityStatus
  available: boolean
  source: EffectiveAvailabilitySource
  reason: string | null
  availability_date: string
  replacement?: StructuredMenuComponentSummary | null
}

export type StructuredMenuCategorySummary = {
  id: number
  slug: string
  name: string
  category_type: string
}

export type StructuredMenuComponentSummary = {
  id: number
  slug: string
  name: string
  component_type: string
}

export type StructuredMenuProductSummary = {
  id: number
  slug: string
  name: string
  product_type: string
  base_price_cents: number
  currency: string
  is_active: boolean
  is_available_by_default: boolean
  availability: EffectiveAvailability
  service_days: Array<'monday' | 'tuesday' | 'wednesday' | 'thursday' | 'friday' | 'saturday' | 'sunday'>
  category: StructuredMenuCategorySummary | null
}

export type ProductSelectionMode = 'fixed' | 'single' | 'multiple' | 'addon' | 'variation' | 'included_choice'

export type ProductSelectionActor = 'system' | 'house' | 'customer'

export type StructuredComponentOption = {
  id: number
  component_id: number
  slug: string
  name: string
  component_type: string
  price_delta_cents: number
  final_price_cents: number | null
  included_quantity: number | null
  is_default: boolean
  requires_confirmation: boolean
  link_active: boolean
  available: boolean
  availability: EffectiveAvailability
  display_order: number
}

export type StructuredProductOption = {
  id: number
  selectable_product: StructuredMenuProductSummary
  price_delta_cents: number
  final_price_cents: number | null
  included_quantity: number | null
  is_default: boolean
  requires_confirmation: boolean
  link_active: boolean
  available: boolean
  availability: EffectiveAvailability
  display_order: number
}

export type StructuredProductOptionGroup = {
  id: number
  code: string
  label: string
  selection_mode: ProductSelectionMode
  selection_actor: ProductSelectionActor
  required: boolean
  min_choices: number | null
  max_choices: number | null
  min_quantity: number | null
  max_quantity: number | null
  same_component_only: boolean
  included_in_base_price: boolean
  component_options: StructuredComponentOption[]
  product_options: StructuredProductOption[]
  display_order: number
}

export type StructuredComboItem = {
  id: number
  included_product: StructuredMenuProductSummary
  quantity: number
  price_behavior: 'included' | 'extra'
  price_delta_cents: number
  print_mode: 'child_line' | 'note'
  display_order: number
}

export type StructuredMenuProduct = StructuredMenuProductSummary & {
  description: string | null
  menu_rule_code: string | null
  uses_weekly_menu: boolean
  allows_item_notes: boolean
  notes_hint: string | null
  configuration_pending: boolean
  groups: StructuredProductOptionGroup[]
  combo_items: StructuredComboItem[]
}

export type StructuredMenuCategory = StructuredMenuCategorySummary & {
  products: StructuredMenuProduct[]
  display_order: number
}

export type StructuredMenuCatalogResponse = {
  date: string
  categories: StructuredMenuCategory[]
}

export type DailyMenuSectionKey = 'hot' | 'salad' | 'meat' | 'extra'

export type DailyMenuComponent = {
  id: number
  section: DailyMenuSectionKey
  display_order: number
  notes: string | null
  component: StructuredMenuComponentSummary
  availability: EffectiveAvailability
  available: boolean
}

export type DailyStructuredMenu = {
  date: string
  service_day: 'monday' | 'tuesday' | 'wednesday' | 'thursday' | 'friday' | 'saturday' | null
  is_service_day: boolean
  timezone: string
  weekly_menu: {
    id: number
    slug: string
    name: string
    starts_on: string | null
    ends_on: string | null
  } | null
  sections: Record<DailyMenuSectionKey, DailyMenuComponent[]>
  traditional_products: StructuredMenuProductSummary[]
  catalog: StructuredMenuCatalogResponse
}

export type CompanySummary = {
  id: string
  name: string
  slug: string
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
  orderCode: string
  status: PaymentStatus
  amount: number
  receivedAmount: number
  pendingAmount: number
  creditApplied: number
  method: string
  paymentMethod: PaymentMethod
  createdLabel: string
  description: string
}

export type ExpenseEntry = {
  id: string
  label: string
  category: string
  amount: number
  createdLabel: string
  notes: string
}

export type PaymentMethodSummary = {
  method: PaymentMethod
  label: string
  amount: number
  count: number
  percentage: number
  tone: BadgeTone
}

export type DailyFinancialSummary = {
  dateLabel: string
  ordersCount: number
  paidOrders: number
  pendingOrders: number
  grossRevenue: number
  confirmedRevenue: number
  pendingAmount: number
  expensesAmount: number
  netProfit: number
  pixAmount: number
  creditUsed: number
  customerCreditBalance: number
  averageTicket: number
}

export type IntegrationStatus = {
  id: string
  title: string
  status: 'online' | 'warning' | 'offline'
  description: string
}

export type AuthUser = {
  id: string
  name: string
  email: string
  company: CompanySummary | null
  roles: string[]
  permissions: string[]
}

export type OperationalSnapshot = {
  company?: CompanySummary
  orders: Order[]
  conversations: Conversation[]
  customers: CustomerSummary[]
  products: Product[]
  deliveries: DeliveryTask[]
  financeEntries: FinanceEntry[]
  financialSummary: DailyFinancialSummary
  expenses: ExpenseEntry[]
  paymentMethods: PaymentMethodSummary[]
  integrations: IntegrationStatus[]
}

export type SnapshotSource = 'api' | 'mock'

export type PrintPreviewResult = {
  id: string
  status: string
  html: string
  previewUrl?: string | null
  generatedAt?: string | null
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
  | 'print-preview'
  | 'print-error'
  | 'whatsapp-error'
  | null
