import type { ReactNode } from 'react'

type PageContainerProps = {
  children: ReactNode
  density?: 'normal' | 'wide'
}

export function PageContainer({ children, density = 'normal' }: PageContainerProps) {
  return <main className={`page-container page-container--${density}`}>{children}</main>
}
