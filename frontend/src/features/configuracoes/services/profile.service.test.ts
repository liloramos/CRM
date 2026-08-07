import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, requestJson } from '../../../services/crm.service'
import {
  profileErrorMessage,
  profileFieldErrors,
  removeProfileAvatar,
  serializeProfilePayload,
  updateProfile,
  uploadProfileAvatar,
} from './profile.service'

vi.mock('../../../services/crm.service', async (importOriginal) => {
  const original = await importOriginal<typeof import('../../../services/crm.service')>()

  return {
    ...original,
    requestJson: vi.fn(),
  }
})

const userResource = {
  id: 7,
  name: 'Marcelo Fictício',
  email: 'marcelo.ficticio@example.test',
  phone: '+55 11 90000-0000',
  job_title: 'Gestor de tráfego',
  avatar_url: 'https://crm.example.test/storage/avatars/7/profile.png',
  company: {
    id: 3,
    name: 'Empresa Fictícia',
    slug: 'empresa-ficticia',
  },
  roles: ['admin_gerente'],
  permissions: ['champs.view'],
}

describe('profile service', () => {
  beforeEach(() => {
    vi.mocked(requestJson).mockReset()
  })

  it('normaliza os campos e serializa o contrato da API', () => {
    expect(serializeProfilePayload({
      name: '  Marcelo Fictício  ',
      email: '  marcelo@example.test ',
      phone: '   ',
      jobTitle: '  Gestor de tráfego  ',
    })).toEqual({
      name: 'Marcelo Fictício',
      email: 'marcelo@example.test',
      phone: null,
      job_title: 'Gestor de tráfego',
    })
  })

  it('atualiza o perfil e adapta os dados reais da sessão', async () => {
    vi.mocked(requestJson).mockResolvedValue({ message: 'ok', user: userResource })

    const user = await updateProfile({
      name: 'Marcelo Fictício',
      email: 'marcelo.ficticio@example.test',
      phone: '+55 11 90000-0000',
      jobTitle: 'Gestor de tráfego',
    })

    expect(requestJson).toHaveBeenCalledWith('/api/app/profile', {
      body: JSON.stringify({
        name: 'Marcelo Fictício',
        email: 'marcelo.ficticio@example.test',
        phone: '+55 11 90000-0000',
        job_title: 'Gestor de tráfego',
      }),
      method: 'PATCH',
    })
    expect(user).toMatchObject({
      id: '7',
      jobTitle: 'Gestor de tráfego',
      avatarUrl: userResource.avatar_url,
      company: { id: '3', name: 'Empresa Fictícia' },
    })
  })

  it('envia avatar como FormData e permite removê-lo', async () => {
    vi.mocked(requestJson).mockResolvedValue({ message: 'ok', user: userResource })
    const file = new File(['imagem-ficticia'], 'perfil.png', { type: 'image/png' })

    await uploadProfileAvatar(file)
    await removeProfileAvatar()

    const uploadOptions = vi.mocked(requestJson).mock.calls[0][1]

    expect(uploadOptions?.method).toBe('POST')
    expect(uploadOptions?.body).toBeInstanceOf(FormData)
    expect((uploadOptions?.body as FormData).get('avatar')).toBe(file)
    expect(requestJson).toHaveBeenLastCalledWith('/api/app/profile/avatar', {
      method: 'DELETE',
    })
  })

  it('mapeia erros de validação sem repassar mensagem técnica como aviso geral', () => {
    const error = new ApiError('SQLSTATE segredo-interno', 422, {
      errors: {
        email: ['Este e-mail já está em uso.'],
        job_title: ['O cargo é muito longo.'],
      },
    })

    expect(profileFieldErrors(error)).toEqual({
      email: 'Este e-mail já está em uso.',
      jobTitle: 'O cargo é muito longo.',
    })
    expect(profileErrorMessage(error, 'profile')).toBe('Revise os campos destacados antes de salvar.')
    expect(profileErrorMessage(error, 'profile')).not.toContain('segredo-interno')
  })

  it('usa mensagem amigável quando a API está indisponível', () => {
    const error = new ApiError('stack trace privado', 500)

    expect(profileErrorMessage(error, 'avatar')).toBe('Não foi possível atualizar a foto agora. Tente novamente.')
  })
})
