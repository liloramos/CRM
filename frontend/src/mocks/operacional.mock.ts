import type { DeliveryTask, FinanceEntry, IntegrationStatus } from '../types/crm'

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
    status: 'revisao_humana',
    amount: 48,
    method: 'Pix + credito',
  },
  {
    id: 'fin-002',
    label: 'Pedido pago e impresso',
    status: 'pago',
    amount: 36,
    method: 'Pix',
  },
  {
    id: 'fin-003',
    label: 'Diferenca pendente',
    status: 'pendente',
    amount: 22,
    method: 'A confirmar',
  },
]

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
