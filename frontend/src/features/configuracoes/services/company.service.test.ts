import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, requestJson } from '../../../services/crm.service'
import type { CompanyIdentityResource } from '../../auth/company-resource'
import {
  companyErrorMessage,
  companyFieldErrors,
  getCompany,
  removeCompanyLogo,
  serializeCompanyPayload,
  updateCompany,
  uploadCompanyLogo,
} from './company.service'

vi.mock('../../../services/crm.service', async (importOriginal) => {
  const original = await importOriginal<typeof import('../../../services/crm.service')>()

  return {
    ...original,
    requestJson: vi.fn(),
  }
})

const companyResource: CompanyIdentityResource = {
  id: 21,
  name: 'Empresa Prisma Fictícia',
  trade_name: 'Prisma Performance',
  responsible_name: 'Caio Fictício',
  email: 'contato@prisma.example.test',
  phone: '+55 21 90000-2100',
  timezone: 'America/Sao_Paulo',
  logo_url: 'https://crm.example.test/storage/companies/21/logo/logo.webp',
  slug: 'empresa-prisma-ficticia',
  can_manage: true,
}

const form = {
  name: '  Empresa Prisma Fictícia  ',
  tradeName: '  Prisma Performance  ',
  responsibleName: '  Caio Fictício  ',
  email: '  contato@prisma.example.test  ',
  phone: '  +55 21 90000-2100  ',
  timezone: 'America/Sao_Paulo',
}

describe('company service', () => {
  beforeEach(() => {
    vi.mocked(requestJson).mockReset()
  })

  it('carrega a empresa real e adapta os dados persistidos', async () => {
    vi.mocked(requestJson).mockResolvedValue({ company: companyResource })

    const company = await getCompany()

    expect(requestJson).toHaveBeenCalledWith('/api/app/company')
    expect(company).toMatchObject({
      id: '21',
      name: 'Empresa Prisma Fictícia',
      logoUrl: companyResource.logo_url,
      canManage: true,
    })
  })

  it('serializa somente campos editáveis e nunca envia company_id, slug ou role', async () => {
    vi.mocked(requestJson).mockResolvedValue({ message: 'ok', company: companyResource })

    await updateCompany(form)

    const payload = serializeCompanyPayload(form)

    expect(payload).toEqual({
      name: 'Empresa Prisma Fictícia',
      trade_name: 'Prisma Performance',
      responsible_name: 'Caio Fictício',
      email: 'contato@prisma.example.test',
      phone: '+55 21 90000-2100',
      timezone: 'America/Sao_Paulo',
    })
    expect(payload).not.toHaveProperty('company_id')
    expect(payload).not.toHaveProperty('slug')
    expect(payload).not.toHaveProperty('role')
    expect(requestJson).toHaveBeenCalledWith('/api/app/company', {
      body: JSON.stringify(payload),
      method: 'PATCH',
    })
  })

  it('envia logo como FormData e permite remoção idempotente', async () => {
    vi.mocked(requestJson).mockResolvedValue({ message: 'ok', company: companyResource })
    const logo = new File(['imagem-ficticia'], 'logo.png', { type: 'image/png' })

    await uploadCompanyLogo(logo)
    await removeCompanyLogo()

    const uploadOptions = vi.mocked(requestJson).mock.calls[0][1]

    expect(uploadOptions?.method).toBe('POST')
    expect(uploadOptions?.body).toBeInstanceOf(FormData)
    expect((uploadOptions?.body as FormData).get('logo')).toBe(logo)
    expect((uploadOptions?.body as FormData).has('company_id')).toBe(false)
    expect(requestJson).toHaveBeenLastCalledWith('/api/app/company/logo', {
      method: 'DELETE',
    })
  })

  it('mapeia validações e mantém mensagens técnicas fora da interface', () => {
    const validationError = new ApiError('SQLSTATE detalhe interno', 422, {
      errors: {
        name: ['Informe o nome da empresa.'],
        timezone: ['Selecione um fuso válido.'],
        logo: ['A logo deve ter no máximo 2 MB.'],
      },
    })

    expect(companyFieldErrors(validationError)).toEqual({
      name: 'Informe o nome da empresa.',
      timezone: 'Selecione um fuso válido.',
      logo: 'A logo deve ter no máximo 2 MB.',
    })
    expect(companyErrorMessage(validationError, 'save')).toBe('Revise os campos destacados antes de salvar.')
    expect(companyErrorMessage(validationError, 'save')).not.toContain('SQLSTATE')
  })

  it('explica modo leitura e falhas de carregamento sem expor a resposta técnica', () => {
    expect(companyErrorMessage(new ApiError('permission stack', 403), 'save')).toContain('não possui permissão')
    expect(companyErrorMessage(new ApiError('database stack', 500), 'load')).toBe(
      'Não foi possível carregar os dados da empresa. Tente novamente.',
    )
  })
})
