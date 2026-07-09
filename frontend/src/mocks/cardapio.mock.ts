import type { Product } from '../types/crm'

export const productsMock: Product[] = [
  {
    id: 'produto-001',
    category: 'Marmitas',
    name: 'Marmita executiva demo',
    description: 'Base para montagem operacional com observacoes por item.',
    price: 24,
    available: true,
    tags: ['mais pedido', 'permite adicionais'],
    options: [],
  },
  {
    id: 'produto-002',
    category: 'Marmitas',
    name: 'Marmita tradicional demo',
    description: 'Produto de exemplo seguro para fallback visual.',
    price: 18,
    available: true,
    tags: ['almoco'],
    options: [],
  },
  {
    id: 'produto-003',
    category: 'Bebidas',
    name: 'Bebida sem acucar demo',
    description: 'Bebida de fallback para validar disponibilidade.',
    price: 6,
    available: true,
    tags: ['bebida'],
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
