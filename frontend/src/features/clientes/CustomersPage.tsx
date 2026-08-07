import { useState } from 'react'
import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { Modal } from '../../components/ui/Modal'
import { CustomerEditor } from './CustomerEditor'
import type { CustomerSummary } from '../../types/crm'
import type { UpdateCustomerPayload } from '../../services/crm.service'
import { formatCurrency, initialsFromName } from '../../utils/formatters'

const CUSTOMER_CREATE_FORM_ID = 'customer-editor-create'
const CUSTOMER_EDIT_FORM_ID = 'customer-editor-edit'

type CustomersPageProps = {
  customers: CustomerSummary[]
  onCreateCustomer: (payload: UpdateCustomerPayload) => Promise<void>
  onUpdateCustomer: (customerId: string, payload: UpdateCustomerPayload) => Promise<void>
}

export function CustomersPage({ customers, onCreateCustomer, onUpdateCustomer }: CustomersPageProps) {
  const [editingCustomer, setEditingCustomer] = useState<CustomerSummary | null>(null)
  const [isCreating, setIsCreating] = useState(false)
  const [isSaving, setIsSaving] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)

  async function handleCreate(payload: UpdateCustomerPayload) {
    setIsSaving(true)
    setFormError(null)

    try {
      await onCreateCustomer(payload)
      setIsCreating(false)
    } catch (error) {
      setFormError(error instanceof Error ? error.message : 'Não foi possível cadastrar o cliente.')
    } finally {
      setIsSaving(false)
    }
  }

  async function handleUpdate(payload: UpdateCustomerPayload) {
    if (!editingCustomer) {
      return
    }

    setIsSaving(true)
    setFormError(null)

    try {
      await onUpdateCustomer(editingCustomer.id, payload)
      setEditingCustomer(null)
    } catch (error) {
      setFormError(error instanceof Error ? error.message : 'Não foi possível atualizar o cliente.')
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <PageContainer>
      <PageHeader
        actions={(
          <Button icon="plus" onClick={() => {
            setFormError(null)
            setIsCreating(true)
          }} variant="primary">
            Cadastrar cliente
          </Button>
        )}
        description="Perfil operacional com preferências, restrições, crédito e histórico sanitizado."
        title="Clientes"
      />
      <div className="customer-grid">
        {customers.map((customer) => (
          <Card className="customer-card" key={customer.id}>
            <div className="customer-card__header">
              <span className="avatar">{initialsFromName(customer.name)}</span>
              <div>
                <h2>{customer.name}</h2>
                <p>{customer.phoneLabel}</p>
              </div>
            </div>
            <Button icon="edit" onClick={() => {
              setFormError(null)
              setEditingCustomer(customer)
            }} size="sm" variant="secondary">
              Editar cliente
            </Button>
            <div className="tag-row">
              {customer.tags.map((tag) => (
                <Badge key={tag} size="sm" tone="brand">
                  {tag}
                </Badge>
              ))}
            </div>
            <SectionTitle eyebrow="Preferencias" title="Atencoes do atendimento" />
            <ul className="clean-list">
              {customer.preferences.map((preference) => (
                <li key={preference}>{preference}</li>
              ))}
            </ul>
            <strong className="credit-value">Crédito: {formatCurrency(customer.creditBalance)}</strong>
          </Card>
        ))}
      </div>
      <Modal
        closeDisabled={isSaving}
        description="Cadastre cliente manualmente sem alterar identificadores externos do WhatsApp."
        onClose={() => setIsCreating(false)}
        onPrimary={() => document.getElementById(CUSTOMER_CREATE_FORM_ID)?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))}
        open={isCreating}
        primaryDisabled={isSaving}
        primaryLabel={isSaving ? 'Salvando' : 'Salvar cliente'}
        size="lg"
        title="Cadastrar cliente"
      >
        <CustomerEditor disabled={isSaving} error={formError} formId={CUSTOMER_CREATE_FORM_ID} key="create" mode="create" onSubmit={handleCreate} />
      </Modal>
      <Modal
        closeDisabled={isSaving}
        description="Atualize os dados do cadastro. O nome de perfil do WhatsApp fica preservado como consulta."
        onClose={() => setEditingCustomer(null)}
        onPrimary={() => document.getElementById(CUSTOMER_EDIT_FORM_ID)?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))}
        open={editingCustomer !== null}
        primaryDisabled={isSaving}
        primaryLabel={isSaving ? 'Salvando' : 'Salvar alterações'}
        size="lg"
        title="Editar cliente"
      >
        <CustomerEditor
          customer={editingCustomer}
          disabled={isSaving}
          error={formError}
          formId={CUSTOMER_EDIT_FORM_ID}
          key={editingCustomer?.id ?? 'edit'}
          mode="edit"
          onSubmit={handleUpdate}
        />
      </Modal>
    </PageContainer>
  )
}
