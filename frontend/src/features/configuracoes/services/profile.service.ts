import { ApiError, requestJson } from '../../../services/crm.service'
import type { AuthUser } from '../../../types/crm'
import { adaptAuthUser, type AuthUserResource } from '../../auth/auth-user'

export type UpdateProfilePayload = {
  name: string
  email: string
  phone: string
  jobTitle: string
}

export type ProfileField = keyof UpdateProfilePayload | 'avatar'
export type ProfileFieldErrors = Partial<Record<ProfileField, string>>

type ProfileResponse = {
  message: string
  user: AuthUserResource
}

export function serializeProfilePayload(payload: UpdateProfilePayload) {
  return {
    name: payload.name.trim(),
    email: payload.email.trim(),
    phone: payload.phone.trim() || null,
    job_title: payload.jobTitle.trim() || null,
  }
}

export async function updateProfile(payload: UpdateProfilePayload): Promise<AuthUser> {
  const response = await requestJson<ProfileResponse>('/api/app/profile', {
    body: JSON.stringify(serializeProfilePayload(payload)),
    method: 'PATCH',
  })

  return adaptAuthUser(response.user)
}

export async function uploadProfileAvatar(file: File): Promise<AuthUser> {
  const body = new FormData()
  body.append('avatar', file)

  const response = await requestJson<ProfileResponse>('/api/app/profile/avatar', {
    body,
    method: 'POST',
  })

  return adaptAuthUser(response.user)
}

export async function removeProfileAvatar(): Promise<AuthUser> {
  const response = await requestJson<ProfileResponse>('/api/app/profile/avatar', {
    method: 'DELETE',
  })

  return adaptAuthUser(response.user)
}

export function profileFieldErrors(error: unknown): ProfileFieldErrors {
  if (!(error instanceof ApiError) || !isRecord(error.details)) {
    return {}
  }

  const errors = error.details.errors

  if (!isRecord(errors)) {
    return {}
  }

  const fields: Array<[string, ProfileField]> = [
    ['name', 'name'],
    ['email', 'email'],
    ['phone', 'phone'],
    ['job_title', 'jobTitle'],
    ['avatar', 'avatar'],
  ]

  return fields.reduce<ProfileFieldErrors>((result, [apiField, formField]) => {
    const messages = errors[apiField]

    if (Array.isArray(messages) && typeof messages[0] === 'string') {
      result[formField] = messages[0]
    }

    return result
  }, {})
}

export function profileErrorMessage(error: unknown, action: 'profile' | 'avatar'): string {
  if (error instanceof ApiError && error.status === 422) {
    return action === 'avatar'
      ? 'A imagem não pôde ser enviada. Revise o formato e o tamanho do arquivo.'
      : 'Revise os campos destacados antes de salvar.'
  }

  if (error instanceof ApiError && error.status === 401) {
    return 'Sua sessão expirou. Entre novamente para continuar.'
  }

  return action === 'avatar'
    ? 'Não foi possível atualizar a foto agora. Tente novamente.'
    : 'Não foi possível salvar o perfil agora. Tente novamente.'
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}
