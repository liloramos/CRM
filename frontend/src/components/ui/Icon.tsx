import {
  ArrowRight,
  Bell,
  Bot,
  ChartNoAxesColumnIncreasing,
  Check,
  ChevronLeft,
  ChevronRight,
  ChevronDown,
  ClipboardList,
  Clock,
  CodeXml,
  CreditCard,
  Banknote,
  CirclePlus,
  DollarSign,
  Eye,
  EyeOff,
  LayoutDashboard,
  Lock,
  LogOut,
  Mic,
  Paperclip,
  Pin,
  Mail,
  MessageCircle,
  Pencil,
  Plus,
  Printer,
  RefreshCw,
  Search,
  Smile,
  Sticker,
  Settings,
  Sparkles,
  TriangleAlert,
  Truck,
  User,
  Users,
  Utensils,
  QrCode,
  Wallet,
  WalletCards,
  Volume2,
  VolumeX,
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
  | 'chevron-down'
  | 'close'
  | 'edit'
  | 'clock'
  | 'spark'
  | 'sound'
  | 'sound-off'
  | 'logout'
  | 'mic'
  | 'paperclip'
  | 'pin'
  | 'smile'
  | 'sticker'
  | 'cash'
  | 'plus-circle'
  | 'qr-code'
  | 'wallet'
  | 'wallet-cards'

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
  'chevron-down': ChevronDown,
  close: X,
  edit: Pencil,
  clock: Clock,
  spark: Sparkles,
  sound: Volume2,
  'sound-off': VolumeX,
  logout: LogOut,
  mic: Mic,
  paperclip: Paperclip,
  pin: Pin,
  smile: Smile,
  sticker: Sticker,
  cash: Banknote,
  'plus-circle': CirclePlus,
  'qr-code': QrCode,
  wallet: Wallet,
  'wallet-cards': WalletCards,
}

export function Icon({ name, size = 20, className = '' }: IconProps) {
  const IconComponent = iconComponents[name] ?? Sparkles

  return <IconComponent aria-hidden="true" className={`icon ${className}`.trim()} size={size} strokeWidth={1.85} />
}
