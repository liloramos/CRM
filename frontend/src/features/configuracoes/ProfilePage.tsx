import { useRef, useState, type ChangeEvent, type FormEvent } from 'react'
import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { UserAvatar } from '../../components/layout/UserAvatar'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { useAuth } from '../auth/auth-state'
import type { AuthUser } from '../../types/crm'
import {
  profileErrorMessage,
  profileFieldErrors,
  removeProfileAvatar,
  updateProfile,
  uploadProfileAvatar,
  type ProfileFieldErrors,
  type UpdateProfilePayload,
} from './services/profile.service'
import { validateProfileAvatar } from './utils/profile-avatar'
import './ProfilePage.css'

export function ProfilePage() {
  const { syncUser, user } = useAuth()

  if (!user) {
    return null
  }

  return <ProfileEditor initialUser={user} onUserChange={syncUser} />
}

type ProfileEditorProps = {
  initialUser: AuthUser
  onUserChange: (user: AuthUser) => void
}

function ProfileEditor({ initialUser, onUserChange }: ProfileEditorProps) {
  const [user, setUser] = useState(initialUser)
  const [form, setForm] = useState<UpdateProfilePayload>(() => profileFormFromUser(initialUser))
  const [fieldErrors, setFieldErrors] = useState<ProfileFieldErrors>({})
  const [selectedAvatar, setSelectedAvatar] = useState<File | null>(null)
  const [isSaving, setIsSaving] = useState(false)
  const [avatarAction, setAvatarAction] = useState<'upload' | 'remove' | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const avatarInputRef = useRef<HTMLInputElement>(null)

  const isBusy = isSaving || avatarAction !== null
  const companyName = user.company?.name ?? 'Empresa não vinculada'
  const accessLabel = user.jobTitle?.trim() || formatRole(user.roles[0])

  function updateField(field: keyof UpdateProfilePayload, value: string) {
    setForm((current) => ({ ...current, [field]: value }))
    setFieldErrors((current) => ({ ...current, [field]: undefined }))
    setNotice(null)
    setError(null)
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setIsSaving(true)
    setNotice(null)
    setError(null)
    setFieldErrors({})

    try {
      const updatedUser = await updateProfile(form)
      applyUserUpdate(updatedUser)
      setNotice('Seus dados foram atualizados com sucesso.')
    } catch (requestError) {
      setFieldErrors(profileFieldErrors(requestError))
      setError(profileErrorMessage(requestError, 'profile'))
    } finally {
      setIsSaving(false)
    }
  }

  function handleAvatarSelection(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0] ?? null
    setNotice(null)
    setError(null)
    setFieldErrors((current) => ({ ...current, avatar: undefined }))

    if (!file) {
      setSelectedAvatar(null)
      return
    }

    const validationError = validateProfileAvatar(file)

    if (validationError) {
      setSelectedAvatar(null)
      setFieldErrors((current) => ({ ...current, avatar: validationError }))
      return
    }

    setSelectedAvatar(file)
  }

  async function handleAvatarUpload() {
    if (!selectedAvatar) {
      setFieldErrors((current) => ({ ...current, avatar: 'Selecione uma imagem antes de enviar.' }))
      return
    }

    setAvatarAction('upload')
    setNotice(null)
    setError(null)

    try {
      const updatedUser = await uploadProfileAvatar(selectedAvatar)
      applyUserUpdate(updatedUser)
      clearAvatarSelection()
      setNotice('Foto de perfil atualizada com sucesso.')
    } catch (requestError) {
      setFieldErrors((current) => ({ ...current, ...profileFieldErrors(requestError) }))
      setError(profileErrorMessage(requestError, 'avatar'))
    } finally {
      setAvatarAction(null)
    }
  }

  async function handleAvatarRemove() {
    setAvatarAction('remove')
    setNotice(null)
    setError(null)

    try {
      const updatedUser = await removeProfileAvatar()
      applyUserUpdate(updatedUser)
      clearAvatarSelection()
      setNotice('Foto removida. Suas iniciais serão exibidas como identificação.')
    } catch (requestError) {
      setError(profileErrorMessage(requestError, 'avatar'))
    } finally {
      setAvatarAction(null)
    }
  }

  function applyUserUpdate(updatedUser: AuthUser) {
    setUser(updatedUser)
    setForm(profileFormFromUser(updatedUser))
    setFieldErrors({})
    onUserChange(updatedUser)
  }

  function clearAvatarSelection() {
    setSelectedAvatar(null)
    if (avatarInputRef.current) {
      avatarInputRef.current.value = ''
    }
  }

  return (
    <PageContainer>
      <PageHeader
        description="Mantenha seus dados profissionais e sua identificação atualizados no CRM."
        eyebrow="Conta autenticada"
        title="Meu perfil"
      />

      <div className="profile-page__grid">
        <Card className="profile-identity-card">
          <UserAvatar avatarUrl={user.avatarUrl} name={user.name} size="xl" />
          <div className="profile-identity-card__copy">
            <span className="eyebrow">Identidade profissional</span>
            <h2>{user.name}</h2>
            <p>{accessLabel}</p>
            <small>{companyName}</small>
          </div>

          <div className="profile-avatar-controls">
            <label className="profile-avatar-controls__field" htmlFor="profile-avatar">
              Nova foto
              <input
                accept="image/jpeg,image/png,image/webp"
                aria-describedby={fieldErrors.avatar ? 'profile-avatar-error' : 'profile-avatar-help'}
                aria-invalid={Boolean(fieldErrors.avatar)}
                disabled={isBusy}
                id="profile-avatar"
                onChange={handleAvatarSelection}
                ref={avatarInputRef}
                type="file"
              />
            </label>
            <small id="profile-avatar-help">JPG, PNG ou WebP, até 2 MB.</small>
            {selectedAvatar ? <span className="profile-avatar-controls__filename">{selectedAvatar.name}</span> : null}
            {fieldErrors.avatar ? <span className="profile-field-error" id="profile-avatar-error">{fieldErrors.avatar}</span> : null}
            <div className="profile-avatar-controls__actions">
              <Button disabled={isBusy || !selectedAvatar} icon="camera" onClick={() => void handleAvatarUpload()} variant="primary">
                {avatarAction === 'upload' ? 'Enviando...' : user.avatarUrl ? 'Trocar foto' : 'Enviar foto'}
              </Button>
              {user.avatarUrl ? (
                <Button disabled={isBusy} icon="trash" onClick={() => void handleAvatarRemove()} variant="ghost">
                  {avatarAction === 'remove' ? 'Removendo...' : 'Remover'}
                </Button>
              ) : null}
            </div>
          </div>
        </Card>

        <Card className="profile-form-card">
          <SectionTitle eyebrow="Dados da sessão" title="Informações pessoais" />
          <form className="profile-form" noValidate onSubmit={handleSubmit}>
            <div className="profile-form__grid">
              <ProfileField
                autoComplete="name"
                error={fieldErrors.name}
                label="Nome completo"
                name="name"
                onChange={(value) => updateField('name', value)}
                required
                value={form.name}
              />
              <ProfileField
                autoComplete="email"
                error={fieldErrors.email}
                label="E-mail"
                name="email"
                onChange={(value) => updateField('email', value)}
                required
                type="email"
                value={form.email}
              />
              <ProfileField
                autoComplete="tel"
                error={fieldErrors.phone}
                label="Telefone"
                name="phone"
                onChange={(value) => updateField('phone', value)}
                placeholder="(11) 90000-0000"
                type="tel"
                value={form.phone}
              />
              <ProfileField
                autoComplete="organization-title"
                error={fieldErrors.jobTitle}
                label="Cargo"
                name="job-title"
                onChange={(value) => updateField('jobTitle', value)}
                placeholder="Ex.: Gestor de tráfego"
                value={form.jobTitle}
              />
            </div>

            <div className="profile-form__context" aria-label="Contexto da conta">
              <div>
                <span>Empresa</span>
                <strong>{companyName}</strong>
              </div>
              <div>
                <span>Perfil de acesso</span>
                <strong>{formatRole(user.roles[0])}</strong>
              </div>
            </div>

            <div aria-live="polite" className="profile-form__feedback">
              {notice ? <p className="profile-notice profile-notice--success">{notice}</p> : null}
              {error ? <p className="profile-notice profile-notice--error">{error}</p> : null}
            </div>

            <div className="profile-form__actions">
              <Button disabled={isBusy} icon="check" type="submit" variant="primary">
                {isSaving ? 'Salvando...' : 'Salvar alterações'}
              </Button>
              <Button
                disabled={isBusy}
                onClick={() => {
                  setForm(profileFormFromUser(user))
                  setFieldErrors({})
                  setError(null)
                  setNotice(null)
                }}
                variant="secondary"
              >
                Descartar alterações
              </Button>
            </div>
          </form>
        </Card>
      </div>
    </PageContainer>
  )
}

type ProfileFieldProps = {
  autoComplete: string
  error?: string
  label: string
  name: string
  onChange: (value: string) => void
  placeholder?: string
  required?: boolean
  type?: 'email' | 'tel' | 'text'
  value: string
}

function ProfileField({
  autoComplete,
  error,
  label,
  name,
  onChange,
  placeholder,
  required = false,
  type = 'text',
  value,
}: ProfileFieldProps) {
  const errorId = `${name}-error`

  return (
    <label htmlFor={name}>
      <span>{label}</span>
      <input
        aria-describedby={error ? errorId : undefined}
        aria-invalid={Boolean(error)}
        autoComplete={autoComplete}
        id={name}
        maxLength={name === 'job-title' ? 120 : name === 'phone' ? 30 : 255}
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        required={required}
        type={type}
        value={value}
      />
      {error ? <span className="profile-field-error" id={errorId}>{error}</span> : null}
    </label>
  )
}

function profileFormFromUser(user: AuthUser): UpdateProfilePayload {
  return {
    name: user.name,
    email: user.email,
    phone: user.phone ?? '',
    jobTitle: user.jobTitle ?? '',
  }
}

function formatRole(role?: string): string {
  switch (role) {
    case 'super_admin':
      return 'Super admin'
    case 'admin_gerente':
      return 'Gerência'
    case 'atendente':
      return 'Prospecção'
    case 'cozinha':
      return 'Operação comercial'
    default:
      return 'Acesso padrão'
  }
}
