import { Badge } from '../../components/ui/Badge'
import type { PaymentMethodSummary } from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

type PaymentMethodBreakdownProps = {
  methods: PaymentMethodSummary[]
}

export function PaymentMethodBreakdown({ methods }: PaymentMethodBreakdownProps) {
  return (
    <div className="payment-breakdown">
      {methods.map((method) => (
        <div className="payment-method-row" key={method.method}>
          <div className="payment-method-row__top">
            <div>
              <strong>{method.label}</strong>
              <span>{method.count} movimento(s)</span>
            </div>
            <Badge tone={method.tone} size="sm">
              {formatCurrency(method.amount)}
            </Badge>
          </div>
          <div className="payment-bar" aria-label={`${method.label}: ${method.percentage}%`}>
            <span style={{ width: `${Math.min(method.percentage, 100)}%` }} />
          </div>
        </div>
      ))}
    </div>
  )
}
