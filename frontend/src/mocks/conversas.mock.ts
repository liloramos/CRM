import { customersMock } from './clientes.mock'
import type { Conversation } from '../types/crm'

export const conversationsMock: Conversation[] = [
  {
    id: 'conv-001',
    customer: customersMock[0],
    mode: 'atencao',
    unread: 2,
    statusLabel: 'Precisa confirmar oportunidade',
    lastMessage: 'Quero seguir com o mesmo plano, mas mudar a prioridade.',
    linkedOrderId: 'operacao-001',
    messages: [
      {
        id: 'msg-1',
        sender: 'customer',
        body: 'Quero duas frentes de prospecção, uma com foco em clínicas.',
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
    linkedOrderId: 'operacao-002',
    messages: [
      {
        id: 'msg-4',
        sender: 'attendant',
        body: 'Operação conferida. Vamos avisar quando estiver pronta.',
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
    lastMessage: 'Ainda não sei se sigo com SP ou RJ primeiro.',
    linkedOrderId: 'operacao-003',
    messages: [
      {
        id: 'msg-5',
        sender: 'customer',
        body: 'Ainda não sei se sigo com SP ou RJ primeiro.',
        timeLabel: 'agora',
      },
    ],
  },
]
