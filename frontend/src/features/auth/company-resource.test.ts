import { describe, expect, it } from 'vitest'
import { adaptAuthUser } from './auth-user'
import { adaptCompanyIdentity, type CompanyIdentityResource } from './company-resource'

const companyResource: CompanyIdentityResource = {
  id: 14,
  name: 'Empresa Horizonte Fictícia',
  trade_name: 'Horizonte Performance',
  responsible_name: 'Ana Fictícia',
  email: 'contato@horizonte.example.test',
  phone: '+55 11 90000-1400',
  timezone: 'America/Sao_Paulo',
  logo_url: 'https://crm.example.test/storage/companies/14/logo/logo.png',
  slug: 'empresa-horizonte-ficticia',
  can_manage: true,
}

describe('company identity resource', () => {
  it('adapta a identidade pública sem expor caminho interno', () => {
    const company = adaptCompanyIdentity(companyResource)

    expect(company).toEqual({
      id: '14',
      name: 'Empresa Horizonte Fictícia',
      tradeName: 'Horizonte Performance',
      responsibleName: 'Ana Fictícia',
      email: 'contato@horizonte.example.test',
      phone: '+55 11 90000-1400',
      timezone: 'America/Sao_Paulo',
      logoUrl: companyResource.logo_url,
      slug: 'empresa-horizonte-ficticia',
      canManage: true,
    })
    expect(company).not.toHaveProperty('logo_path')
  })

  it('mantém avatar pessoal e logo da empresa em campos distintos na sessão', () => {
    const user = adaptAuthUser({
      id: 8,
      name: 'Usuária Fictícia',
      email: 'usuario@example.test',
      phone: null,
      job_title: 'Gestora',
      avatar_url: 'https://crm.example.test/storage/avatars/8/avatar.png',
      company: companyResource,
      roles: ['admin_gerente'],
      permissions: ['settings.manage'],
    })

    expect(user.avatarUrl).toContain('/avatars/8/')
    expect(user.company?.logoUrl).toContain('/companies/14/logo/')
    expect(user.company?.name).toBe('Empresa Horizonte Fictícia')
  })
})
