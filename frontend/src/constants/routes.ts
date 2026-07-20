import type { RouteKey } from '../types/crm'

export type MenuItem = {
  key: RouteKey
  label: string
  icon: string
  badge?: string
  group: 'operacao' | 'analise' | 'configuracao'
}

export const menuItems: MenuItem[] = [
  { key: 'dashboard', label: 'Dashboard', icon: 'dashboard', group: 'operacao' },
  { key: 'conversas', label: 'Conversas', icon: 'chat', group: 'operacao' },
  { key: 'pedidos', label: 'Pedidos', icon: 'orders', group: 'operacao' },
  { key: 'cardapio', label: 'Cardapio', icon: 'menu', group: 'operacao' },
  { key: 'entregas', label: 'Entregas', icon: 'delivery', group: 'operacao' },
  { key: 'pagamentos', label: 'Pagamentos / Pix', icon: 'payment', group: 'operacao' },
  { key: 'financeiro', label: 'Financeiro', icon: 'finance', group: 'analise' },
  { key: 'clientes', label: 'Clientes', icon: 'customers', group: 'operacao' },
  { key: 'relatorios', label: 'Relatorios', icon: 'reports', group: 'analise' },
  { key: 'configuracoes', label: 'Configuracoes', icon: 'settings', group: 'configuracao' },
  { key: 'whatsapp', label: 'WhatsApp / API', icon: 'api', group: 'configuracao' },
  { key: 'ia', label: 'IA e Automacao', icon: 'ai', group: 'configuracao' },
  { key: 'perfil', label: 'Perfil', icon: 'user', group: 'configuracao' },
]

export const routeLabels: Record<RouteKey, string> = {
  login: 'Login',
  cadastro: 'Cadastro',
  dashboard: 'Dashboard',
  conversas: 'Conversas',
  pedidos: 'Pedidos',
  cardapio: 'Cardapio',
  entregas: 'Entregas',
  pagamentos: 'Pagamentos / Pix',
  financeiro: 'Financeiro',
  clientes: 'Clientes',
  relatorios: 'Relatorios',
  configuracoes: 'Configuracoes',
  whatsapp: 'WhatsApp / API',
  ia: 'IA e Automacao',
  perfil: 'Perfil',
}
