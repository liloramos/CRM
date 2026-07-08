import type { ExpenseEntry } from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

type ExpenseListProps = {
  expenses: ExpenseEntry[]
}

export function ExpenseList({ expenses }: ExpenseListProps) {
  return (
    <div className="expense-list">
      {expenses.map((expense) => (
        <div className="expense-item" key={expense.id}>
          <div>
            <strong>{expense.label}</strong>
            <span>{expense.category}</span>
            <p>{expense.notes}</p>
          </div>
          <div className="expense-item__value">
            <strong>{formatCurrency(expense.amount)}</strong>
            <span>{expense.createdLabel}</span>
          </div>
        </div>
      ))}
    </div>
  )
}
