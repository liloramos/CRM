import { customersMock } from './clientes.mock'
import type { Conversation } from '../types/crm'

export const conversationsMock: Conversation[] = [
  {
    id: 'conv-001',
    customer: customersMock[0],
    mode: 'atencao',
    unread: 2,
    statusLabel: 'Precisa confirmar pedido',
    lastMessage: 'Quero igual ao ultimo, mas muda uma marmita.',
    linkedOrderId: 'pedido-001',
    messages: [
      {
        id: 'msg-1',
        sender: 'customer',
        body: 'Quero duas marmitas, uma com pouca massa.',
        timeLabel: 'agora',
      },
      {
        id: 'msg-2',
        sender: 'customer',
        body: 'Tambem quero usar o credito se estiver certo.',
        timeLabel: 'agora',
      },
      {
        id: 'msg-3',
        sender: 'ai',
        body: 'Sugestao: confirmar itens, retirada e uso de credito antes de fechar.',
        timeLabel: 'sugestao',
      },
    ],
  },
  {
    id: 'conv-002',
    customer: customersMock[1],
    mode: 'ia',
    unread: 0,
    statusLabel: 'Aguardando cliente',
    lastMessage: 'Pode deixar separado para retirada.',
    linkedOrderId: 'pedido-002',
    messages: [
      {
        id: 'msg-4',
        sender: 'attendant',
        body: 'Pedido conferido. Vamos avisar quando estiver pronto.',
        timeLabel: 'ha 5 min',
      },
    ],
  },
  {
    id: 'conv-003',
    customer: customersMock[2],
    mode: 'manual',
    unread: 1,
    statusLabel: 'Atendente assumiu',
    lastMessage: 'Ainda nao sei se vou retirar ou comer no local.',
    linkedOrderId: 'pedido-003',
    messages: [
      {
        id: 'msg-5',
        sender: 'customer',
        body: 'Ainda nao sei se vou retirar ou comer no local.',
        timeLabel: 'agora',
      },
    ],
  },
]
