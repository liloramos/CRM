import { useMemo, useState, type FormEvent } from 'react'
import type { CustomerSummary } from '../../types/crm'
import type { UpdateCustomerPayload } from '../../services/crm.service'

type CustomerEditorProps = {
  customer?: CustomerSummary | null
  disabled?: boolean
  error?: string | null
  formId: string
  mode: 'create' | 'edit'
  onSubmit: (payload: UpdateCustomerPayload) => Promise<void> | void
}

type CustomerFormState = {
  name: string
  phone: string
  email: string
  notes: string
  street: string
  number: string
  complement: string
  neighborhood: string
  city: string
  reference: string
}

export function CustomerEditor({ customer, disabled = false, error, formId, onSubmit }: CustomerEditorProps) {
  const [form, setForm] = useState<CustomerFormState>(() => formFromCustomer(customer))

  const readonlyDetails = useMemo(() => {
    if (!customer) {
      return []
    }

    return [
      customer.whatsappProfileName ? `Perfil WhatsApp: ${customer.whatsappProfileName}` : null,
      customer.sourceChannel ? `Origem: ${sourceLabel(customer.sourceChannel)}` : null,
      customer.lastWhatsappAt ? `Última mensagem: ${new Date(customer.lastWhatsappAt).toLocaleString('pt-BR')}` : null,
    ].filter(Boolean)
  }, [customer])

  function updateField(field: keyof CustomerFormState, value: string) {
    setForm((current) => ({ ...current, [field]: value }))
  }

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    void onSubmit({
      name: form.name.trim(),
      phone: form.phone.trim() || undefined,
      email: form.email.trim() || undefined,
      notes: form.notes.trim() || undefined,
      address: {
        street: form.street.trim() || undefined,
        number: form.number.trim() || undefined,
        complement: form.complement.trim() || undefined,
        neighborhood: form.neighborhood.trim() || undefined,
        city: form.city.trim() || undefined,
        reference: form.reference.trim() || undefined,
      },
    })
  }

  return (
    <form className="customer-editor" id={formId} onSubmit={handleSubmit}>
      {error ? <p className="form-error" role="alert">{error}</p> : null}

      <label>
        <span>Nome</span>
        <input
          disabled={disabled}
          maxLength={120}
          onChange={(event) => updateField('name', event.target.value)}
          placeholder="Nome do cliente"
          required
          type="text"
          value={form.name}
        />
      </label>

      <div className="form-grid form-grid--two">
        <label>
          <span>Telefone</span>
          <input
            disabled={disabled}
            maxLength={40}
            onChange={(event) => updateField('phone', event.target.value)}
            placeholder="DDD e número"
            type="tel"
            value={form.phone}
          />
        </label>
        <label>
          <span>E-mail</span>
          <input
            disabled={disabled}
            maxLength={160}
            onChange={(event) => updateField('email', event.target.value)}
            placeholder="email@exemplo.com"
            type="email"
            value={form.email}
          />
        </label>
      </div>

      <fieldset>
        <legend>Endereço</legend>
        <div className="form-grid form-grid--two">
          <label>
            <span>Rua</span>
            <input disabled={disabled} onChange={(event) => updateField('street', event.target.value)} value={form.street} />
          </label>
          <label>
            <span>Número</span>
            <input disabled={disabled} onChange={(event) => updateField('number', event.target.value)} value={form.number} />
          </label>
          <label>
            <span>Complemento</span>
            <input disabled={disabled} onChange={(event) => updateField('complement', event.target.value)} value={form.complement} />
          </label>
          <label>
            <span>Bairro</span>
            <input disabled={disabled} onChange={(event) => updateField('neighborhood', event.target.value)} value={form.neighborhood} />
          </label>
          <label>
            <span>Cidade</span>
            <input disabled={disabled} onChange={(event) => updateField('city', event.target.value)} value={form.city} />
          </label>
          <label>
            <span>Referência</span>
            <input disabled={disabled} onChange={(event) => updateField('reference', event.target.value)} value={form.reference} />
          </label>
        </div>
      </fieldset>

      <label>
        <span>Observação</span>
        <textarea
          disabled={disabled}
          maxLength={1000}
          onChange={(event) => updateField('notes', event.target.value)}
          placeholder="Preferências, restrições ou observações do atendimento"
          rows={4}
          value={form.notes}
        />
      </label>

      {readonlyDetails.length > 0 ? (
        <div className="customer-editor__readonly">
          {readonlyDetails.map((detail) => (
            <span key={detail}>{detail}</span>
          ))}
        </div>
      ) : null}
    </form>
  )
}

function formFromCustomer(customer?: CustomerSummary | null): CustomerFormState {
  return {
    name: customer?.name ?? '',
    phone: customer?.phone ?? '',
    email: customer?.email ?? '',
    notes: customer?.notes?.[0] ?? '',
    street: customer?.address?.street ?? '',
    number: customer?.address?.number ?? '',
    complement: customer?.address?.complement ?? '',
    neighborhood: customer?.address?.neighborhood ?? '',
    city: customer?.address?.city ?? '',
    reference: customer?.address?.reference ?? '',
  }
}

function sourceLabel(source: string): string {
  return source === 'whatsapp' ? 'WhatsApp' : source === 'demo' ? 'Demonstração' : 'Manual'
}
