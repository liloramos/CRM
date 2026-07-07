import type { BadgeTone } from '../types/crm'

export const orderStatusConfig = {
  novo: { label: 'Novo', tone: 'warning' },
  em_conferencia: { label: 'Em conferencia', tone: 'info' },
  aguardando_pagamento: { label: 'Aguardando pagamento', tone: 'warning' },
  comprovante_recebido: { label: 'Comprovante recebido', tone: 'info' },
  pagamento_confirmado: { label: 'Pagamento confirmado', tone: 'success' },
  pronto_para_imprimir: { label: 'Pronto para imprimir', tone: 'brand' },
  impresso: { label: 'Impresso', tone: 'success' },
  em_preparo: { label: 'Em preparo', tone: 'warning' },
  pronto: { label: 'Pronto', tone: 'success' },
  saiu_para_entrega: { label: 'Saiu para entrega', tone: 'info' },
  finalizado: { label: 'Finalizado', tone: 'success' },
  cancelado: { label: 'Cancelado', tone: 'danger' },
  manual: { label: 'Manual', tone: 'manual' },
} satisfies Record<string, { label: string; tone: BadgeTone }>

export const printStatusConfig = {
  aguardando: { label: 'Aguardando impressao', tone: 'warning' },
  imprimindo: { label: 'Imprimindo', tone: 'info' },
  impresso: { label: 'Impresso', tone: 'success' },
  reimpressao: { label: 'Reimpressao solicitada', tone: 'manual' },
  erro: { label: 'Falha na impressao', tone: 'danger' },
} satisfies Record<string, { label: string; tone: BadgeTone }>

export const conversationModeConfig = {
  ia: { label: 'IA assistindo', tone: 'success' },
  manual: { label: 'Atendimento manual', tone: 'manual' },
  atencao: { label: 'Atencao necessaria', tone: 'danger' },
} satisfies Record<string, { label: string; tone: BadgeTone }>
