import type {
  DailyFinancialSummary,
  DeliveryTask,
  ExpenseEntry,
  FinanceEntry,
  IntegrationStatus,
  PaymentMethodSummary,
} from '../types/crm'

export const deliveryTasksMock: DeliveryTask[] = [
  {
    id: 'entrega-001',
    orderCode: '#S-1041',
    type: 'entrega',
    status: 'Em rota planejada',
    recipient: 'Cliente Brisa',
    routeLabel: 'Endereco ficticio cadastrado',
  },
  {
    id: 'entrega-002',
    orderCode: '#S-1042',
    type: 'retirada',
    status: 'Aguardando pessoa autorizada',
    recipient: 'Pessoa autorizada',
    routeLabel: 'Retirada no balcao',
  },
]

export const financeEntriesMock: FinanceEntry[] = [
  {
    id: 'fin-001',
    label: 'Pedido com credito usado',
    orderCode: '#S-1042',
    status: 'revisao_humana',
    amount: 48,
    receivedAmount: 42,
    pendingAmount: 0,
    creditApplied: 6,
    method: 'Pix + credito',
    paymentMethod: 'misto',
    createdLabel: 'Hoje, atendimento atual',
    description: 'Comprovante em conferencia humana antes de liberar preparo.',
  },
  {
    id: 'fin-002',
    label: 'Pedido pago e impresso',
    orderCode: '#S-1041',
    status: 'pago',
    amount: 36,
    receivedAmount: 36,
    pendingAmount: 0,
    creditApplied: 0,
    method: 'Pix',
    paymentMethod: 'pix',
    createdLabel: 'Hoje, fila do almoco',
    description: 'Pagamento confirmado e comanda ja impressa.',
  },
  {
    id: 'fin-003',
    label: 'Diferenca pendente',
    orderCode: '#S-1040',
    status: 'pendente',
    amount: 22,
    receivedAmount: 0,
    pendingAmount: 22,
    creditApplied: 0,
    method: 'A confirmar',
    paymentMethod: 'a_confirmar',
    createdLabel: 'Hoje, balcao',
    description: 'Pedido manual aguardando definicao de pagamento.',
  },
]

export const expenseEntriesMock: ExpenseEntry[] = [
  {
    id: 'desp-001',
    label: 'Insumo operacional ficticio',
    category: 'Despesa simples',
    amount: 12.5,
    createdLabel: 'Hoje',
    notes: 'Registro manual para conferencia interna do dia.',
  },
  {
    id: 'desp-002',
    label: 'Ajuste operacional de entrega',
    category: 'Operacao',
    amount: 6,
    createdLabel: 'Hoje',
    notes: 'Valor ficticio usado apenas para compor o resumo financeiro.',
  },
]

export const paymentMethodSummaryMock: PaymentMethodSummary[] = [
  {
    method: 'pix',
    label: 'Pix confirmado',
    amount: 78,
    count: 2,
    percentage: 74,
    tone: 'success',
  },
  {
    method: 'credito_cliente',
    label: 'Credito usado',
    amount: 6,
    count: 1,
    percentage: 6,
    tone: 'info',
  },
  {
    method: 'a_confirmar',
    label: 'Pendente',
    amount: 22,
    count: 1,
    percentage: 20,
    tone: 'warning',
  },
]

export const dailyFinancialSummaryMock: DailyFinancialSummary = {
  dateLabel: 'Hoje',
  ordersCount: 3,
  paidOrders: 2,
  pendingOrders: 1,
  grossRevenue: 106,
  confirmedRevenue: 84,
  pendingAmount: 22,
  expensesAmount: 18.5,
  netProfit: 65.5,
  pixAmount: 78,
  creditUsed: 6,
  customerCreditBalance: 6,
  averageTicket: 35.33,
}

export const integrationsMock: IntegrationStatus[] = [
  {
    id: 'whatsapp',
    title: 'WhatsApp / API',
    status: 'warning',
    description: 'Configuracao tecnica isolada da tela de conversa.',
  },
  {
    id: 'ia',
    title: 'IA e automacao',
    status: 'online',
    description: 'Sugere respostas, mas exige confirmacao humana em ambiguidades.',
  },
  {
    id: 'impressao',
    title: 'Impressao HTML',
    status: 'warning',
    description: 'Comanda deve ser impressa antes do preparo.',
  },
]
