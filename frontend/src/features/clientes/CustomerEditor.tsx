import { useMemo, useState, type FormEvent } from 'react'
import type { CustomerAddress, CustomerSummary } from '../../types/crm'
import type { UpdateCustomerPayload } from '../../services/crm.service'

type CustomerEditorProps = {
  customer?: CustomerSummary | null
  disabled?: boolean
  error?: string | null
  formId: string
  mode: 'create' | 'edit'
  onSubmit: (payload: UpdateCustomerPayload) => Promise<void> | void
}

type AddressDraft = {
  key: string
  id?: string
  label: string
  postalCode: string
  street: string
  number: string
  complement: string
  neighborhood: string
  city: string
  state: string
  reference: string
  isDefault: boolean
}

type CustomerFormState = {
  name: string
  phone: string
  email: string
  notes: string
}

export function CustomerEditor({ customer, disabled = false, error, formId, onSubmit }: CustomerEditorProps) {
  const [form, setForm] = useState<CustomerFormState>(() => formFromCustomer(customer))
  const [addresses, setAddresses] = useState<AddressDraft[]>(() => (customer?.addresses ?? []).map(addressDraft))
  const [editingAddressKey, setEditingAddressKey] = useState<string | null>(null)
  const [addressError, setAddressError] = useState<string | null>(null)

  const readonlyDetails = useMemo(() => {
    if (!customer) return []
    return [
      customer.whatsappProfileName ? `Perfil WhatsApp: ${customer.whatsappProfileName}` : null,
      customer.sourceChannel ? `Origem: ${sourceLabel(customer.sourceChannel)}` : null,
      customer.lastWhatsappAt ? `Última mensagem: ${new Date(customer.lastWhatsappAt).toLocaleString('pt-BR')}` : null,
    ].filter(Boolean)
  }, [customer])

  function updateField(field: keyof CustomerFormState, value: string) {
    setForm((current) => ({ ...current, [field]: value }))
  }

  function updateAddress(key: string, field: keyof AddressDraft, value: string | boolean) {
    setAddresses((current) => current.map((address) => address.key === key ? { ...address, [field]: value } : address))
  }

  function addAddress() {
    const key = `new-${Date.now()}-${addresses.length}`
    setAddresses((current) => [...current, emptyAddress(key, current.length === 0)])
    setEditingAddressKey(key)
    setAddressError(null)
  }

  function removeAddress(key: string) {
    setAddresses((current) => {
      const removed = current.find((address) => address.key === key)
      const next = current.filter((address) => address.key !== key)
      if (removed?.isDefault && next.length > 0) next[0] = { ...next[0], isDefault: true }
      return next
    })
    if (editingAddressKey === key) setEditingAddressKey(null)
  }

  function makeDefault(key: string) {
    setAddresses((current) => current.map((address) => ({ ...address, isDefault: address.key === key })))
  }

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const incomplete = addresses.find((address) => !address.label.trim() || !address.street.trim() || !address.number.trim() || !address.neighborhood.trim() || !address.city.trim())
    if (incomplete) {
      setEditingAddressKey(incomplete.key)
      setAddressError('Preencha nome, rua, número, bairro e cidade em cada endereço.')
      return
    }

    setAddressError(null)
    void onSubmit({
      name: form.name.trim(),
      phone: form.phone.trim() || undefined,
      email: form.email.trim() || undefined,
      notes: form.notes.trim() || undefined,
      addresses: addresses.map((address) => ({
        id: address.id,
        label: address.label.trim(),
        postal_code: address.postalCode.trim() || undefined,
        street: address.street.trim(),
        number: address.number.trim(),
        complement: address.complement.trim() || undefined,
        neighborhood: address.neighborhood.trim(),
        city: address.city.trim(),
        state: address.state.trim().toUpperCase() || undefined,
        country_code: 'BR',
        reference: address.reference.trim() || undefined,
        is_default: address.isDefault,
      })),
    })
  }

  return (
    <form className="customer-editor" id={formId} onSubmit={handleSubmit}>
      {error ? <p className="form-error" role="alert">{error}</p> : null}

      <label><span>Nome</span><input disabled={disabled} maxLength={120} onChange={(event) => updateField('name', event.target.value)} placeholder="Nome do cliente" required type="text" value={form.name} /></label>
      <div className="form-grid form-grid--two">
        <label><span>Telefone</span><input disabled={disabled} maxLength={40} onChange={(event) => updateField('phone', event.target.value)} placeholder="DDD e número" type="tel" value={form.phone} /></label>
        <label><span>E-mail</span><input disabled={disabled} maxLength={160} onChange={(event) => updateField('email', event.target.value)} placeholder="email@exemplo.com" type="email" value={form.email} /></label>
      </div>

      <fieldset className="customer-address-book">
        <div className="customer-address-book__heading">
          <div><legend>Endereços</legend><small>Salve Casa, Trabalho e outros destinos usados com frequência.</small></div>
          <button className="text-button" disabled={disabled} onClick={addAddress} type="button">+ Adicionar endereço</button>
        </div>
        {addressError ? <p className="form-error" role="alert">{addressError}</p> : null}
        {addresses.length === 0 ? <p className="customer-address-book__empty">Nenhum endereço salvo. O cliente também pode pedir para retirada.</p> : null}
        <div className="customer-address-list">
          {addresses.map((address) => (
            <div className="customer-address-card" key={address.key}>
              <div className="customer-address-card__summary">
                <div><strong>{address.label || 'Novo endereço'}</strong>{address.isDefault ? <span className="customer-address-card__default">Padrão</span> : null}<small>{addressLine(address)}</small></div>
                <div className="customer-address-card__actions">
                  {!address.isDefault ? <button disabled={disabled} onClick={() => makeDefault(address.key)} type="button">Definir padrão</button> : null}
                  <button disabled={disabled} onClick={() => setEditingAddressKey(editingAddressKey === address.key ? null : address.key)} type="button">{editingAddressKey === address.key ? 'Fechar' : 'Editar'}</button>
                  <button disabled={disabled} onClick={() => removeAddress(address.key)} type="button">Remover</button>
                </div>
              </div>
              {editingAddressKey === address.key ? (
                <div className="customer-address-card__form form-grid form-grid--two">
                  <label><span>Nome do endereço</span><input disabled={disabled} maxLength={80} onChange={(event) => updateAddress(address.key, 'label', event.target.value)} placeholder="Casa, Trabalho..." value={address.label} /></label>
                  <label><span>CEP</span><input disabled={disabled} maxLength={16} onChange={(event) => updateAddress(address.key, 'postalCode', event.target.value)} value={address.postalCode} /></label>
                  <label><span>Rua</span><input disabled={disabled} onChange={(event) => updateAddress(address.key, 'street', event.target.value)} value={address.street} /></label>
                  <label><span>Número</span><input disabled={disabled} onChange={(event) => updateAddress(address.key, 'number', event.target.value)} value={address.number} /></label>
                  <label><span>Complemento</span><input disabled={disabled} onChange={(event) => updateAddress(address.key, 'complement', event.target.value)} value={address.complement} /></label>
                  <label><span>Bairro</span><input disabled={disabled} onChange={(event) => updateAddress(address.key, 'neighborhood', event.target.value)} value={address.neighborhood} /></label>
                  <label><span>Cidade</span><input disabled={disabled} onChange={(event) => updateAddress(address.key, 'city', event.target.value)} value={address.city} /></label>
                  <label><span>UF</span><input disabled={disabled} maxLength={2} onChange={(event) => updateAddress(address.key, 'state', event.target.value)} value={address.state} /></label>
                  <label className="customer-address-card__wide"><span>Referência</span><input disabled={disabled} onChange={(event) => updateAddress(address.key, 'reference', event.target.value)} value={address.reference} /></label>
                </div>
              ) : null}
            </div>
          ))}
        </div>
      </fieldset>

      <label><span>Observação</span><textarea disabled={disabled} maxLength={1000} onChange={(event) => updateField('notes', event.target.value)} placeholder="Preferências, restrições ou observações do atendimento" rows={4} value={form.notes} /></label>
      {readonlyDetails.length > 0 ? <div className="customer-editor__readonly">{readonlyDetails.map((detail) => <span key={detail}>{detail}</span>)}</div> : null}
    </form>
  )
}

function formFromCustomer(customer?: CustomerSummary | null): CustomerFormState {
  return { name: customer?.name ?? '', phone: customer?.phone ?? '', email: customer?.email ?? '', notes: customer?.notes?.[0] ?? '' }
}

function addressDraft(address: CustomerAddress, index: number): AddressDraft {
  return {
    key: address.id ?? `existing-${index}`,
    id: address.id,
    label: address.label ?? 'Principal',
    postalCode: address.postal_code ?? '',
    street: address.street ?? '',
    number: address.number ?? '',
    complement: address.complement ?? '',
    neighborhood: address.neighborhood ?? '',
    city: address.city ?? '',
    state: address.state ?? '',
    reference: address.reference ?? '',
    isDefault: address.is_default,
  }
}

function emptyAddress(key: string, isDefault: boolean): AddressDraft {
  return { key, label: '', postalCode: '', street: '', number: '', complement: '', neighborhood: '', city: '', state: '', reference: '', isDefault }
}

function addressLine(address: AddressDraft): string {
  return [address.street, address.number, address.neighborhood, address.city].filter(Boolean).join(', ') || 'Preencha os dados deste endereço'
}

function sourceLabel(source: string): string {
  return source === 'whatsapp' ? 'WhatsApp' : source === 'demo' ? 'Demonstração' : 'Manual'
}
