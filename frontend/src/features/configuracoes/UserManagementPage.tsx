import { useCallback, useEffect, useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { DataTable, type DataTableColumn } from '../../components/ui/DataTable'
import { Modal } from '../../components/ui/Modal'
import { SelectField } from '../../components/ui/SelectField'
import { EmptyState, ErrorState, LoadingState } from '../../components/ui/States'
import { UserAvatar } from '../../components/ui/UserAvatar'
import {
  createManagedUser,
  getManagedUsers,
  removeManagedUserAvatar,
  updateManagedUser,
  uploadManagedUserAvatar,
  type ManagedUser,
  type ManagedUserPayload,
  type UserManagementData,
} from '../../services/crm.service'

const labels: Record<string, string> = {
  super_admin: 'DEV',
  admin_gerente: 'Gerência',
  atendente: 'Atendente',
}

type UserForm = ManagedUserPayload

export function UserManagementPage({ onBack, onUsersChanged }: { onBack: () => void; onUsersChanged: () => void }) {
  const [data, setData] = useState<UserManagementData | null>(null)
  const [selected, setSelected] = useState<ManagedUser | null>(null)
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<UserForm>(emptyUserForm())
  const [avatarFile, setAvatarFile] = useState<File | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  const load = useCallback(() => void getManagedUsers()
    .then(setData)
    .catch(() => setError('Não foi possível carregar os usuários.')), [])

  useEffect(() => { load() }, [load])

  function start(user?: ManagedUser) {
    setSelected(user ?? null)
    setAvatarFile(null)
    setError('')
    setForm(user ? {
      name: user.name,
      email: user.email,
      phone: user.phone ?? '',
      job_title: user.jobTitle ?? '',
      role: user.roles[0] ?? '',
      permissions: user.permissions,
      can_be_seller: user.canBeSeller,
      is_active: user.isActive,
    } : defaultUserForm(data))
    setOpen(true)
  }

  function selectRole(roleName: string) {
    const role = data?.available_roles.find((candidate) => candidate.name === roleName)
    setForm((current) => ({
      ...current,
      role: roleName,
      permissions: role?.permissions ?? [],
    }))
  }

  function togglePermission(permission: string, checked: boolean) {
    setForm((current) => ({
      ...current,
      permissions: checked
        ? [...new Set([...current.permissions, permission])]
        : current.permissions.filter((name) => name !== permission),
    }))
  }

  function mergeUser(result: ManagedUser) {
    setData((current) => current ? {
      ...current,
      users: current.users.some((user) => user.id === result.id)
        ? current.users.map((user) => user.id === result.id ? result : user)
        : [...current.users, result].sort((left, right) => left.name.localeCompare(right.name)),
    } : current)
  }

  async function submit() {
    if (!form.name || !form.email || !form.role || (!selected && (!form.password || form.password !== form.password_confirmation))) {
      setError('Preencha os campos obrigatórios e confirme a senha.')
      return
    }

    setBusy(true)
    setError('')

    try {
      let result = selected
        ? await updateManagedUser(selected.id, form)
        : await createManagedUser(form)

      if (avatarFile) result = await uploadManagedUserAvatar(result.id, avatarFile)

      mergeUser(result)
      setMessage(selected ? 'Usuário atualizado com sucesso.' : 'Usuário criado com sucesso.')
      setOpen(false)
      onUsersChanged()
    } catch {
      setError('Não foi possível salvar. Revise os dados, o perfil e o teto de permissões.')
    } finally {
      setBusy(false)
    }
  }

  async function removeAvatar() {
    if (!selected) return
    setBusy(true)
    setError('')

    try {
      const result = await removeManagedUserAvatar(selected.id)
      mergeUser(result)
      setSelected(result)
      setMessage('Foto removida. As iniciais voltaram a ser exibidas.')
    } catch {
      setError('Não foi possível remover a foto.')
    } finally {
      setBusy(false)
    }
  }

  if (!data) {
    return error
      ? <ErrorState actionLabel="Tentar novamente" description={error} onAction={load} title="Usuários indisponíveis" />
      : <LoadingState description="Carregando acessos da empresa." title="Aguarde" />
  }

  const columns: DataTableColumn<ManagedUser>[] = [
    {
      key: 'user',
      header: 'Usuário',
      render: (user) => (
        <div className="table-main user-identity-cell">
          <UserAvatar avatarUrl={user.avatarUrl} className="avatar--table-user" name={user.name} />
          <div>
            <strong>{user.name}</strong>
            <small>{user.canBeSeller ? 'Responsável por vendas' : 'Não elegível para vendas'}</small>
          </div>
        </div>
      ),
    },
    {
      key: 'contact',
      header: 'Contato',
      render: (user) => <div className="table-main"><strong>{user.email}</strong><span>{user.phone ?? 'Sem telefone'}</span></div>,
    },
    { key: 'job', header: 'Cargo', render: (user) => user.jobTitle ?? 'Não informado' },
    { key: 'role', header: 'Perfil', render: (user) => labels[user.roles[0]] ?? user.roles[0] },
    { key: 'status', header: 'Status', render: (user) => user.isActive ? 'Ativo' : 'Inativo' },
    {
      key: 'actions',
      header: 'Ações',
      render: (user) => <Button disabled={!user.canEdit} onClick={() => start(user)} size="sm" variant="ghost">Editar</Button>,
    },
  ]
  const accessLocked = selected ? !selected.canEditAccess : false
  const protectedRole = data.available_roles.find((role) => role.name === form.role)?.protected ?? false

  return (
    <main className="general-settings-page user-management-page">
      <header className="general-settings-page__header">
        <div>
          <h1>Usuários e permissões</h1>
          <p>Identidade, acesso e responsabilidade por vendas permanecem separados e protegidos pelo backend.</p>
        </div>
        <Button className="settings-back-button" onClick={onBack} variant="ghost">← Voltar para Configurações</Button>
      </header>

      <Card>
        <SectionTitle title="Acessos da empresa" />
        <Button onClick={() => start()} variant="primary">Adicionar usuário</Button>
        {message ? <p className="payments-feedback">{message}</p> : null}
        {data.users.length
          ? <DataTable columns={columns} data={data.users} getRowKey={(user) => user.id} />
          : <EmptyState actionLabel="Adicionar usuário" description="Nenhum usuário adicional cadastrado." onAction={() => start()} title="Sem usuários" />}
      </Card>

      <Modal
        onClose={() => setOpen(false)}
        onPrimary={() => void submit()}
        open={open}
        primaryDisabled={busy}
        primaryLabel={selected ? 'Salvar alterações' : 'Criar usuário'}
        title={selected ? 'Editar usuário' : 'Adicionar usuário'}
      >
        <div className="user-management-form">
          <section>
            <h3>Identidade</h3>
            <div className="account-form">
              <label>Nome<input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} /></label>
              <label>E-mail<input value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} /></label>
              <label>Telefone<input value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} /></label>
              <label>Cargo<input value={form.job_title} onChange={(event) => setForm({ ...form, job_title: event.target.value })} /></label>
              <label>Foto<input accept="image/png,image/jpeg,image/webp" onChange={(event) => setAvatarFile(event.target.files?.[0] ?? null)} type="file" /></label>
              {selected?.avatarUrl ? <Button disabled={busy} onClick={() => void removeAvatar()} size="sm" variant="ghost">Remover foto atual</Button> : null}
            </div>
          </section>

          <section>
            <h3>Acesso</h3>
            <SelectField
              disabled={accessLocked}
              label="Perfil de acesso"
              onChange={selectRole}
              options={data.available_roles.map((role) => ({ value: role.name, label: role.label }))}
              value={form.role}
            />
            {accessLocked ? <p className="muted-text">Seu próprio acesso não pode ser alterado por esta tela.</p> : null}
            <label className="checkbox-line">
              <input
                checked={form.is_active}
                disabled={accessLocked}
                onChange={(event) => setForm({ ...form, is_active: event.target.checked })}
                type="checkbox"
              />
              Acesso ativo
            </label>
            <div className="user-permissions-grid">
              {data.available_permissions.map((permission) => (
                <label className="checkbox-line" key={permission.name}>
                  <input
                    checked={form.permissions.includes(permission.name)}
                    disabled={accessLocked || protectedRole}
                    onChange={(event) => togglePermission(permission.name, event.target.checked)}
                    type="checkbox"
                  />
                  {permission.label}
                </label>
              ))}
            </div>
            {protectedRole ? <p className="muted-text">O perfil DEV recebe as capacidades protegidas do sistema; elas não são delegadas como overrides.</p> : null}
          </section>

          <section>
            <h3>Operação</h3>
            <label className="checkbox-line">
              <input
                checked={form.can_be_seller}
                onChange={(event) => setForm({ ...form, can_be_seller: event.target.checked })}
                type="checkbox"
              />
              Pode ser responsável por vendas
            </label>
            <p className="muted-text">Esta opção é independente do cargo e do perfil de acesso.</p>
          </section>

          {!selected ? (
            <section>
              <h3>Credencial inicial</h3>
              <div className="account-form">
                <label>Senha inicial<input type="password" value={form.password ?? ''} onChange={(event) => setForm({ ...form, password: event.target.value })} /></label>
                <label>Confirmar senha<input type="password" value={form.password_confirmation ?? ''} onChange={(event) => setForm({ ...form, password_confirmation: event.target.value })} /></label>
              </div>
            </section>
          ) : null}
        </div>
        {error ? <p className="payments-feedback">{error}</p> : null}
      </Modal>
    </main>
  )
}

function defaultUserForm(data: UserManagementData | null): UserForm {
  const role = data?.available_roles.find((candidate) => candidate.name === 'atendente')
    ?? data?.available_roles[data.available_roles.length - 1]

  return {
    name: '',
    email: '',
    phone: '',
    job_title: '',
    role: role?.name ?? 'atendente',
    permissions: role?.permissions ?? [],
    can_be_seller: false,
    is_active: true,
    password: '',
    password_confirmation: '',
  }
}

function emptyUserForm(): UserForm {
  return {
    name: '',
    email: '',
    phone: '',
    job_title: '',
    role: 'atendente',
    permissions: [],
    can_be_seller: false,
    is_active: true,
    password: '',
    password_confirmation: '',
  }
}
