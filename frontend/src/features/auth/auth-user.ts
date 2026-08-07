import type { AuthUser } from '../../types/crm'
import { adaptCompanyIdentity, type CompanyIdentityResource } from './company-resource'

export type AuthUserResource = {
  id: string | number
  name: string
  email: string
  phone: string | null
  job_title: string | null
  avatar_url: string | null
  company: CompanyIdentityResource | null
  roles: string[]
  permissions: string[]
}

export function adaptAuthUser(resource: AuthUserResource): AuthUser {
  return {
    id: String(resource.id),
    name: resource.name,
    email: resource.email,
    phone: resource.phone,
    jobTitle: resource.job_title,
    avatarUrl: resource.avatar_url,
    company: resource.company ? adaptCompanyIdentity(resource.company) : null,
    roles: resource.roles,
    permissions: resource.permissions,
  }
}
