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
  | 'check'
  | 'alert'
  | 'arrow'
  | 'close'
  | 'edit'
  | 'clock'
  | 'spark'

type IconProps = {
  name: IconName | string
  size?: number
  className?: string
}

const iconPaths: Record<string, string[]> = {
  dashboard: ['M4 4h7v7H4z', 'M13 4h7v4h-7z', 'M13 10h7v10h-7z', 'M4 13h7v7H4z'],
  chat: ['M5 6h14v9H9l-4 4z', 'M8 9h7', 'M8 12h5'],
  orders: ['M6 5h12v15H6z', 'M9 5V3h6v2', 'M9 10h6', 'M9 14h6'],
  menu: ['M5 7h14', 'M5 12h14', 'M5 17h14'],
  delivery: ['M4 7h10v9H4z', 'M14 10h3l3 3v3h-6z', 'M7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4', 'M17 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4'],
  payment: ['M4 7h16v10H4z', 'M4 10h16', 'M8 14h3'],
  finance: ['M12 3v18', 'M16 7.5c-.8-1.2-2.1-1.8-3.8-1.8-2 0-3.5.9-3.5 2.5 0 3.8 7.3 1.6 7.3 5.6 0 1.7-1.5 2.7-3.8 2.7-1.9 0-3.4-.6-4.4-1.9'],
  customers: ['M8 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8', 'M2 21a6 6 0 0 1 12 0', 'M17 10a3 3 0 1 0 0-6', 'M18 21a5 5 0 0 0-3-4.6'],
  reports: ['M5 20V4', 'M9 20v-8', 'M13 20V8', 'M17 20v-5', 'M3 20h18'],
  settings: ['M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8', 'M4 12h2', 'M18 12h2', 'M12 4v2', 'M12 18v2', 'M6.3 6.3l1.4 1.4', 'M16.3 16.3l1.4 1.4', 'M17.7 6.3l-1.4 1.4', 'M7.7 16.3l-1.4 1.4'],
  api: ['M8 9 5 12l3 3', 'M16 9l3 3-3 3', 'M14 5l-4 14'],
  ai: ['M12 3l2.2 5.1 5.3.5-4 3.5 1.2 5.2L12 14.5 7.3 17.3l1.2-5.2-4-3.5 5.3-.5z'],
  user: ['M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8', 'M4 21a8 8 0 0 1 16 0'],
  search: ['M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14', 'M16 16l4 4'],
  mail: ['M4 6h16v12H4z', 'M4 7l8 6 8-6'],
  lock: ['M7 11V8a5 5 0 0 1 10 0v3', 'M6 11h12v9H6z', 'M12 15v2'],
  eye: ['M3 12s3.3-6 9-6 9 6 9 6-3.3 6-9 6-9-6-9-6z', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6'],
  'eye-off': ['M4 4l16 16', 'M9.8 9.8A3 3 0 0 0 14.2 14.2', 'M6.7 6.7C4.4 8.2 3 12 3 12s3.3 6 9 6c1.7 0 3.1-.4 4.2-1', 'M10.8 6.1c.4-.1.8-.1 1.2-.1 5.7 0 9 6 9 6a15.5 15.5 0 0 1-2.1 2.9'],
  bell: ['M6 9a6 6 0 0 1 12 0c0 7 3 6 3 8H3c0-2 3-1 3-8', 'M10 20h4'],
  plus: ['M12 5v14', 'M5 12h14'],
  printer: ['M7 8V4h10v4', 'M6 17H4v-7h16v7h-2', 'M7 14h10v6H7z'],
  check: ['M5 12l4 4L19 6'],
  alert: ['M12 4l9 16H3z', 'M12 9v4', 'M12 17h.01'],
  arrow: ['M5 12h14', 'M13 6l6 6-6 6'],
  close: ['M6 6l12 12', 'M18 6 6 18'],
  edit: ['M5 19h4L19 9l-4-4L5 15z', 'M13 7l4 4'],
  clock: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18', 'M12 7v5l3 2'],
  spark: ['M12 3l1.4 5.1L18 10l-4.6 1.9L12 17l-1.4-5.1L6 10l4.6-1.9z'],
}

export function Icon({ name, size = 20, className = '' }: IconProps) {
  const paths = iconPaths[name] ?? iconPaths.spark

  return (
    <svg
      aria-hidden="true"
      className={`icon ${className}`}
      fill="none"
      height={size}
      viewBox="0 0 24 24"
      width={size}
    >
      {paths.map((path) => (
        <path
          d={path}
          key={path}
          stroke="currentColor"
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth="1.7"
        />
      ))}
    </svg>
  )
}
