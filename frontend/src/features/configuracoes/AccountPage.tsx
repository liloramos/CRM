import { useCallback, useEffect, useRef, useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { ErrorState, LoadingState } from '../../components/ui/States'
import { UserAvatar } from '../../components/ui/UserAvatar'
import {
  getAccountCompany,
  getAccountProfile,
  removeAccountAvatar,
  saveAccountCompany,
  saveAccountProfile,
  uploadAccountAvatar,
  uploadAccountCompanyLogo,
  type AccountCompany,
  type AccountProfile,
} from '../../services/crm.service'

type Data = AccountProfile | AccountCompany
type Form = Record<string, string>

const fields = (company: boolean): Array<[string, string]> => company
  ? [
      ['name', 'Nome da empresa'],
      ['displayName', 'Nome comercial'],
      ['responsibleName', 'Responsável'],
      ['contactEmail', 'E-mail comercial'],
      ['contactPhone', 'Telefone'],
      ['timezone', 'Fuso horário'],
      ['addressLine', 'Endereço'],
      ['addressNumber', 'Número'],
      ['district', 'Bairro'],
      ['city', 'Cidade'],
      ['state', 'UF'],
      ['postalCode', 'CEP'],
    ]
  : [
      ['name', 'Nome completo'],
      ['email', 'E-mail'],
      ['phone', 'Telefone'],
      ['jobTitle', 'Cargo'],
    ]

const asForm = (data: Data) => Object.entries(data).reduce<Form>(
  (form, [key, value]) => typeof value === 'string' ? { ...form, [key]: value } : form,
  {},
)

type AccountPageProps = {
  company?: boolean
  onProfileChanged?: () => Promise<void> | void
}

export function AccountPage({ company = false, onProfileChanged }: AccountPageProps) {
  const [data, setData] = useState<Data | null>(null)
  const [form, setForm] = useState<Form>({})
  const [file, setFile] = useState<File | null>(null)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const fileInput = useRef<HTMLInputElement>(null)
  const fetchData = useCallback(() => company ? getAccountCompany() : getAccountProfile(), [company])
  const apply = useCallback((value: Data) => {
    setData(value)
    setForm(asForm(value))
    setFile(null)
  }, [])

  useEffect(() => {
    let active = true
    void fetchData()
      .then((value) => { if (active) apply(value) })
      .catch(() => { if (active) setError('Não foi possível carregar os dados.') })

    return () => { active = false }
  }, [apply, fetchData])

  if (!data) {
    return error
      ? <ErrorState actionLabel="Tentar novamente" description={error} onAction={() => void fetchData().then(apply)} title="Dados indisponíveis" />
      : <LoadingState description="Carregando informações." title="Aguarde" />
  }

  const profile = data as AccountProfile
  const organization = data as AccountCompany
  const title = company ? organization.displayName || organization.name : profile.name
  const image = company ? organization.logoUrl : profile.avatarUrl
  const update = (key: string, value: string) => setForm((current) => ({ ...current, [key]: value }))

  async function notifyProfileChanged() {
    if (!company) await onProfileChanged?.()
  }

  async function save() {
    setBusy(true)
    setError('')
    setMessage('')

    try {
      apply(company ? await saveAccountCompany(form) : await saveAccountProfile(form))
      await notifyProfileChanged()
      setMessage('Alterações salvas com sucesso.')
    } catch {
      setError('Não foi possível salvar. Revise os campos e tente novamente.')
    } finally {
      setBusy(false)
    }
  }

  async function upload() {
    if (!file) return
    setBusy(true)
    setError('')
    setMessage('')

    try {
      apply(company ? await uploadAccountCompanyLogo(file) : await uploadAccountAvatar(file))
      await notifyProfileChanged()
      setMessage('Imagem atualizada com sucesso.')
    } catch {
      setError('Não foi possível enviar a imagem.')
    } finally {
      setBusy(false)
    }
  }

  async function removeAvatar() {
    if (company) return
    setBusy(true)
    setError('')
    setMessage('')

    try {
      apply(await removeAccountAvatar())
      await notifyProfileChanged()
      setMessage('Foto removida. As iniciais voltaram a ser exibidas.')
    } catch {
      setError('Não foi possível remover a foto.')
    } finally {
      setBusy(false)
    }
  }

  const discard = () => void fetchData()
    .then(apply)
    .catch(() => setError('Não foi possível restaurar os dados.'))

  return (
    <main className="account-page">
      <header className="account-header">
        <h1>{company ? 'Empresa' : 'Perfil'}</h1>
        <p>{company ? 'Gerencie a identidade e os dados operacionais do restaurante.' : 'Gerencie suas informações pessoais e a segurança da sua conta.'}</p>
      </header>
      <div className="account-grid">
        <Card className="account-identity">
          <SectionTitle title="Identidade" />
          <div className="profile-summary">
            {company
              ? image
                ? <img alt={title} className="avatar avatar--lg" src={image} />
                : <UserAvatar className="avatar--lg" name={title} />
              : <UserAvatar avatarUrl={image} className="avatar--lg" name={title} />}
            <div>
              <h2>{title}</h2>
              <p>{company ? organization.responsibleName || 'Responsável não informado' : profile.jobTitle || 'Cargo não informado'}</p>
              <p>{company ? organization.name : profile.roles.map(formatRole).join(', ')}</p>
            </div>
          </div>
          {!company ? (
            <div className="account-readonly">
              <span>Empresa</span>
              <strong>{profile.companyName || 'Não informada'}</strong>
              <span>Perfil de acesso</span>
              <strong>{profile.roles.map(formatRole).join(', ')}</strong>
            </div>
          ) : null}
          <input
            accept="image/png,image/jpeg,image/webp"
            className="account-file"
            onChange={(event) => setFile(event.target.files?.[0] ?? null)}
            ref={fileInput}
            type="file"
          />
          <div className="account-upload-actions">
            <Button disabled={busy} onClick={() => fileInput.current?.click()} variant="secondary">{company ? 'Alterar logo' : 'Alterar foto'}</Button>
            {!company && profile.avatarUrl ? <Button disabled={busy} onClick={() => void removeAvatar()} variant="ghost">Remover foto</Button> : null}
            {file ? (
              <>
                <small>{file.name}</small>
                <Button disabled={busy} onClick={() => void upload()} variant="primary">Enviar</Button>
                <Button disabled={busy} onClick={() => {
                  setFile(null)
                  if (fileInput.current) fileInput.current.value = ''
                }} variant="ghost">Cancelar</Button>
              </>
            ) : null}
          </div>
        </Card>
        <div className="account-content">
          <Card>
            <SectionTitle title={company ? 'Informações da empresa' : 'Informações pessoais'} />
            {message ? <p className="payments-feedback">{message}</p> : null}
            {error ? <p className="payments-feedback">{error}</p> : null}
            <div className="account-form">
              {fields(company).map(([key, label]) => (
                <label key={key}>{label}<input onChange={(event) => update(key, event.target.value)} value={form[key] ?? ''} /></label>
              ))}
            </div>
            <div className="inline-actions">
              <Button disabled={busy} onClick={() => void save()} variant="primary">Salvar alterações</Button>
              <Button disabled={busy} onClick={discard} variant="secondary">Descartar</Button>
            </div>
          </Card>
          {!company ? (
            <Card>
              <SectionTitle title="Segurança" />
              <div className="account-form account-security">
                <label>Senha atual<input onChange={(event) => update('current_password', event.target.value)} type="password" /></label>
                <label>Nova senha<input onChange={(event) => update('password', event.target.value)} type="password" /></label>
                <label>Confirmar nova senha<input onChange={(event) => update('password_confirmation', event.target.value)} type="password" /></label>
              </div>
              <Button disabled={busy} onClick={() => void save()} variant="secondary">Alterar senha</Button>
            </Card>
          ) : null}
        </div>
      </div>
    </main>
  )
}

function formatRole(role: string) {
  if (role === 'super_admin') return 'DEV'
  if (role === 'admin_gerente') return 'Gerência'
  if (role === 'atendente') return 'Atendente'
  return role
}
