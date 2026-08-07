import {
  useEffect,
  useRef,
  useState,
  type ChangeEvent,
  type FormEvent,
} from 'react'
import { CompanyLogo } from '../../components/layout/CompanyLogo'
import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { ErrorState, LoadingState } from '../../components/ui/States'
import type { CompanyIdentity } from '../../types/crm'
import { useAuth } from '../auth/auth-state'
import {
  companyErrorMessage,
  companyFieldErrors,
  getCompany,
  removeCompanyLogo,
  updateCompany,
  uploadCompanyLogo,
  type CompanyFieldErrors,
  type UpdateCompanyPayload,
} from './services/company.service'
import { canManageCompany, validateCompanyLogo } from './utils/company-identity'
import './CompanyPage.css'

const BRAZILIAN_TIMEZONES = [
  'America/Sao_Paulo',
  'America/Bahia',
  'America/Belem',
  'America/Boa_Vista',
  'America/Cuiaba',
  'America/Fortaleza',
  'America/Manaus',
  'America/Porto_Velho',
  'America/Recife',
  'America/Rio_Branco',
]

const EMPTY_FORM: UpdateCompanyPayload = {
  name: '',
  tradeName: '',
  responsibleName: '',
  email: '',
  phone: '',
  timezone: 'America/Sao_Paulo',
}

export function CompanyPage() {
  const { syncUser, user } = useAuth()
  const [company, setCompany] = useState<CompanyIdentity | null>(user?.company ?? null)
  const [form, setForm] = useState<UpdateCompanyPayload>(() => companyFormFrom(user?.company ?? null))
  const [fieldErrors, setFieldErrors] = useState<CompanyFieldErrors>({})
  const [isLoading, setIsLoading] = useState(!user?.company)
  const [isSaving, setIsSaving] = useState(false)
  const [logoAction, setLogoAction] = useState<'upload' | 'remove' | null>(null)
  const [selectedLogo, setSelectedLogo] = useState<File | null>(null)
  const [previewUrl, setPreviewUrl] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const logoInputRef = useRef<HTMLInputElement>(null)
  const isBusy = isSaving || logoAction !== null
  const canManage = canManageCompany(company)

  useEffect(() => {
    let active = true

    void getCompany()
      .then((loadedCompany) => {
        if (!active) {
          return
        }

        setCompany(loadedCompany)
        setForm(companyFormFrom(loadedCompany))
        setError(null)
      })
      .catch((requestError: unknown) => {
        if (active) {
          setError(companyErrorMessage(requestError, 'load'))
        }
      })
      .finally(() => {
        if (active) {
          setIsLoading(false)
        }
      })

    return () => {
      active = false
    }
  }, [])

  useEffect(() => {
    return () => {
      if (previewUrl) {
        URL.revokeObjectURL(previewUrl)
      }
    }
  }, [previewUrl])

  function updateField(field: keyof UpdateCompanyPayload, value: string) {
    setForm((current) => ({ ...current, [field]: value }))
    setFieldErrors((current) => ({ ...current, [field]: undefined }))
    setNotice(null)
    setError(null)
  }

  async function retryLoad() {
    setIsLoading(true)
    setError(null)

    try {
      const loadedCompany = await getCompany()
      applyCompanyUpdate(loadedCompany)
    } catch (requestError) {
      setError(companyErrorMessage(requestError, 'load'))
    } finally {
      setIsLoading(false)
    }
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    if (!canManage) {
      return
    }

    setIsSaving(true)
    setNotice(null)
    setError(null)
    setFieldErrors({})

    try {
      const updatedCompany = await updateCompany(form)
      applyCompanyUpdate(updatedCompany)
      setNotice('Dados da empresa atualizados com sucesso.')
    } catch (requestError) {
      setFieldErrors(companyFieldErrors(requestError))
      setError(companyErrorMessage(requestError, 'save'))
    } finally {
      setIsSaving(false)
    }
  }

  function handleLogoSelection(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0] ?? null
    clearPreview()
    setNotice(null)
    setError(null)
    setFieldErrors((current) => ({ ...current, logo: undefined }))

    if (!file) {
      setSelectedLogo(null)
      return
    }

    const validationError = validateCompanyLogo(file)

    if (validationError) {
      setSelectedLogo(null)
      setFieldErrors((current) => ({ ...current, logo: validationError }))
      return
    }

    setSelectedLogo(file)
    setPreviewUrl(URL.createObjectURL(file))
  }

  async function handleLogoUpload() {
    if (!selectedLogo || !canManage) {
      if (!selectedLogo) {
        setFieldErrors((current) => ({ ...current, logo: 'Selecione uma imagem antes de enviar.' }))
      }
      return
    }

    setLogoAction('upload')
    setNotice(null)
    setError(null)

    try {
      const updatedCompany = await uploadCompanyLogo(selectedLogo)
      applyCompanyUpdate(updatedCompany)
      clearLogoSelection()
      setNotice('Logo da empresa atualizada com sucesso.')
    } catch (requestError) {
      setFieldErrors((current) => ({ ...current, ...companyFieldErrors(requestError) }))
      setError(companyErrorMessage(requestError, 'logo'))
    } finally {
      setLogoAction(null)
    }
  }

  async function handleLogoRemove() {
    if (!canManage) {
      return
    }

    setLogoAction('remove')
    setNotice(null)
    setError(null)

    try {
      const updatedCompany = await removeCompanyLogo()
      applyCompanyUpdate(updatedCompany)
      clearLogoSelection()
      setNotice('Logo removida. As iniciais da empresa serão usadas como identificação.')
    } catch (requestError) {
      setError(companyErrorMessage(requestError, 'logo'))
    } finally {
      setLogoAction(null)
    }
  }

  function applyCompanyUpdate(updatedCompany: CompanyIdentity) {
    setCompany(updatedCompany)
    setForm(companyFormFrom(updatedCompany))
    setFieldErrors({})

    if (user) {
      syncUser({ ...user, company: updatedCompany })
    }
  }

  function clearPreview() {
    setPreviewUrl((current) => {
      if (current) {
        URL.revokeObjectURL(current)
      }

      return null
    })
  }

  function clearLogoSelection() {
    setSelectedLogo(null)
    clearPreview()
    if (logoInputRef.current) {
      logoInputRef.current.value = ''
    }
  }

  if (isLoading && !company) {
    return (
      <PageContainer>
        <LoadingState description="Sincronizando a identidade do workspace..." title="Carregando empresa" />
      </PageContainer>
    )
  }

  if (!company) {
    return (
      <PageContainer>
        <ErrorState
          actionLabel="Tentar novamente"
          description={error ?? 'Sua conta não possui uma empresa vinculada.'}
          onAction={() => void retryLoad()}
          title="Empresa indisponível"
        />
      </PageContainer>
    )
  }

  const displayName = company.tradeName || company.name

  return (
    <PageContainer>
      <PageHeader
        description="Gerencie a identidade pública exibida para as pessoas deste workspace."
        eyebrow="Configurações do workspace"
        title="Empresa"
      />

      <div className="company-page__grid">
        <Card className="company-identity-card">
          <div className="company-identity-card__preview">
            {previewUrl ? <img alt="Prévia da nova logo" src={previewUrl} /> : (
              <CompanyLogo className="company-logo--xl" logoUrl={company.logoUrl} name={displayName} />
            )}
          </div>
          <div className="company-identity-card__copy">
            <span className="eyebrow">Identidade do tenant</span>
            <h2>{displayName}</h2>
            <p>{company.responsibleName || 'Responsável não informado'}</p>
            <small>O identificador técnico da empresa permanece protegido.</small>
          </div>

          <div className="company-logo-controls">
            <label htmlFor="company-logo">
              Nova logo
              <input
                accept="image/jpeg,image/png,image/webp"
                aria-describedby={fieldErrors.logo ? 'company-logo-error' : 'company-logo-help'}
                aria-invalid={Boolean(fieldErrors.logo)}
                disabled={isBusy || !canManage}
                id="company-logo"
                onChange={handleLogoSelection}
                ref={logoInputRef}
                type="file"
              />
            </label>
            <small id="company-logo-help">JPG, JPEG, PNG ou WebP, até 2 MB.</small>
            {selectedLogo ? <span className="company-logo-controls__filename">{selectedLogo.name}</span> : null}
            {fieldErrors.logo ? <span className="company-field-error" id="company-logo-error">{fieldErrors.logo}</span> : null}
            {canManage ? (
              <div className="company-logo-controls__actions">
                <Button disabled={isBusy || !selectedLogo} icon="camera" onClick={() => void handleLogoUpload()} variant="primary">
                  {logoAction === 'upload' ? 'Enviando...' : company.logoUrl ? 'Trocar logo' : 'Enviar logo'}
                </Button>
                {company.logoUrl ? (
                  <Button disabled={isBusy} icon="trash" onClick={() => void handleLogoRemove()} variant="ghost">
                    {logoAction === 'remove' ? 'Removendo...' : 'Remover logo'}
                  </Button>
                ) : null}
              </div>
            ) : null}
          </div>
        </Card>

        <Card className="company-form-card">
          <SectionTitle eyebrow="Dados públicos" title="Informações da empresa" />
          {!canManage ? (
            <p className="company-readonly-notice">Você possui acesso de leitura. Somente administradores do workspace podem alterar estes dados.</p>
          ) : null}
          <form className="company-form" noValidate onSubmit={handleSubmit}>
            <div className="company-form__grid">
              <CompanyField
                autoComplete="organization"
                disabled={isBusy || !canManage}
                error={fieldErrors.name}
                label="Nome da empresa"
                name="company-name"
                onChange={(value) => updateField('name', value)}
                required
                value={form.name}
              />
              <CompanyField
                autoComplete="organization"
                disabled={isBusy || !canManage}
                error={fieldErrors.tradeName}
                label="Nome comercial"
                name="company-trade-name"
                onChange={(value) => updateField('tradeName', value)}
                value={form.tradeName}
              />
              <CompanyField
                autoComplete="name"
                disabled={isBusy || !canManage}
                error={fieldErrors.responsibleName}
                label="Responsável"
                name="company-responsible-name"
                onChange={(value) => updateField('responsibleName', value)}
                value={form.responsibleName}
              />
              <CompanyField
                autoComplete="email"
                disabled={isBusy || !canManage}
                error={fieldErrors.email}
                label="E-mail comercial"
                name="company-email"
                onChange={(value) => updateField('email', value)}
                type="email"
                value={form.email}
              />
              <CompanyField
                autoComplete="tel"
                disabled={isBusy || !canManage}
                error={fieldErrors.phone}
                label="Telefone"
                name="company-phone"
                onChange={(value) => updateField('phone', value)}
                placeholder="(11) 90000-0000"
                type="tel"
                value={form.phone}
              />
              <label htmlFor="company-timezone">
                <span>Fuso horário</span>
                <select
                  aria-describedby={fieldErrors.timezone ? 'company-timezone-error' : undefined}
                  aria-invalid={Boolean(fieldErrors.timezone)}
                  disabled={isBusy || !canManage}
                  id="company-timezone"
                  onChange={(event) => updateField('timezone', event.target.value)}
                  value={form.timezone}
                >
                  {BRAZILIAN_TIMEZONES.map((timezone) => <option key={timezone} value={timezone}>{timezone}</option>)}
                </select>
                {fieldErrors.timezone ? <span className="company-field-error" id="company-timezone-error">{fieldErrors.timezone}</span> : null}
              </label>
            </div>

            <div aria-live="polite" className="company-form__feedback">
              {notice ? <p className="company-notice company-notice--success">{notice}</p> : null}
              {error ? <p className="company-notice company-notice--error">{error}</p> : null}
            </div>

            {canManage ? (
              <div className="company-form__actions">
                <Button disabled={isBusy} icon="check" type="submit" variant="primary">
                  {isSaving ? 'Salvando...' : 'Salvar empresa'}
                </Button>
                <Button
                  disabled={isBusy}
                  onClick={() => {
                    setForm(companyFormFrom(company))
                    setFieldErrors({})
                    setError(null)
                    setNotice(null)
                  }}
                  variant="secondary"
                >
                  Descartar alterações
                </Button>
              </div>
            ) : null}
          </form>
        </Card>
      </div>
    </PageContainer>
  )
}

type CompanyFieldProps = {
  autoComplete: string
  disabled: boolean
  error?: string
  label: string
  name: string
  onChange: (value: string) => void
  placeholder?: string
  required?: boolean
  type?: 'email' | 'tel' | 'text'
  value: string
}

function CompanyField({
  autoComplete,
  disabled,
  error,
  label,
  name,
  onChange,
  placeholder,
  required = false,
  type = 'text',
  value,
}: CompanyFieldProps) {
  const errorId = `${name}-error`

  return (
    <label htmlFor={name}>
      <span>{label}</span>
      <input
        aria-describedby={error ? errorId : undefined}
        aria-invalid={Boolean(error)}
        autoComplete={autoComplete}
        disabled={disabled}
        id={name}
        maxLength={name === 'company-phone' ? 30 : 255}
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        required={required}
        type={type}
        value={value}
      />
      {error ? <span className="company-field-error" id={errorId}>{error}</span> : null}
    </label>
  )
}

function companyFormFrom(company: CompanyIdentity | null): UpdateCompanyPayload {
  if (!company) {
    return EMPTY_FORM
  }

  return {
    name: company.name,
    tradeName: company.tradeName ?? '',
    responsibleName: company.responsibleName ?? '',
    email: company.email ?? '',
    phone: company.phone ?? '',
    timezone: company.timezone,
  }
}
