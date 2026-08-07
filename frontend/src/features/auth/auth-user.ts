import type { AuthUser } from '../../types/crm'

export type AuthUserResource = {
  id: string | number
  name: string
  email: string
  phone: string | null
  job_title: string | null
  avatar_url: string | null
  company: {
    id: string | number
    name: string
    slug: string
  } | null
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
    company: resource.company
      ? {
          id: String(resource.company.id),
          name: resource.company.name,
          slug: resource.company.slug,
        }
      : null,
    roles: resource.roles,
    permissions: resource.permissions,
  }
}
