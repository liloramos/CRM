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
}

export function DataTable<T>({ columns, data, getRowKey }: DataTableProps<T>) {
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
            <tr key={getRowKey(item)}>
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
