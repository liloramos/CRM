import type { CompanyIdentity } from '../../types/crm'
import { CompanyLogo } from './CompanyLogo'

type CompanyBrandProps = {
  company: CompanyIdentity | null
  compact?: boolean
}

export function CompanyBrand({ company, compact = false }: CompanyBrandProps) {
  const companyName = company?.tradeName || company?.name || 'Empresa não vinculada'

  return (
    <div className={compact ? 'company-brand company-brand--compact' : 'company-brand'} aria-label={companyName}>
      <CompanyLogo logoUrl={company?.logoUrl} name={companyName} />
      {compact ? null : (
        <span className="company-brand__copy">
          <strong>{companyName}</strong>
          <small>Workspace comercial</small>
        </span>
      )}
    </div>
  )
}
