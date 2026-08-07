import type { CompanyIdentity } from '../../../types/crm'

export const MAX_COMPANY_LOGO_SIZE_BYTES = 2 * 1024 * 1024

const ACCEPTED_COMPANY_LOGO_TYPES = ['image/jpeg', 'image/png', 'image/webp']

export function validateCompanyLogo(file: Pick<File, 'size' | 'type'>): string | null {
  if (!ACCEPTED_COMPANY_LOGO_TYPES.includes(file.type)) {
    return 'Use uma imagem JPG, PNG ou WebP.'
  }

  if (file.size > MAX_COMPANY_LOGO_SIZE_BYTES) {
    return 'A logo deve ter no máximo 2 MB.'
  }

  return null
}

export function companyInitials(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean)

  if (words.length === 0) {
    return 'EM'
  }

  return words.slice(0, 2).map((word) => word.charAt(0).toUpperCase()).join('')
}

export function canManageCompany(company: CompanyIdentity | null | undefined): boolean {
  return company?.canManage === true
}
