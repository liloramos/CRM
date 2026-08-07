import { describe, expect, it } from 'vitest'
import type { CompanyIdentity } from '../../../types/crm'
import {
  canManageCompany,
  companyInitials,
  MAX_COMPANY_LOGO_SIZE_BYTES,
  validateCompanyLogo,
} from './company-identity'

describe('company identity utilities', () => {
  it.each([
    ['logo.jpg', 'image/jpeg'],
    ['logo.jpeg', 'image/jpeg'],
    ['logo.png', 'image/png'],
    ['logo.webp', 'image/webp'],
  ])('aceita %s no limite de dois megabytes', (name, type) => {
    const file = new File(['imagem'], name, { type })

    expect(validateCompanyLogo(file)).toBeNull()
  })

  it('rejeita SVG, texto renomeado e arquivo acima de dois megabytes', () => {
    const svg = new File(['<svg></svg>'], 'logo.svg', { type: 'image/svg+xml' })
    const renamedText = new File(['texto'], 'logo.jpg', { type: 'text/plain' })

    expect(validateCompanyLogo(svg)).toContain('JPG')
    expect(validateCompanyLogo(renamedText)).toContain('JPG')
    expect(validateCompanyLogo({ size: MAX_COMPANY_LOGO_SIZE_BYTES + 1, type: 'image/png' })).toContain('2 MB')
  })

  it('gera fallback pela identidade real e respeita a permissão fornecida pelo backend', () => {
    const company = {
      id: '2',
      name: 'Norte Estratégia',
      tradeName: null,
      responsibleName: null,
      email: null,
      phone: null,
      timezone: 'America/Sao_Paulo',
      logoUrl: null,
      slug: 'norte-estrategia',
      canManage: false,
    } satisfies CompanyIdentity

    expect(companyInitials(company.name)).toBe('NE')
    expect(companyInitials('')).toBe('EM')
    expect(canManageCompany(company)).toBe(false)
    expect(canManageCompany({ ...company, canManage: true })).toBe(true)
  })
})
