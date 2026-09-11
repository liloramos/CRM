import type { ReactNode } from 'react'

export type DataTableColumn<T> = {
  key: string
  header: string
  render: (item: T) => ReactNode
  align?: 'left' | 'right'
}

type DataTableProps<T> = {
  columns: DataTableColumn<T>[]
  data: T[]
  getRowKey: (item: T) => string
  isRowSelected?: (item: T) => boolean
  onRowSelect?: (item: T) => void
}

export function DataTable<T>({ columns, data, getRowKey, isRowSelected, onRowSelect }: DataTableProps<T>) {
  return (
    <div className="table-wrap">
      <table className="data-table">
        <thead>
          <tr>
            {columns.map((column) => (
              <th className={column.align === 'right' ? 'is-right' : undefined} key={column.key}>
                {column.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {data.map((item) => (
            <tr
              aria-selected={isRowSelected?.(item)}
              className={isRowSelected?.(item) ? 'is-selected' : undefined}
              key={getRowKey(item)}
              onClick={onRowSelect ? () => onRowSelect(item) : undefined}
              onKeyDown={onRowSelect ? (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                  event.preventDefault()
                  onRowSelect(item)
                }
              } : undefined}
              tabIndex={onRowSelect ? 0 : undefined}
            >
              {columns.map((column) => (
                <td className={column.align === 'right' ? 'is-right' : undefined} key={column.key}>
                  {column.render(item)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
