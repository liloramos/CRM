import { orderStatusConfig, printStatusConfig } from '../../constants/status'
import type { OrderStatus, PrintStatus } from '../../types/crm'
import { Badge } from './Badge'

type StatusBadgeProps =
  | { type: 'order'; status: OrderStatus }
  | { type: 'print'; status: PrintStatus }

export function StatusBadge(props: StatusBadgeProps) {
  const config = props.type === 'order' ? orderStatusConfig[props.status] : printStatusConfig[props.status]

  return <Badge tone={config.tone}>{config.label}</Badge>
}
