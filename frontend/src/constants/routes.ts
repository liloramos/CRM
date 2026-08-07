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
    key: 'saved-leads',
    label: 'Leads salvos',
    icon: 'customers',
    group: 'operacao',
  },
  {
    key: 'perfil',
    label: 'Perfil',
    icon: 'user',
    group: 'configuracao',
  },
  {
    key: 'configuracoes',
    label: 'Empresa',
    icon: 'settings',
    group: 'configuracao',
  },
]

export const routeLabels: Record<RouteKey, string> = {
  login: 'Login',
  cadastro: 'Cadastro',
  dashboard: 'Dashboard',
  conversas: 'Conversas',
  pedidos: 'Operações',
  cardapio: 'Catálogo comercial',
  entregas: 'Entregas',
  pagamentos: 'Pagamentos / Pix',
  financeiro: 'Financeiro',
  clientes: 'Clientes',
  relatorios: 'Relatorios',
  configuracoes: 'Empresa',
  whatsapp: 'WhatsApp / API',
  ia: 'IA e Automacao',
  perfil: 'Perfil',
  champs: 'Prospecção de leads',
  'saved-leads': 'Leads salvos',
}
