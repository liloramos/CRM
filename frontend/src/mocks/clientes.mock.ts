import type { CustomerSummary } from '../types/crm'

export const customersMock: CustomerSummary[] = [
  {
    id: 'cliente-aurora',
    name: 'Cliente Aurora',
    phoneLabel: '(00) 00000-0000',
    tags: ['recorrente', 'credito ativo'],
    creditBalance: 6,
    notes: ['Prefere confirmacao humana quando pede igual ao anterior.'],
    preferences: ['Pouco arroz', 'Sem fritura', 'Bebida zero quando solicitado'],
  },
  {
    id: 'cliente-brisa',
    name: 'Cliente Brisa',
    phoneLabel: '(00) 00000-0000',
    tags: ['retirada por terceiro'],
    creditBalance: 0,
    notes: ['Costuma enviar pedido em mensagens separadas.'],
    preferences: ['Separar marmita por beneficiario'],
  },
  {
    id: 'cliente-cerrado',
    name: 'Cliente Cerrado',
    phoneLabel: '(00) 00000-0000',
    tags: ['novo'],
    creditBalance: 0,
    notes: ['Sem observacoes fixas.'],
    preferences: ['Confirmar retirada ou entrega'],
  },
]
