import { useState } from 'react'
import { companyInitials } from '../../features/configuracoes/utils/company-identity'

type CompanyLogoProps = {
  className?: string
  logoUrl?: string | null
  name: string
}

export function CompanyLogo({ className = '', logoUrl, name }: CompanyLogoProps) {
  const [failedUrl, setFailedUrl] = useState<string | null>(null)
  const canRenderImage = Boolean(logoUrl) && failedUrl !== logoUrl

  return (
    <span className={`company-logo ${className}`.trim()}>
      {canRenderImage ? (
        <img
          alt={`Logo de ${name}`}
          decoding="async"
          onError={() => setFailedUrl(logoUrl ?? null)}
          src={logoUrl ?? ''}
        />
      ) : (
        <span aria-label={`Identidade de ${name}`} className="company-logo__fallback">
          {companyInitials(name)}
        </span>
      )}
    </span>
  )
}
