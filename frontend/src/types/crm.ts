export type RouteKey =
  | 'login'
  | 'cadastro'
  | 'dashboard'
  | 'conversas'
  | 'caixa'
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
  | 'assistente'
  | 'suporte'
  | 'perfil'
  | 'empresa'
  | 'configuracoes-gerais'
  | 'configuracoes-usuarios'
  | 'configuracoes-marca'
  | 'configuracoes-impressao'
  | 'configuracoes-pagamentos'
  | 'configuracoes-seguranca'

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

export type PaymentStatus = 'pendente' | 'parcial' | 'pago' | 'credito' | 'revisao_humana' | 'anulado' | 'cancelado'
export type FulfillmentType = 'retirada' | 'entrega' | 'balcao'
export type AutomationMode = 'ia' | 'manual' | 'atencao'
export type PrintStatus = 'aguardando' | 'imprimindo' | 'impresso' | 'reimpressao' | 'erro'
export type PrintJobStatus = 'previewed' | 'queued' | 'printing' | 'printed' | 'failed' | 'reprint_requested' | 'printer_unavailable' | 'manual_confirmed' | 'waived'
export type PaymentMethod = 'pix' | 'dinheiro' | 'cartao' | 'credito_cliente' | 'misto' | 'a_confirmar'
export type FulfillmentApiType = 'pickup' | 'delivery' | 'counter'

export type BackendOrderStatus =
  | 'draft'
  | 'awaiting_customer_confirmation'
  | 'confirmed'
  | 'awaiting_payment'
  | 'awaiting_payment_proof'
  | 'payment_proof_received'
  | 'payment_confirmed'
  | 'payment_rejected'
  | 'ready_to_print'
  | 'printed'
  | 'in_preparation'
  | 'ready_for_pickup'
  | 'out_for_delivery'
  | 'finished'
  | 'cancelled'

export type MenuOption = {
  id: string
  name: string
  type: string
  groupCode: string
  groupLabel: string
  priceDelta: number
  required: boolean
  allow_no_meat?: boolean
  availableToday: boolean
  dailyReason?: string | null
}

export type CustomerSummary = {
  id: string
  name: string
  phoneLabel: string
  phone?: string | null
  email?: string | null
  whatsappId?: string | null
  whatsappProfileName?: string | null
  sourceChannel?: string | null
  lastWhatsappAt?: string | null
  tags: string[]
  creditBalance: number
  notes: string[]
  preferences: string[]
  address?: {
    street?: string | null
    number?: string | null
    complement?: string | null
    neighborhood?: string | null
    city?: string | null
    reference?: string | null
  } | null
}

export type OrderItem = {
  id: string
  name: string
  quantity: number
  unitPrice: number
  totalPrice: number
  notes: string
  beneficiary: string | null
  composition?: string[]
  removals?: string[]
  additions: string[]
  edit?: {
    productId: string
    itemNotes: string
    beneficiaryName?: string | null
    composition?: {
      structured_options?: Array<{ component_link_id?: number; product_link_id?: number; quantity?: number }>
      included_component_ids?: number[]
      removed_component_ids?: number[]
      removed_group_codes?: string[]
      daily_component_ids?: number[]
      meat_mode?: 'traditional' | 'beef_only' | 'none'
      traditional_meat_component_ids?: number[]
      additions?: Array<{ code: string; quantity: number }>
    }
    options?: Array<{
      name: string
      groupCode: string
      quantity: number
      metadata: Record<string, unknown>
    }>
    removals?: string[]
  }
  unavailable?: boolean
}

export type Order = {
  id: string
  code: string
  conversationId?: string | null
  resolvedConversationId?: string | null
  backendStatus?: BackendOrderStatus
  seller?: SellerSummary | null
  sellerName?: string | null
  customer: CustomerSummary
  status: OrderStatus
  paymentStatus: PaymentStatus
  paymentMethod?: PaymentMethod
  fulfillmentType: FulfillmentType
  printStatus: PrintStatus
  latestPrintJobId?: string | null
  latestPrintJobStatus?: PrintJobStatus | null
  printConfirmationPending?: boolean
  hasConfirmedPrint?: boolean
  channel: 'WhatsApp' | 'Manual' | 'Balcao'
  createdLabel: string
  availableTransitions: Array<{
    status: BackendOrderStatus
    label: string
  }>
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
    actorName?: string | null
    timeLabel: string
  }>
}

export type ConversationMessage = {
  id: string
  sender: 'customer' | 'attendant' | 'ai' | 'system'
  direction?: 'inbound' | 'outbound'
  type?: 'text' | 'image' | 'video' | 'document' | 'audio' | 'location' | 'interactive' | 'unsupported' | string
  body: string | null
  timeLabel: string
  createdAt?: string | null
  occurredAt?: string | null
  status?: string | null
  errorMessage?: string | null
  errorCode?: string | null
  isPinned?: boolean
  isRevoked?: boolean
  pinnedAt?: string | null
  sentAt?: string | null
  receivedAt?: string | null
  deliveredAt?: string | null
  readAt?: string | null
  failedAt?: string | null
  replyTo?: {
    id: string
    sender: 'customer' | 'attendant' | 'ai' | 'system'
    type?: ConversationMessage['type']
    body: string
  } | null
  media?: Array<{
    id: string
    type: string
    name: string
    filename?: string | null
    mimeType: string | null
    sizeBytes: number | null
    url: string | null
    isVoiceNote?: boolean
    contentHash?: string | null
  }>
  reactions?: Array<{
    emoji: string
    source: 'customer' | 'operator'
    createdAt?: string | null
  }>
}

export type ConversationQuickReplyCategory =
  | 'greeting'
  | 'menu'
  | 'order'
  | 'address'
  | 'payment'
  | 'payment_proof'
  | 'unavailable_product'
  | 'human_support'
  | 'closing'
  | 'information'

export type ConversationQuickReply = {
  id: string
  title: string
  shortcut: string
  body: string
  category: ConversationQuickReplyCategory
  isActive: boolean
  displayOrder: number
  createdAt?: string | null
  updatedAt?: string | null
}

export type ConversationAiStyle = {
  establishment_name: string
  preferred_greeting: string
  tone: 'warm' | 'direct' | 'casual' | 'professional'
  formality: 'informal' | 'balanced' | 'formal'
  emoji_usage: 'none' | 'light' | 'moderate'
  preferred_words: string[]
  forbidden_words: string[]
  human_transfer_message: string
  payment_proof_received_message: string
  closing_message: string
}

export type ConversationAlert = {
  id: string
  type: string
  severity: 'info' | 'warning' | 'critical'
  status: 'open' | 'acknowledged' | 'resolved'
  title: string
  message: string
  createdAt: string | null
  acknowledgedAt?: string | null
  resolvedAt?: string | null
  paymentProofId?: string | null
  orderId?: string | null
  conversationId?: string | null
  paymentId?: string | null
  isActionable?: boolean
}

export type SellerSummary = {
  id: string
  name: string
}

export type OperationalNotification = {
  id: string
  kind: 'message' | 'alert'
  title: string
  description: string
  occurredAt: string | null
  conversationId: string | null
  orderId: string | null
  paymentId: string | null
  severity: 'info' | 'warning' | 'critical'
}

export type ConversationPaymentReview = {
  proofId: string
  orderId: string
  orderCode: string
  customerName: string
  expectedTotal: number
  amountCents: number | null
  status: string
  receivedAt: string | null
  fileName: string | null
  mimeType: string | null
  mediaUrl: string | null
  evidences: Array<{
    proofId: string
    receivedAt: string | null
    fileName: string | null
    mimeType: string | null
    mediaUrl: string | null
  }>
} | null

export type ConversationOperationalStatus = {
  code: 'IDLE' | 'IN_SERVICE' | 'AWAITING_PAYMENT' | 'PAYMENT_REVIEW' | 'PREPARING' | 'AWAITING_DELIVERY' | 'OUT_FOR_DELIVERY' | 'COMPLETED' | 'ATTENTION'
  label: string
  tone: 'gray' | 'blue' | 'yellow' | 'orange' | 'cyan' | 'purple' | 'teal' | 'green' | 'red'
  reason?: string | null
  priority: number
}

export type Conversation = {
  id: string
  timezone?: string
  customer: CustomerSummary
  mode: AutomationMode
  automationMode?: 'assisted' | 'automatic' | 'manual'
  automationStatus?: string
  automationVersion?: number
  automationRollout?: 'disabled' | 'shadow' | 'act_safe'
  hasCopilotAnalysis?: boolean
  unread: number
  statusLabel: string
  operationalStatus?: ConversationOperationalStatus
  lastMessage: string
  messages: ConversationMessage[]
  linkedOrderId?: string
  activeOrder?: Order | null
  assignedUser?: { id: string; name: string } | null
  manualTakeoverBy?: { id: string; name: string } | null
  handoffReason?: string | null
  lastMessageAt?: string | null
  isPinned?: boolean
  pinnedAt?: string | null
  alerts?: ConversationAlert[]
  paymentReview?: ConversationPaymentReview
}

export type Product = {
  id: string
  slug?: string
  category: string
  name: string
  description: string
  price: number
  available: boolean
  tags: string[]
  options: MenuOption[]
  structuredGroups?: StructuredProductOptionGroup[]
  meatConfiguration?: StructuredMeatConfiguration | null
  additions?: StructuredProductAddition[]
  dailyMeatOptions?: DailyMenuComponent[]
  comboItems?: StructuredComboItem[]
  usesWeeklyMenu?: boolean
  fixedComponentsRemovable?: boolean
  removableGroupCodes?: string[]
  configurationPending?: boolean
  serviceDays?: ProductServiceDayKey[]
  resolvedConfiguration?: ResolvedProductConfiguration | null
}

export type ResolvedDailyProductComponent = {
  id: number
  slug: string
  name: string
  category: string
  section: DailyMenuSectionKey
  available: boolean
  applicability: 'AVAILABLE_TODAY' | 'UNAVAILABLE_TODAY' | 'NOT_APPLICABLE'
  selectable: boolean
  fixed: boolean
  removable: boolean
}

export type ResolvedProductConfiguration = {
  product: {
    id: number
    slug: string
    name: string
    base_price_cents: number
    availability: EffectiveAvailability
  }
  date: string
  static_configuration: StructuredMenuProduct
  daily_components: ResolvedDailyProductComponent[]
  meat_selection: StructuredMeatConfiguration['traditional']['selection_rules']
  allow_no_meat: boolean
}

export type EffectiveAvailabilityStatus = 'available' | 'unavailable' | 'sold_out'

export type EffectiveAvailabilitySource =
  | 'product_override'
  | 'global_availability'
  | 'component_default'
  | 'product_default'
  | 'daily_menu_override'
  | 'product_service_day'

export type ProductServiceDayKey =
  | 'monday'
  | 'tuesday'
  | 'wednesday'
  | 'thursday'
  | 'friday'
  | 'saturday'
  | 'sunday'

export type WeeklyMenuServiceDayKey = Exclude<ProductServiceDayKey, 'sunday'>

export type DailyMenuSectionKey = 'hot' | 'salad' | 'meat' | 'extra'

export type MenuComponentTypeKey = 'base' | 'hot' | 'salad' | 'meat' | 'extra' | 'addon' | 'juice_flavor'

export type DailyMenuAdjustmentAction = 'include' | 'exclude'

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
  display_name: string
  supporting_name: string | null
  search_aliases: string[]
  component_type: MenuComponentTypeKey | string
}

export type StructuredMenuProductSummary = {
  id: number
  slug: string
  name: string
  product_type: string
  menu_rule_code: string | null
  pricing_mode: 'unit' | 'weight' | string
  weight_unit: string | null
  base_price_cents: number | null
  currency: string
  is_active: boolean
  is_available_by_default: boolean
  administrative_status: 'active' | 'inactive' | 'archived' | 'legacy'
  is_archived: boolean
  is_legacy: boolean
  legacy_reason: string | null
  display_order: number
  is_counter_product: boolean
  image_url: string | null
  availability: EffectiveAvailability
  service_days: ProductServiceDayKey[]
  category: StructuredMenuCategorySummary | null
}

export type ProductSelectionMode = 'fixed' | 'single' | 'multiple' | 'addon' | 'variation' | 'included_choice'

export type ProductSelectionActor = 'system' | 'house' | 'customer'

export type StructuredComponentOption = {
  id: number
  component_id: number
  slug: string
  name: string
  display_name: string
  supporting_name: string | null
  search_aliases: string[]
  component_type: MenuComponentTypeKey | string
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
  allow_no_meat?: boolean
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

export type StructuredMeatConfiguration = {
  traditional: {
    enabled: boolean
    base_price_cents: number | null
    selection_rules: {
      min: number | null
      max: number | null
      same_component_only: boolean
    }
    allow_no_meat: boolean
    additional_meat_price_cents: number | null
  }
  beef_only: {
    enabled: boolean
    final_price_cents: number | null
    price_delta_cents: number | null
    replaces_traditional_meats: boolean
    option_id: number | null
    component: StructuredMenuComponentSummary | null
  }
}

export type StructuredProductAddition = {
  code: 'extra_beef' | string
  group_code: string
  name: string
  enabled: boolean
  price_cents: number
  price_delta_cents: number
  max_quantity: number | null
  requires_traditional_meats: boolean
  option_id: number
  component: StructuredMenuComponentSummary
}

export type StructuredMenuProduct = StructuredMenuProductSummary & {
  description: string | null
  menu_rule_code: string | null
  uses_weekly_menu: boolean
  fixed_components_removable: boolean
  removable_group_codes: string[]
  allows_item_notes: boolean
  notes_hint: string | null
  configuration_pending: boolean
  meat_configuration: StructuredMeatConfiguration | null
  additions: StructuredProductAddition[]
  groups: StructuredProductOptionGroup[]
  combo_items: StructuredComboItem[]
}

export type CounterSaleAddition = {
  code: 'extra_beef' | string
  name: string
  price_cents: number
  max_quantity: number
}

export type CounterSaleProduct = StructuredMenuProductSummary & {
  additions: CounterSaleAddition[]
}

export type CounterSaleCustomer = Pick<CustomerSummary, 'id' | 'name' | 'phone' | 'phoneLabel'>

export type CounterSaleCustomerSnapshot = {
  name: string
  phone: string | null
}

export type CounterSaleDraft = {
  id: string
  code: string
  productId: number | null
  productName: string
  menuRuleCode: 'self_service_counter' | 'counter_weight_standard' | 'counter_weight_meat_only'
  openedAtLabel: string
  timeLabel: string
  status: 'draft'
  statusLabel: string
  weightPending: boolean
  weightGrams: number | null
  pricePerKgCents: number | null
  selectedComponents: string[]
  hasExtraBeef: boolean
  notes: string | null
  seller: SellerSummary | null
  sellerName: string | null
  customer: CounterSaleCustomer | null
  customerSnapshot: CounterSaleCustomerSnapshot | null
}

export type CounterSaleHistoryFilters = {
  dateFrom?: string
  dateTo?: string
  paymentMethod?: 'cash' | 'pix' | 'debit_card' | 'credit_card'
  status?: 'completed' | 'cancelled'
}

export type CounterSaleRecord = {
  id: string
  code: string
  timeLabel: string
  dateLabel: string
  itemsQuantity: number
  totalCents: number
  paymentMethod: 'cash' | 'pix' | 'debit_card' | 'credit_card' | null
  paymentMethodLabel: string
  status: 'completed' | 'cancelled'
  statusLabel: string
  isCancellable: boolean
  seller: SellerSummary | null
  sellerName: string | null
  customer: CounterSaleCustomer | null
  customerSnapshot: CounterSaleCustomerSnapshot | null
}

export type CounterSaleSummary = {
  dateLabel: string
  totalSoldCents: number
  completedSalesCount: number
  totalCancelledCents: number
  paymentTotals: {
    cash: number
    pix: number
    card: number
  }
}

export type CounterSaleTopProduct = {
  productName: string
  quantity: number
  totalCents: number
}

export type CounterSaleHistory = {
  filters: Required<Pick<CounterSaleHistoryFilters, 'dateFrom' | 'dateTo'>> & {
    paymentMethod: CounterSaleHistoryFilters['paymentMethod'] | null
    status: CounterSaleHistoryFilters['status'] | null
    timezone: string
  }
  summary: CounterSaleSummary
  topProducts: CounterSaleTopProduct[]
  sales: CounterSaleRecord[]
}

export type CounterSaleDetail = CounterSaleRecord & {
  originLabel: string
  payment: {
    methodLabel: string
    statusLabel: string
    amountCents: number
    confirmedAtLabel: string | null
    voidReason: string | null
  }
  items: Array<{
    id: string
    productName: string
    productImageUrl: string | null
    quantity: number
    weightGrams: number | null
    pricePerKgCents: number | null
    unitPriceCents: number
    subtotalCents: number
  }>
  history: Array<{
    id: string
    title: string
    description: string
    actorName: string | null
    timeLabel: string
  }>
}

export type StructuredMenuCategory = StructuredMenuCategorySummary & {
  products: StructuredMenuProduct[]
  display_order: number
  description?: string | null
  is_active?: boolean
}

export type StructuredMenuCatalogResponse = {
  date: string
  categories: StructuredMenuCategory[]
}

export type DailyMenuComponent = {
  id: number
  source?: 'weekly_menu' | 'daily_adjustment'
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

export type AdminMenuProductsResponse = StructuredMenuCatalogResponse

export type AdminMenuComponent = StructuredMenuComponentSummary & {
  description: string | null
  default_price_delta_cents: number
  is_active: boolean
  display_order: number
  product_group_links_count: number
  weekly_menu_items_count: number
  weekly_menu_items: Array<{
    id: number
    service_day: WeeklyMenuServiceDayKey
    section: DailyMenuSectionKey
    display_order: number
    is_active: boolean
    notes: string | null
  }>
  availability: EffectiveAvailability
}

export type AdminMenuComponentsResponse = {
  components: AdminMenuComponent[]
}

export type AdminWeeklyMenuItem = {
  id: number
  service_day: WeeklyMenuServiceDayKey
  section: DailyMenuSectionKey
  display_order: number
  is_active: boolean
  notes: string | null
  component: StructuredMenuComponentSummary
}

export type AdminWeeklyMenuResponse = {
  weekly_menu: {
    id: number
    slug: string
    name: string
    starts_on: string | null
    ends_on: string | null
    is_active: boolean
  } | null
  days: Record<WeeklyMenuServiceDayKey, Record<DailyMenuSectionKey, AdminWeeklyMenuItem[]>>
}

export type AdminDailyMenuAdjustment = {
  id: number
  date: string
  section: DailyMenuSectionKey
  action: DailyMenuAdjustmentAction
  display_order: number | null
  notes: string | null
  updated_at: string | null
  marked_by: {
    id: number
    name: string
  } | null
  component: StructuredMenuComponentSummary
}

export type AdminDailyMenuAdjustmentsResponse = {
  date: string
  adjustments: AdminDailyMenuAdjustment[]
}

export type ComponentAvailabilityMutationResponse = {
  scope: 'global' | 'product_override'
  component: StructuredMenuComponentSummary
  product?: StructuredMenuProductSummary
  date: string
  configured_status: EffectiveAvailabilityStatus | null
  reason?: string | null
  replacement?: StructuredMenuComponentSummary | null
  effective_availability: EffectiveAvailability
  cleared?: boolean
}

export type DailyMenuAdjustmentMutationResponse = {
  cleared: boolean
  id?: number
  date: string
  section: DailyMenuSectionKey
  action?: DailyMenuAdjustmentAction
  display_order?: number | null
  notes?: string | null
  marked_by_user_id?: number | null
  component: StructuredMenuComponentSummary
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

export type DeliveryCoordinates = {
  latitude: number
  longitude: number
}

export type DeliveryAddress = {
  postal_code?: string | null
  street?: string | null
  number?: string | null
  complement?: string | null
  neighborhood?: string | null
  city?: string | null
  state?: string | null
  reference?: string | null
  latitude?: number | null
  longitude?: number | null
}

export type DeliveryMapTask = {
  id: string
  order_code: string
  status: string
  order_status: BackendOrderStatus
  recipient: string | null
  address: DeliveryAddress | null
  origin: DeliveryCoordinates | null
  destination: DeliveryCoordinates | null
  distance_meters: number | null
  duration_seconds: number | null
  encoded_polyline: string | null
  calculated_fee_cents: number | null
  final_fee_cents: number
  quote_id: number | null
}

export type DeliveryDistanceBand = {
  up_to_meters: number
  fee_cents: number
}

export type DeliverySettings = {
  id: number
  company_id: number
  is_active: boolean
  calculation_mode: 'per_km' | 'distance_bands' | string
  price_per_km_cents: number
  minimum_fee_cents: number | null
  maximum_distance_km: number | null
  maps_provider: 'google' | 'fake' | 'none' | string
  provider_options: {
    origin?: DeliveryCoordinates & { address?: string }
    distance_bands?: DeliveryDistanceBand[]
  } | null
}

export type FinanceEntry = {
  id: string
  orderId: string
  paymentId: string | null
  conversationId: string | null
  label: string
  orderCode: string
  customerName: string
  customerPhone: string | null
  status: PaymentStatus
  amount: number
  receivedAmount: number
  pendingAmount: number
  creditApplied: number
  canVoidPayment?: boolean
  permanentDeletion?: {
    eligible: boolean
    reasons: string[]
  } | null
  method: string
  paymentMethod: PaymentMethod
  createdLabel: string
  createdAt: string | null
  canConfirmPayment: boolean
  canReviewProof: boolean
  items: Array<{ id: string; name: string; quantity: number; totalPrice: number }>
  proof: {
    id: string
    status: 'received' | 'accepted' | 'rejected' | string
    amount: number | null
    receivedAt: string | null
    fileName: string | null
    mimeType: string | null
    mediaUrl: string | null
  } | null
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

export type FinancialMovement = {
  id: string
  occurredAt: string | null
  origin: string
  orderId: string | null
  orderCode: string | null
  customerName: string
  type: 'payment' | 'void'
  method: string
  status: string
  amount: number
  totalAmount?: number
  operatorName: string | null
  notes: string | null
  canVoid: boolean
  paymentCount?: number
  details?: {
    items: number
    deliveryFee: number
    adjustments: number
    creditUsed: number
    total: number
    received: number
    payments: Array<{ id: string; method: string; status: string; amount: number; occurredAt: string | null; operatorName: string | null; canVoid: boolean }>
  }
}

export type FinancialOverview = {
  period: { from: string; to: string; timezone: string }
  summary: {
    confirmedRevenue: number
    receivedAmount: number
    pendingAmount: number
    creditBalance: number
    voidedAmount: number
    voidedCount: number
    averageTicket: number | null
    paidOrders: number
    movementCount: number
  }
  movements: FinancialMovement[]
  methods: Array<{ method: string; count: number; amount: number }>
}

export type OperationalReport = {
  period: { from: string; to: string; timezone: string }
  metrics: {
    averageResponseSeconds: number | null
    responseSampleCount: number
    ordersCreated: number
    ordersCompleted: number
    ordersCancelled: number
    ordersPaid: number
    averageTicket: number | null
    confirmedRevenue: number
    paymentsConfirmed: number
    paymentsPending: number
    pixAwaitingReview: number
    printJobsGenerated: number
    printJobsPrinted: number
    printFailures: number
    conversationsStarted: number
    conversationsAutomated: number
    humanReviewEvents: number
  }
  sufficiency: Record<'responseTime' | 'orders' | 'payments' | 'printing' | 'conversations', boolean>
  charts: {
    revenueByDay: Array<{ date: string; value: number }>
    ordersByStatus: Array<{ label: string; value: number }>
    paymentsByMethod: Array<{ label: string; value: number }>
    ordersByDay: Array<{ date: string; value: number }>
  }
  formulas: Record<string, string>
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
  phone: string | null
  jobTitle: string | null
  avatarUrl: string | null
  company: CompanySummary | null
  roles: string[]
  permissions: string[]
}

export type OperationalSnapshot = {
  company?: CompanySummary
  capabilities: {
    can_permanently_delete_orders: boolean
    can_run_destructive_test_cleanup: boolean
    destructive_cleanup_environment: string
  }
  orders: Order[]
  sellerCandidates: SellerSummary[]
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

export type AddItemContext = {
  orderId: string
  orderCode: string
  defaultBeneficiaryName: string
  product: Product | null
  resolvedConversationId?: string | null
  source: SnapshotSource
}

export type PrintPreviewResult = {
  id: string
  status: string
  html: string
  previewUrl?: string | null
  generatedAt?: string | null
}

export type PaymentSettingMethod = {
  code: 'pix' | 'cash' | 'debit_card' | 'credit_card' | 'customer_credit' | 'other'
  label: string
  description: string
  enabled: boolean
}

export type PaymentSettings = {
  methods: PaymentSettingMethod[]
  pix: { public_key: string | null }
}

export type AccountSecurity = {
  account: { name: string; email: string; roles: string[] }
  two_factor: { available: boolean; enabled: boolean; confirmation_required: boolean }
  session: { active: boolean }
}

export type WhatsAppIntegration = {
  provider: string
  configured: boolean
  connection_status: 'local' | 'not_configured' | 'configured' | 'connected' | 'error'
  phone_number_masked: string | null
  phone_number_id_masked: string | null
  waba_id_masked: string | null
  api_version: string | null
  token_configured: boolean
  webhook: { url: string | null; status: 'local' | 'pending' | 'verified'; last_received_at: string | null }
  last_inbound: { conversation_id: number | null; status: string; occurred_at: string | null } | null
  last_outbound: { conversation_id: number | null; status: string; occurred_at: string | null } | null
  recent_errors: Array<{ kind: string; occurred_at: string | null }>
  processing: { pending_webhook_events: number; failed_webhook_events: number }
}

export type AiAutomationSettings = {
  provider: string
  model: string | null
  api_key_configured: boolean
  rollout: 'disabled' | 'shadow' | 'act_safe'
  automation_enabled: boolean
  allow_auto_send: boolean
  guidance: {
    version: number
    instructions: string[]
    updated_at: string | null
    updated_by_user_id: number | null
  }
  last_execution_at: string | null
  last_failure: string | null
  handoffs: Array<{ reason: string; occurred_at: string | null }>
}

export type AiAutomationSandboxResult = {
  reply: string
  reply_messages: string[]
  classification: string
  requires_human_review: boolean
  action: string
  reason: string
  rollout: AiAutomationSettings['rollout']
}

export type SystemAssistantAction = {
  type: 'explain_feature' | 'navigate_to_page' | 'open_order' | 'open_customer' | 'show_pending_payments' | 'show_deliveries' | 'open_menu' | 'open_company_settings' | 'open_finance' | 'open_reports'
  target: RouteKey
  label: string
  parameters: { order_id?: string; customer_id?: string }
}

export type SystemAssistantResponse = { answer: string; action: SystemAssistantAction | null }

export type SupportTicket = {
  id: string
  code: string
  category: 'order' | 'payment' | 'printing' | 'whatsapp' | 'ai' | 'delivery' | 'menu' | 'access' | 'customer' | 'reports' | 'other'
  subject: string
  priority: 'low' | 'normal' | 'high'
  status: 'open' | 'in_review' | 'resolved'
  created_at: string | null
  email_delivery_status: 'not_configured' | 'sent' | 'failed'
  contact: { email_configured: boolean; whatsapp_number: string | null; whatsapp_url: string | null }
}

export type SupportTicketCenter = { tickets: SupportTicket[]; contact: { email_configured: boolean; whatsapp_number: string | null } }

export type AppModal =
  | 'new-order'
  | 'delete-draft'
  | 'delete-order-permanent'
  | 'delete-orders-bulk'
  | 'cleanup-test-orders'
  | 'confirm-payment'
  | 'void-payment'
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
