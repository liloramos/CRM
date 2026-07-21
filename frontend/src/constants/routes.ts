import type { RouteKey } from '../types/crm'

export type MenuItem = {
  key: RouteKey
  label: string
  icon: string
  badge?: string
  group: 'operacao' | 'analise' | 'configuracao'
}

export const menuItems: MenuItem[] = [
  {
    key: 'champs',
    label: 'Prospecção de leads',
    icon: 'reports',
    group: 'operacao',
  },
  {
    key: 'perfil',
    label: 'Perfil',
    icon: 'user',
    group: 'configuracao',
  },
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
  champs: 'Prospecção de leads',
}
