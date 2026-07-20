import {
  ArrowRight,
  Bell,
  Bot,
  ChartNoAxesColumnIncreasing,
  Check,
  ChevronLeft,
  ChevronRight,
  ClipboardList,
  Clock,
  CodeXml,
  CreditCard,
  DollarSign,
  Eye,
  EyeOff,
  LayoutDashboard,
  Lock,
  LogOut,
  Mail,
  MessageCircle,
  Pencil,
  Plus,
  Printer,
  RefreshCw,
  Search,
  Settings,
  Sparkles,
  TriangleAlert,
  Truck,
  User,
  Users,
  Utensils,
  X,
  type LucideIcon,
} from 'lucide-react'

export type IconName =
  | 'dashboard'
  | 'chat'
  | 'orders'
  | 'menu'
  | 'delivery'
  | 'payment'
  | 'finance'
  | 'customers'
  | 'reports'
  | 'settings'
  | 'api'
  | 'ai'
  | 'user'
  | 'search'
  | 'mail'
  | 'lock'
  | 'eye'
  | 'eye-off'
  | 'bell'
  | 'plus'
  | 'printer'
  | 'refresh'
  | 'check'
  | 'alert'
  | 'arrow'
  | 'chevron-left'
  | 'chevron-right'
  | 'close'
  | 'edit'
  | 'clock'
  | 'spark'
  | 'logout'

type IconProps = {
  name: IconName | string
  size?: number
  className?: string
}

const iconComponents: Record<string, LucideIcon> = {
  dashboard: LayoutDashboard,
  chat: MessageCircle,
  orders: ClipboardList,
  menu: Utensils,
  delivery: Truck,
  payment: CreditCard,
  finance: DollarSign,
  customers: Users,
  reports: ChartNoAxesColumnIncreasing,
  settings: Settings,
  api: CodeXml,
  ai: Bot,
  user: User,
  search: Search,
  mail: Mail,
  lock: Lock,
  eye: Eye,
  'eye-off': EyeOff,
  bell: Bell,
  plus: Plus,
  printer: Printer,
  refresh: RefreshCw,
  check: Check,
  alert: TriangleAlert,
  arrow: ArrowRight,
  'chevron-left': ChevronLeft,
  'chevron-right': ChevronRight,
  close: X,
  edit: Pencil,
  clock: Clock,
  spark: Sparkles,
  logout: LogOut,
}

export function Icon({ name, size = 20, className = '' }: IconProps) {
  const IconComponent = iconComponents[name] ?? Sparkles

  return <IconComponent aria-hidden="true" className={`icon ${className}`.trim()} size={size} strokeWidth={1.85} />
}
