import type { Product } from '../types/crm'

export const productsMock: Product[] = [
  {
    id: 'produto-001',
    category: 'Serviços',
    name: 'Diagnóstico executivo demo',
    description: 'Base para montagem operacional com observações por item.',
    price: 24,
    available: true,
    tags: ['maior demanda', 'permite extras'],
    options: [],
  },
  {
    id: 'produto-002',
    category: 'Serviços',
    name: 'Plano tradicional demo',
    description: 'Item de exemplo seguro para fallback visual.',
    price: 18,
    available: true,
    tags: ['prospecção'],
    options: [],
  },
  {
    id: 'produto-003',
    category: 'Canais',
    name: 'Canal complementar demo',
    description: 'Canal de fallback para validar disponibilidade.',
    price: 6,
    available: true,
    tags: ['canal'],
    options: [],
  },
  {
    id: 'produto-004',
    category: 'Adicionais',
    name: 'Adicional temporariamente indisponivel',
    description: 'Exemplo para modal de item indisponivel.',
    price: 3,
    available: false,
    tags: ['indisponivel'],
    options: [],
  },
]
