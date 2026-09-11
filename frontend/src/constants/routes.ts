import type { RouteKey } from '../types/crm'

export type MenuItem = {
  key: RouteKey
  label: string
  icon: string
  badge?: string
  requiredPermission?: string
  managementOnly?: boolean
  group: 'operacao' | 'analise' | 'configuracao'
}

export const menuItems: MenuItem[] = [
  { key: 'dashboard', label: 'Dashboard', icon: 'dashboard', group: 'operacao' },
  { key: 'conversas', label: 'Conversas', icon: 'chat', group: 'operacao' },
  { key: 'caixa', label: 'Caixa', icon: 'cash', group: 'operacao' },
  { key: 'pedidos', label: 'Pedidos', icon: 'orders', group: 'operacao' },
  { key: 'cardapio', label: 'Cardápio', icon: 'menu', group: 'operacao' },
  { key: 'entregas', label: 'Entregas', icon: 'delivery', group: 'operacao' },
  { key: 'pagamentos', label: 'Pagamentos / Pix', icon: 'payment', group: 'operacao', requiredPermission: 'payments.view' },
  { key: 'financeiro', label: 'Financeiro', icon: 'finance', group: 'analise', requiredPermission: 'finance.view', managementOnly: true },
  { key: 'clientes', label: 'Clientes', icon: 'customers', group: 'operacao', requiredPermission: 'customers.view' },
  { key: 'relatorios', label: 'Relatórios', icon: 'reports', group: 'analise', requiredPermission: 'reports.view', managementOnly: true },
  { key: 'configuracoes', label: 'Configurações', icon: 'settings', group: 'configuracao' },
  { key: 'whatsapp', label: 'WhatsApp / API', icon: 'api', group: 'configuracao' },
  { key: 'ia', label: 'IA e Automação', icon: 'ai', group: 'configuracao' },
  { key: 'assistente', label: 'Labia', icon: 'spark', group: 'operacao' },
  { key: 'perfil', label: 'Perfil', icon: 'user', group: 'configuracao' },
  { key: 'empresa', label: 'Empresa', icon: 'settings', group: 'configuracao', managementOnly: true },
]

export const routeLabels: Record<RouteKey, string> = {
  login: 'Login',
  cadastro: 'Cadastro',
  dashboard: 'Dashboard',
  conversas: 'Conversas',
  caixa: 'Caixa',
  pedidos: 'Pedidos',
  cardapio: 'Cardápio',
  entregas: 'Entregas',
  pagamentos: 'Pagamentos / Pix',
  financeiro: 'Financeiro',
  clientes: 'Clientes',
  relatorios: 'Relatórios',
  configuracoes: 'Configurações',
  whatsapp: 'WhatsApp / API',
  ia: 'IA e Automação',
  assistente: 'Labia',
  suporte: 'Central de Ajuda',
  perfil: 'Perfil',
  empresa: 'Empresa',
  'configuracoes-gerais': 'Configurações gerais',
  'configuracoes-usuarios': 'Usuários e permissões',
  'configuracoes-marca': 'Aparência e marca',
  'configuracoes-impressao': 'Impressão',
  'configuracoes-pagamentos': 'Pagamentos',
  'configuracoes-seguranca': 'Segurança',
}
