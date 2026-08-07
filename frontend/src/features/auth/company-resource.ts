import type { CompanyIdentity } from '../../types/crm'

export type CompanyIdentityResource = {
  id: string | number
  name: string
  trade_name: string | null
  responsible_name: string | null
  email: string | null
  phone: string | null
  timezone: string
  logo_url: string | null
  slug: string
  can_manage: boolean
}

export function adaptCompanyIdentity(resource: CompanyIdentityResource): CompanyIdentity {
  return {
    id: String(resource.id),
    name: resource.name,
    tradeName: resource.trade_name,
    responsibleName: resource.responsible_name,
    email: resource.email,
    phone: resource.phone,
    timezone: resource.timezone,
    logoUrl: resource.logo_url,
    slug: resource.slug,
    canManage: resource.can_manage,
  }
}
