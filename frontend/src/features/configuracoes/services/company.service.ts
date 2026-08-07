import { adaptCompanyIdentity, type CompanyIdentityResource } from '../../auth/company-resource'
import { ApiError, requestJson } from '../../../services/crm.service'
import type { CompanyIdentity } from '../../../types/crm'

export type UpdateCompanyPayload = {
  name: string
  tradeName: string
  responsibleName: string
  email: string
  phone: string
  timezone: string
}

export type CompanyField = keyof UpdateCompanyPayload | 'logo'
export type CompanyFieldErrors = Partial<Record<CompanyField, string>>

type CompanyResponse = {
  message?: string
  company: CompanyIdentityResource
}

export function serializeCompanyPayload(payload: UpdateCompanyPayload) {
  return {
    name: payload.name.trim(),
    trade_name: payload.tradeName.trim() || null,
    responsible_name: payload.responsibleName.trim() || null,
    email: payload.email.trim() || null,
    phone: payload.phone.trim() || null,
    timezone: payload.timezone,
  }
}

export async function getCompany(): Promise<CompanyIdentity> {
  const response = await requestJson<CompanyResponse>('/api/app/company')

  return adaptCompanyIdentity(response.company)
}

export async function updateCompany(payload: UpdateCompanyPayload): Promise<CompanyIdentity> {
  const response = await requestJson<CompanyResponse>('/api/app/company', {
    body: JSON.stringify(serializeCompanyPayload(payload)),
    method: 'PATCH',
  })

  return adaptCompanyIdentity(response.company)
}

export async function uploadCompanyLogo(file: File): Promise<CompanyIdentity> {
  const body = new FormData()
  body.append('logo', file)

  const response = await requestJson<CompanyResponse>('/api/app/company/logo', {
    body,
    method: 'POST',
  })

  return adaptCompanyIdentity(response.company)
}

export async function removeCompanyLogo(): Promise<CompanyIdentity> {
  const response = await requestJson<CompanyResponse>('/api/app/company/logo', {
    method: 'DELETE',
  })

  return adaptCompanyIdentity(response.company)
}

export function companyFieldErrors(error: unknown): CompanyFieldErrors {
  if (!(error instanceof ApiError) || !isRecord(error.details)) {
    return {}
  }

  const errors = error.details.errors

  if (!isRecord(errors)) {
    return {}
  }

  const fields: Array<[string, CompanyField]> = [
    ['name', 'name'],
    ['trade_name', 'tradeName'],
    ['responsible_name', 'responsibleName'],
    ['email', 'email'],
    ['phone', 'phone'],
    ['timezone', 'timezone'],
    ['logo', 'logo'],
  ]

  return fields.reduce<CompanyFieldErrors>((result, [apiField, formField]) => {
    const messages = errors[apiField]

    if (Array.isArray(messages) && typeof messages[0] === 'string') {
      result[formField] = messages[0]
    }

    return result
  }, {})
}

export function companyErrorMessage(error: unknown, action: 'load' | 'save' | 'logo'): string {
  if (error instanceof ApiError && error.status === 403) {
    return 'Seu perfil pode visualizar a empresa, mas não possui permissão para alterá-la.'
  }

  if (error instanceof ApiError && error.status === 422) {
    return action === 'logo'
      ? 'A logo não pôde ser enviada. Revise o formato e o tamanho do arquivo.'
      : 'Revise os campos destacados antes de salvar.'
  }

  if (error instanceof ApiError && error.status === 401) {
    return 'Sua sessão expirou. Entre novamente para continuar.'
  }

  if (action === 'load') {
    return 'Não foi possível carregar os dados da empresa. Tente novamente.'
  }

  return action === 'logo'
    ? 'Não foi possível atualizar a logo agora. Tente novamente.'
    : 'Não foi possível salvar a empresa agora. Tente novamente.'
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}
