import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Card, SectionTitle } from '../../components/ui/Card'
import type { CustomerSummary } from '../../types/crm'
import { formatCurrency, initialsFromName } from '../../utils/formatters'

type CustomersPageProps = {
  customers: CustomerSummary[]
}

export function CustomersPage({ customers }: CustomersPageProps) {
  return (
    <PageContainer>
      <PageHeader
        description="Perfil operacional com preferencias, restricoes, credito e historico sanitizado."
        title="Clientes"
      />
      <div className="customer-grid">
        {customers.map((customer) => (
          <Card className="customer-card" key={customer.id}>
            <div className="customer-card__header">
              <span className="avatar">{initialsFromName(customer.name)}</span>
              <div>
                <h2>{customer.name}</h2>
                <p>{customer.phoneLabel}</p>
              </div>
            </div>
            <div className="tag-row">
              {customer.tags.map((tag) => (
                <Badge key={tag} size="sm" tone="brand">
                  {tag}
                </Badge>
              ))}
            </div>
            <SectionTitle eyebrow="Preferencias" title="Atencoes do atendimento" />
            <ul className="clean-list">
              {customer.preferences.map((preference) => (
                <li key={preference}>{preference}</li>
              ))}
            </ul>
            <strong className="credit-value">Credito: {formatCurrency(customer.creditBalance)}</strong>
          </Card>
        ))}
      </div>
    </PageContainer>
  )
}
