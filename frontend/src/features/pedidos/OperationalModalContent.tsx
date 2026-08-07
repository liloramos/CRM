import { useEffect, useId, useRef, useState, type KeyboardEvent, type MouseEvent } from 'react'
import { Badge } from '../../components/ui/Badge'
import { EmptyState, LoadingState } from '../../components/ui/States'
import { Icon } from '../../components/ui/Icon'
import { SelectField } from '../../components/ui/SelectField'
import type {
  AddItemContext,
  AppModal,
  BackendOrderStatus,
  Conversation,
  CustomerSummary,
  FulfillmentApiType,
  MenuOption,
  Order,
  PrintPreviewResult,
  Product,
  StructuredMeatConfiguration,
  StructuredComponentOption,
  DailyMenuComponent,
  StructuredProductAddition,
  StructuredProductOption,
  StructuredProductOptionGroup,
} from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

export type AutomationModeSelection = 'assisted' | 'manual'

type PaymentMethodSelection = 'pix' | 'cash' | 'debit_card' | 'credit_card' | 'customer_credit' | 'other'

type MeatModeSelection = 'traditional' | 'beef_only'

type BlockedOrderDeletion = {
  order_id: string
  code?: string | null
  reasons: string[]
}

type OperationalModalContentProps = {
  addItemContext: AddItemContext | null
  actionError: string | null
  automationMode: AutomationModeSelection
  beneficiaryName: string
  blockedOrderDeletions: BlockedOrderDeletion[]
  bulkDeleteCount: number
  cancelNotes: string
  cancelReason: string
  deleteConfirmation: string
  itemHasDifferentBeneficiary: boolean
  itemExtraBeef: boolean
  isActionBusy: boolean
  isSearchingCustomers: boolean
  itemMeatMode: MeatModeSelection
  itemNotes: string
  itemQuantity: number
  modal: AppModal
  newCustomerMode: boolean
  newCustomerName: string
  newCustomerPhone: string
  newOrderCustomerQuery: string
  newOrderCustomerResults: CustomerSummary[]
  newOrderFulfillmentType: FulfillmentApiType
  newOrderNotes: string
  newOrderWalkInPhone: string
  onAutomationModeChange: (mode: AutomationModeSelection) => void
  onBeneficiaryNameChange: (value: string) => void
  onCancelNotesChange: (value: string) => void
  onCancelReasonChange: (value: string) => void
  onDeleteConfirmationChange: (value: string) => void
  onItemHasDifferentBeneficiaryChange: (value: boolean) => void
  onItemExtraBeefChange: (value: boolean) => void
  onItemMeatModeChange: (value: MeatModeSelection) => void
  onItemNotesChange: (value: string) => void
  onItemQuantityChange: (value: number) => void
  onNewCustomerModeChange: (value: boolean) => void
  onNewCustomerNameChange: (value: string) => void
  onNewCustomerPhoneChange: (value: string) => void
  onNewOrderCustomerQueryChange: (value: string) => void
  onNewOrderFulfillmentTypeChange: (value: FulfillmentApiType) => void
  onNewOrderNotesChange: (value: string) => void
  onNewOrderWalkInPhoneChange: (value: string) => void
  onPaymentAmountChange: (value: string) => void
  onPaymentMethodChange: (value: PaymentMethodSelection) => void
  onPaymentNotesChange: (value: string) => void
  onProductChange: (productId: string) => void
  onSelectNewOrderCustomer: (customer: CustomerSummary | null) => void
  onSelectedOptionsChange: (optionIds: string[]) => void
  onStatusNotesChange: (value: string) => void
  onStatusReasonChange: (value: string) => void
  onStatusTargetChange: (value: BackendOrderStatus | '') => void
  paymentAmount: string
  paymentMethod: PaymentMethodSelection
  paymentNotes: string
  printPreview: PrintPreviewResult | null
  products: Product[]
  selectedConversation?: Conversation
  selectedNewOrderCustomer: CustomerSummary | null
  selectedOrder?: Order
  selectedOptionIds: string[]
  selectedProductId: string
  statusNotes: string
  statusReason: string
  statusTarget: BackendOrderStatus | ''
}

export function OperationalModalContent({
  addItemContext,
  actionError,
  automationMode,
  beneficiaryName,
  blockedOrderDeletions,
  bulkDeleteCount,
  cancelNotes,
  cancelReason,
  deleteConfirmation,
  itemHasDifferentBeneficiary,
  itemExtraBeef,
  isActionBusy,
  isSearchingCustomers,
  itemMeatMode,
  itemNotes,
  itemQuantity,
  modal,
  newCustomerMode,
  newCustomerName,
  newCustomerPhone,
  newOrderCustomerQuery,
  newOrderCustomerResults,
  newOrderFulfillmentType,
  newOrderNotes,
  newOrderWalkInPhone,
  onAutomationModeChange,
  onBeneficiaryNameChange,
  onCancelNotesChange,
  onCancelReasonChange,
  onDeleteConfirmationChange,
  onItemHasDifferentBeneficiaryChange,
  onItemExtraBeefChange,
  onItemMeatModeChange,
  onItemNotesChange,
  onItemQuantityChange,
  onNewCustomerModeChange,
  onNewCustomerNameChange,
  onNewCustomerPhoneChange,
  onNewOrderCustomerQueryChange,
  onNewOrderFulfillmentTypeChange,
  onNewOrderNotesChange,
  onNewOrderWalkInPhoneChange,
  onPaymentAmountChange,
  onPaymentMethodChange,
  onPaymentNotesChange,
  onProductChange,
  onSelectNewOrderCustomer,
  onSelectedOptionsChange,
  onStatusNotesChange,
  onStatusReasonChange,
  onStatusTargetChange,
  paymentAmount,
  paymentMethod,
  paymentNotes,
  printPreview,
  products,
  selectedConversation,
  selectedNewOrderCustomer,
  selectedOrder,
  selectedOptionIds,
  selectedProductId,
  statusNotes,
  statusReason,
  statusTarget,
}: OperationalModalContentProps) {
  if (modal === 'new-order') {
    return (
      <div className="modal-fields">
        <p>Crie um pedido real para cliente cadastrado ou cliente avulso. Destinatario diferente fica apenas no item, quando necessario.</p>

        {newCustomerMode ? (
          <div className="customer-create-panel">
            <div className="inline-actions">
              <Badge tone="brand">Novo cliente</Badge>
              <button
                className="text-button"
                onClick={() => onNewCustomerModeChange(false)}
                type="button"
              >
                Voltar para busca
              </button>
            </div>
            <label>
              Nome do cliente
              <input
                autoFocus
                onChange={(event) => onNewCustomerNameChange(event.target.value)}
                placeholder="Ex.: Murilo"
                value={newCustomerName}
              />
            </label>
            <label>
              Telefone
              <input
                onChange={(event) => onNewCustomerPhoneChange(event.target.value)}
                placeholder="Ex.: (62) 99999-0000"
                value={newCustomerPhone}
              />
            </label>
          </div>
        ) : (
          <CustomerSearchCombobox
            customers={newOrderCustomerResults}
            isLoading={isSearchingCustomers}
            onCreateNewCustomer={() => {
              onNewCustomerNameChange(newOrderCustomerQuery)
              onNewCustomerModeChange(true)
            }}
            onQueryChange={(value) => {
              onNewOrderCustomerQueryChange(value)
              onSelectNewOrderCustomer(null)
            }}
            onSelectCustomer={(customer) => {
              onSelectNewOrderCustomer(customer)
              onNewOrderCustomerQueryChange(customer?.name ?? '')
            }}
            query={newOrderCustomerQuery}
            selectedCustomer={selectedNewOrderCustomer}
          />
        )}

        {!newCustomerMode && !selectedNewOrderCustomer && newOrderCustomerQuery.trim() ? (
          <div className="walk-in-customer-panel">
            <Badge tone="neutral">Cliente avulso</Badge>
            <p>O pedido sera criado com o nome digitado, sem cadastrar cliente automaticamente.</p>
            <label>
              Telefone opcional
              <input
                onChange={(event) => onNewOrderWalkInPhoneChange(event.target.value)}
                placeholder="Ex.: (62) 99999-0000"
                value={newOrderWalkInPhone}
              />
            </label>
          </div>
        ) : null}

        <SelectField
          label="Tipo de atendimento"
          onChange={(value) => onNewOrderFulfillmentTypeChange(value as FulfillmentApiType)}
          options={[
            { value: 'pickup', label: 'Retirada' },
            { value: 'counter', label: 'Balcão' },
            { value: 'delivery', label: 'Entrega' },
          ]}
          value={newOrderFulfillmentType}
        />
        <label>
          Observacao do pedido
          <textarea
            onChange={(event) => onNewOrderNotesChange(event.target.value)}
            placeholder="Ex.: cliente retira no balcao, conferir troco."
            value={newOrderNotes}
          />
        </label>
        {actionError ? <p className="form-error">{actionError}</p> : null}
      </div>
    )
  }

  if (modal === 'add-product') {
    const selectedProduct = addItemContext?.product ?? products.find((product) => product.id === selectedProductId)
    const structuredGroups = selectedProduct?.structuredGroups ?? []
    const visibleStructuredGroups = selectedProduct?.meatConfiguration
      ? structuredGroups.filter((group) => !['variacao_bife', 'bife_adicional'].includes(group.code))
      : structuredGroups
    const legacyOptionGroups = groupOptions(selectedProduct?.options ?? [])

    return (
      <div className="modal-fields">
        {addItemContext ? (
          <p>
            Pedido <strong>{addItemContext.orderCode}</strong>. O item sera adicionado somente a este pedido real.
          </p>
        ) : selectedOrder ? (
          <p>O pedido selecionado nao esta pronto para receber itens reais.</p>
        ) : (
          <p>Crie ou selecione um pedido antes de adicionar itens.</p>
        )}
        <SelectField
          disabled={!addItemContext || products.length === 0}
          label="Produto"
          onChange={onProductChange}
          options={products.map((product) => ({
            value: product.id,
            label: `${product.name} - ${formatCurrency(product.price)}`,
            description: product.category,
            disabled: !product.available,
          }))}
          placeholder={products.length === 0 ? 'Nenhum produto carregado' : 'Selecione um produto'}
          searchable
          searchPlaceholder="Buscar produto..."
          value={selectedProduct?.id ?? ''}
        />
        {selectedProduct?.meatConfiguration ? (
          <BeefChoicePicker
            dailyMeats={selectedProduct.dailyMeatOptions ?? []}
            extraBeef={selectedProduct.additions?.find((addition) => addition.code === 'extra_beef') ?? null}
            isExtraBeefSelected={itemExtraBeef}
            meatConfiguration={selectedProduct.meatConfiguration}
            meatMode={itemMeatMode}
            onExtraBeefChange={onItemExtraBeefChange}
            onMeatModeChange={onItemMeatModeChange}
            onSelectedOptionsChange={onSelectedOptionsChange}
            selectedOptionIds={selectedOptionIds}
          />
        ) : null}
        {selectedProduct && visibleStructuredGroups.length > 0 ? (
          <StructuredOptionPicker
            groups={visibleStructuredGroups}
            onSelectedOptionsChange={onSelectedOptionsChange}
            selectedOptionIds={selectedOptionIds}
          />
        ) : null}
        {selectedProduct && visibleStructuredGroups.length === 0 && legacyOptionGroups.length > 0 ? (
          <LegacyOptionPicker
            groups={legacyOptionGroups}
            onSelectedOptionsChange={onSelectedOptionsChange}
            selectedOptionIds={selectedOptionIds}
          />
        ) : null}
        <label>
          Quantidade
          <input
            min={1}
            onChange={(event) => onItemQuantityChange(Number(event.target.value))}
            type="number"
            value={itemQuantity}
          />
        </label>
        <label className="checkbox-line">
          <input
            checked={itemHasDifferentBeneficiary}
            onChange={(event) => {
              onItemHasDifferentBeneficiaryChange(event.target.checked)
              if (!event.target.checked) {
                onBeneficiaryNameChange('')
              }
            }}
            type="checkbox"
          />
          Este item e para outra pessoa
        </label>
        {itemHasDifferentBeneficiary ? (
          <label>
            Nome de quem vai receber
            <input
              onChange={(event) => onBeneficiaryNameChange(event.target.value)}
              placeholder="Ex.: Larissa"
              value={beneficiaryName}
            />
          </label>
        ) : null}
        <label>
          Observacao por item
          <textarea
            onChange={(event) => onItemNotesChange(event.target.value)}
            placeholder="Ex.: sem salada, retirar cebola, separar para retirada."
            value={itemNotes}
          />
        </label>
        {selectedProduct ? (
          <AddItemCompositionSummary
            extraBeefSelected={itemExtraBeef}
            meatMode={itemMeatMode}
            product={selectedProduct}
            quantity={itemQuantity}
            selectedOptionIds={selectedOptionIds}
          />
        ) : null}
        {actionError ? <p className="form-error">{actionError}</p> : null}
      </div>
    )
  }

  if (modal === 'print-preview') {
    if (isActionBusy && !printPreview) {
      return <LoadingState description="Renderizando comanda HTML pelo Laravel..." title="Gerando previa" />
    }

    return (
      <div className="modal-fields">
        {actionError ? <p className="form-error">{actionError}</p> : null}
        {printPreview?.html ? (
          <iframe className="ticket-frame" srcDoc={printPreview.html} title="Previa HTML da comanda" />
        ) : (
          <EmptyState description="A previa sera exibida aqui quando o backend gerar o ticket." title="Sem previa carregada" />
        )}
      </div>
    )
  }

  if (modal === 'confirm-payment') {
    return (
      <div className="modal-fields">
        <p>
          Pedido <strong>{selectedOrder?.code ?? 'selecionado'}</strong>. Total em aberto:{' '}
          <strong>{formatCurrency(selectedOrder?.amountDue ?? 0)}</strong>.
        </p>
        <SelectField
          label="Forma de pagamento"
          onChange={(value) => onPaymentMethodChange(value as PaymentMethodSelection)}
          options={[
            { value: 'pix', label: 'Pix' },
            { value: 'cash', label: 'Dinheiro' },
            { value: 'debit_card', label: 'Cartão de débito' },
            { value: 'credit_card', label: 'Cartão de crédito' },
            { value: 'customer_credit', label: 'Crédito do cliente' },
            { value: 'other', label: 'Outra forma' },
          ]}
          value={paymentMethod}
        />
        <label>
          Valor recebido
          <input
            inputMode="decimal"
            onChange={(event) => onPaymentAmountChange(event.target.value)}
            placeholder="Ex.: 22,00"
            value={paymentAmount}
          />
        </label>
        <label>
          Observacao
          <textarea
            onChange={(event) => onPaymentNotesChange(event.target.value)}
            placeholder="Ex.: pagamento conferido no caixa."
            value={paymentNotes}
          />
        </label>
        {actionError ? <p className="form-error">{actionError}</p> : null}
      </div>
    )
  }

  if (modal === 'change-status') {
    const transitions = selectedOrder?.availableTransitions ?? []

    return (
      <div className="modal-fields">
        {transitions.length > 0 ? (
          <>
            <SelectField
              label="Novo status"
              onChange={(value) => onStatusTargetChange(value as BackendOrderStatus)}
              options={transitions.map((transition) => ({
                value: transition.status,
                label: transition.label,
              }))}
              value={statusTarget}
            />
            <label>
              Motivo
              <input
                onChange={(event) => onStatusReasonChange(event.target.value)}
                placeholder="Ex.: preparo iniciado"
                value={statusReason}
              />
            </label>
            <label>
              Observacao
              <textarea
                onChange={(event) => onStatusNotesChange(event.target.value)}
                placeholder="Detalhe operacional opcional."
                value={statusNotes}
              />
            </label>
          </>
        ) : (
          <EmptyState description="Este pedido nao possui transicoes disponiveis no momento." title="Sem status disponivel" />
        )}
        {actionError ? <p className="form-error">{actionError}</p> : null}
      </div>
    )
  }

  if (modal === 'cancel-order') {
    return (
      <div className="modal-fields">
        <p>
          O pedido <strong>{selectedOrder?.code ?? 'selecionado'}</strong> sera cancelado sem apagar itens ou historico.
        </p>
        <label>
          Motivo do cancelamento
          <input
            autoFocus
            onChange={(event) => onCancelReasonChange(event.target.value)}
            placeholder="Ex.: cliente desistiu"
            value={cancelReason}
          />
        </label>
        <label>
          Observacao
          <textarea
            onChange={(event) => onCancelNotesChange(event.target.value)}
            placeholder="Detalhe operacional opcional."
            value={cancelNotes}
          />
        </label>
        {actionError ? <p className="form-error">{actionError}</p> : null}
      </div>
    )
  }

  if (modal === 'delete-draft') {
    return (
      <div className="modal-fields">
        <p>
          O rascunho <strong>{selectedOrder?.code ?? 'selecionado'}</strong> sera apagado permanentemente somente se ainda estiver vazio, sem pagamento e sem impressao.
        </p>
        <div className="attention-box">
          <Badge tone="danger">Acao permanente</Badge>
          <p>Pedidos com itens, pagamento, comanda, entrega, conversa ou historico operacional devem ser cancelados, nao apagados.</p>
        </div>
        {actionError ? <p className="form-error">{actionError}</p> : null}
      </div>
    )
  }

  if (modal === 'delete-order-permanent') {
    return (
      <div className="modal-fields">
        <p>
          O pedido <strong>{selectedOrder?.code ?? 'selecionado'}</strong> sera excluido permanentemente somente se for operacionalmente elegivel.
        </p>
        <div className="attention-box">
          <Badge tone="danger">Exclusao operacional</Badge>
          <p>Somente pedidos sem pagamento, impressao fisica, entrega ou preparo iniciado podem ser excluidos permanentemente.</p>
        </div>
        <BlockedDeletionList blocked={blockedOrderDeletions} />
        <label>
          Digite EXCLUIR para confirmar
          <input
            autoFocus
            onChange={(event) => onDeleteConfirmationChange(event.target.value)}
            value={deleteConfirmation}
          />
        </label>
        {actionError ? <p className="form-error">{actionError}</p> : null}
      </div>
    )
  }

  if (modal === 'delete-orders-bulk') {
    return (
      <div className="modal-fields">
        <p>
          {bulkDeleteCount} pedido(s) selecionado(s) serao excluidos permanentemente somente se todos forem operacionalmente elegiveis.
        </p>
        <div className="attention-box">
          <Badge tone="danger">Exclusao em lote</Badge>
          <p>Pedidos bloqueados nao serao ignorados silenciosamente. Se houver bloqueio, nenhum pedido da selecao sera excluido.</p>
        </div>
        <BlockedDeletionList blocked={blockedOrderDeletions} />
        <label>
          Digite EXCLUIR para confirmar
          <input
            autoFocus
            onChange={(event) => onDeleteConfirmationChange(event.target.value)}
            value={deleteConfirmation}
          />
        </label>
        {actionError ? <p className="form-error">{actionError}</p> : null}
      </div>
    )
  }

  if (modal === 'cleanup-test-orders') {
    return (
      <div className="modal-fields">
        <p>
          {bulkDeleteCount} pedido(s) selecionado(s) serao enviados para a limpeza ampla de registros de teste.
        </p>
        <div className="attention-box">
          <Badge tone="danger">Ferramenta de desenvolvimento</Badge>
          <p>Esta ferramenta so fica disponivel em ambiente seguro, com ALLOW_DESTRUCTIVE_TEST_CLEANUP habilitado. Clientes sao preservados.</p>
        </div>
        <label>
          Digite EXCLUIR para confirmar
          <input
            autoFocus
            onChange={(event) => onDeleteConfirmationChange(event.target.value)}
            value={deleteConfirmation}
          />
        </label>
        {actionError ? <p className="form-error">{actionError}</p> : null}
      </div>
    )
  }

  if (modal === 'toggle-ai') {
    if (!selectedConversation) {
      return (
        <div className="mode-options">
          <EmptyState
            description="Selecione uma conversa na tela de atendimento antes de alternar IA/manual."
            title="Nenhuma conversa selecionada"
          />
        </div>
      )
    }

    return (
      <div className="mode-options">
        <div className="mode-options__intro">
          <strong>Conversa selecionada: {selectedConversation.customer.name}</strong>
          <p>A alteracao vale para esta conversa. Situacoes ambiguas, pagamento, credito e entrega seguem com confirmacao humana.</p>
        </div>

        <label className={automationMode === 'assisted' ? 'mode-card is-selected' : 'mode-card'}>
          <input
            checked={automationMode === 'assisted'}
            name="automation-mode"
            onChange={() => onAutomationModeChange('assisted')}
            type="radio"
            value="assisted"
          />
          <span className="mode-card__icon">
            <Icon name="ai" size={22} />
          </span>
          <span className="mode-card__copy">
            <strong>IA assistida</strong>
            <small>Sugere respostas e perguntas de confirmacao, sem confirmar decisoes sensiveis sozinha.</small>
          </span>
        </label>
        <label className={automationMode === 'manual' ? 'mode-card is-selected' : 'mode-card'}>
          <input
            checked={automationMode === 'manual'}
            name="automation-mode"
            onChange={() => onAutomationModeChange('manual')}
            type="radio"
            value="manual"
          />
          <span className="mode-card__icon">
            <Icon name="user" size={22} />
          </span>
          <span className="mode-card__copy">
            <strong>Atendimento manual</strong>
            <small>A equipe assume a conversa; o sistema registra tomada manual e mantem revisao humana ativa.</small>
          </span>
        </label>
        {actionError ? <p className="form-error">{actionError}</p> : null}
      </div>
    )
  }

  if (modal === 'print-error') {
    return (
      <div className="modal-fields">
        <p>Opcoes previstas: tentar novamente, reimprimir, copiar comanda ou marcar impresso manualmente.</p>
        <label>
          Motivo operacional
          <textarea placeholder="Descreva a falha de impressao de forma objetiva." />
        </label>
      </div>
    )
  }

  if (modal === 'whatsapp-error') {
    return (
      <div className="modal-fields">
        <p>Verifique provider, webhook e variaveis seguras. Tokens reais nao devem aparecer na interface.</p>
        <label>
          Diagnostico
          <input placeholder="Sem conexao ou configuracao ausente" />
        </label>
      </div>
    )
  }

  return (
    <div className="modal-fields">
      <label>
        Motivo
        <textarea placeholder="Registre um motivo operacional seguro." />
      </label>
      <label>
        Proxima acao
        <input placeholder="Ex.: conferir com cliente antes de finalizar" />
      </label>
    </div>
  )
}

function BlockedDeletionList({ blocked }: { blocked: BlockedOrderDeletion[] }) {
  if (blocked.length === 0) {
    return null
  }

  return (
    <div className="blocked-deletions" role="alert">
      <strong>Pedidos bloqueados</strong>
      {blocked.map((entry) => (
        <div className="blocked-deletions__item" key={entry.order_id}>
          <span>{entry.code ? `Pedido ${entry.code}` : `Pedido ${entry.order_id}`}</span>
          <small>{entry.reasons.map(deletionReasonLabel).join(', ')}</small>
        </div>
      ))}
    </div>
  )
}

function deletionReasonLabel(reason: string): string {
  switch (reason) {
    case 'order_not_found':
      return 'pedido nao encontrado para esta empresa'
    case 'status_not_eligible':
      return 'status nao elegivel'
    case 'preparation_started':
      return 'preparo iniciado'
    case 'payment_confirmed':
      return 'pagamento confirmado'
    case 'payment_record_exists':
      return 'registro de pagamento existente'
    case 'financial_movement':
      return 'movimentacao financeira'
    case 'print_confirmed':
      return 'impressao fisica confirmada'
    case 'delivery_started':
      return 'entrega ou retirada iniciada'
    case 'conversation_linked':
      return 'pedido vinculado a conversa'
    default:
      return reason.replace(/_/g, ' ')
  }
}

function CustomerSearchCombobox({
  customers,
  isLoading,
  onCreateNewCustomer,
  onQueryChange,
  onSelectCustomer,
  query,
  selectedCustomer,
}: {
  customers: CustomerSummary[]
  isLoading: boolean
  onCreateNewCustomer: () => void
  onQueryChange: (value: string) => void
  onSelectCustomer: (customer: CustomerSummary | null) => void
  query: string
  selectedCustomer: CustomerSummary | null
}) {
  const [isOpen, setIsOpen] = useState(false)
  const [activeIndex, setActiveIndex] = useState(0)
  const rootRef = useRef<HTMLDivElement>(null)
  const searchInputRef = useRef<HTMLInputElement>(null)
  const listboxId = useId()
  const safeActiveIndex = Math.min(activeIndex, Math.max(customers.length - 1, 0))
  const walkInName = query.trim()

  useEffect(() => {
    if (!isOpen) {
      return undefined
    }

    function handleDocumentMouseDown(event: globalThis.MouseEvent) {
      if (!rootRef.current?.contains(event.target as Node)) {
        setIsOpen(false)
      }
    }

    document.addEventListener('mousedown', handleDocumentMouseDown)

    return () => document.removeEventListener('mousedown', handleDocumentMouseDown)
  }, [isOpen])

  function openDropdown() {
    setIsOpen(true)
    window.setTimeout(() => searchInputRef.current?.focus(), 0)
  }

  function handleSelect(customer: CustomerSummary) {
    onSelectCustomer(customer)
    setIsOpen(false)
  }

  function handleClear(event: MouseEvent<HTMLButtonElement>) {
    event.stopPropagation()
    onSelectCustomer(null)
    onQueryChange('')
    openDropdown()
  }

  return (
    <div className="customer-select" ref={rootRef}>
      <span className="field-label">Cliente</span>
      <div className={isOpen ? 'customer-select__control is-open' : 'customer-select__control'}>
        <button
          aria-controls={listboxId}
          aria-expanded={isOpen}
          aria-haspopup="listbox"
          className="customer-select__trigger"
          onClick={() => (isOpen ? setIsOpen(false) : openDropdown())}
          onKeyDown={(event) => {
            if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
              event.preventDefault()
              openDropdown()
            }

            if (event.key === 'Escape') {
              setIsOpen(false)
            }
          }}
          role="combobox"
          type="button"
        >
          <span className={selectedCustomer ? 'customer-select__value' : 'customer-select__placeholder'}>
            {selectedCustomer ? `${selectedCustomer.name} - ${selectedCustomer.phoneLabel}` : 'Selecione ou digite o nome do cliente...'}
          </span>
          <Icon name="chevron-right" size={16} />
        </button>
        {selectedCustomer ? (
          <button aria-label="Limpar cliente selecionado" className="customer-select__clear" onClick={handleClear} type="button">
            <Icon name="close" size={14} />
          </button>
        ) : null}
      </div>

      {isOpen ? (
        <div className="customer-select__dropdown">
          <label className="customer-select__search">
            <span>Pesquisar</span>
            <input
              aria-activedescendant={customers[safeActiveIndex] ? `${listboxId}-option-${customers[safeActiveIndex].id}` : undefined}
              aria-autocomplete="list"
              aria-controls={listboxId}
              onChange={(event) => {
                setActiveIndex(0)
                onQueryChange(event.target.value)
              }}
              onKeyDown={(event) => handleCustomerSearchKeyDown(
                event,
                safeActiveIndex,
                customers,
                setActiveIndex,
                handleSelect,
                () => setIsOpen(false),
              )}
              placeholder="Digite o nome do cliente..."
              ref={searchInputRef}
              role="combobox"
              value={query}
            />
          </label>
          <div className="customer-results" id={listboxId} role="listbox">
            {isLoading ? <span className="customer-results__state">Buscando clientes...</span> : null}
            {!isLoading && customers.length === 0 ? (
              <span className="customer-results__state">Nenhum cliente encontrado.</span>
            ) : null}
            {customers.map((customer, index) => (
              <button
                aria-selected={selectedCustomer?.id === customer.id}
                className={index === safeActiveIndex ? 'customer-result is-active' : 'customer-result'}
                id={`${listboxId}-option-${customer.id}`}
                key={customer.id}
                onClick={() => handleSelect(customer)}
                role="option"
                type="button"
              >
                <strong>{customer.name}</strong>
                <span>{customer.phoneLabel}</span>
              </button>
            ))}
          </div>
          {walkInName && !selectedCustomer ? (
            <button className="customer-select__walk-in" onClick={() => setIsOpen(false)} type="button">
              Continuar com "{walkInName}" sem cadastrar
            </button>
          ) : null}
          <button className="customer-select__create" onClick={onCreateNewCustomer} type="button">
            + Cadastrar novo cliente
          </button>
        </div>
      ) : null}
    </div>
  )
}

function BeefChoicePicker({
  dailyMeats,
  extraBeef,
  isExtraBeefSelected,
  meatConfiguration,
  meatMode,
  onExtraBeefChange,
  onMeatModeChange,
  onSelectedOptionsChange,
  selectedOptionIds,
}: {
  dailyMeats: DailyMenuComponent[]
  extraBeef: StructuredProductAddition | null
  isExtraBeefSelected: boolean
  meatConfiguration: StructuredMeatConfiguration
  meatMode: MeatModeSelection
  onExtraBeefChange: (value: boolean) => void
  onMeatModeChange: (value: MeatModeSelection) => void
  onSelectedOptionsChange: (optionIds: string[]) => void
  selectedOptionIds: string[]
}) {
  const selectedMeatIds = selectedOptionIds
    .filter(isDailyMeatToken)
    .map((token) => Number(token.replace('daily-meat:', '')))
    .filter((value) => Number.isFinite(value))
  const minMeats = meatConfiguration.traditional.selection_rules.min ?? 0
  const maxMeats = meatConfiguration.traditional.selection_rules.max
  const selectedCount = selectedMeatIds.length
  const beefOnlyPrice = meatConfiguration.beef_only.final_price_cents
  const canUseBeefOnly = meatConfiguration.beef_only.enabled && beefOnlyPrice !== null
  const canUseExtraBeef = extraBeef?.enabled ?? false

  function toggleMeat(componentId: number) {
    const token = dailyMeatToken(componentId)
    const checked = selectedOptionIds.includes(token)

    if (!checked && maxMeats !== null && selectedCount >= maxMeats) {
      return
    }

    onSelectedOptionsChange(
      checked
        ? selectedOptionIds.filter((optionId) => optionId !== token)
        : [...selectedOptionIds, token],
    )
  }

  return (
    <div className="option-picker">
      <div>
        <strong>Escolha da carne</strong>
        <p>Escolha as carnes tradicionais ou use somente bife, conforme a regra da marmita.</p>
      </div>
      <div className="option-picker__group">
        <div className="option-picker__grid">
          <label className={optionChoiceClassName(meatMode === 'traditional', false)}>
            <input
              checked={meatMode === 'traditional'}
              name="meat-mode"
              onChange={() => onMeatModeChange('traditional')}
              type="radio"
            />
            <span className="option-choice__box" aria-hidden="true" />
            <span className="option-choice__content">
              <strong>Carnes tradicionais</strong>
              <small>Escolha as carnes normalmente conforme a regra da marmita.</small>
            </span>
          </label>
          <label className={optionChoiceClassName(meatMode === 'beef_only', !canUseBeefOnly)}>
            <input
              checked={meatMode === 'beef_only'}
              disabled={!canUseBeefOnly}
              name="meat-mode"
              onChange={() => onMeatModeChange('beef_only')}
              type="radio"
            />
            <span className="option-choice__box" aria-hidden="true" />
            <span className="option-choice__content">
              <strong>Somente bife{beefOnlyPrice !== null ? ` - ${formatCurrency(beefOnlyPrice / 100)}` : ''}</strong>
              <small>O bife substitui todas as carnes tradicionais.</small>
            </span>
          </label>
        </div>
      </div>

      {meatMode === 'traditional' ? (
        <div className="option-picker__group">
          <div className="option-picker__heading">
            <span>Carnes do dia</span>
            <small>
              Escolha {minMeats === maxMeats ? minMeats : `de ${minMeats} a ${maxMeats ?? 'varias'}`} carne
              {minMeats === 1 && maxMeats === 1 ? '' : 's'}.
            </small>
          </div>
          <div className="option-picker__grid">
            {dailyMeats.length > 0 ? dailyMeats.map((item) => {
              const token = dailyMeatToken(item.component.id)
              const checked = selectedOptionIds.includes(token)
              const disabled = !item.available || (!checked && maxMeats !== null && selectedCount >= maxMeats)
              const supportingName = item.component.supporting_name && item.component.supporting_name !== item.component.display_name
                ? item.component.supporting_name
                : null

              return (
                <label className={optionChoiceClassName(checked, disabled)} key={token}>
                  <input
                    checked={checked}
                    disabled={disabled}
                    onChange={() => toggleMeat(item.component.id)}
                    type="checkbox"
                  />
                  <span className="option-choice__box" aria-hidden="true" />
                  <span className="option-choice__content">
                    <strong>{item.component.display_name || item.component.name}</strong>
                    <small>{supportingName ?? (item.available ? 'Disponivel hoje' : 'Indisponivel hoje')}</small>
                  </span>
                </label>
              )
            }) : (
              <p className="muted-text">Nenhuma carne disponivel no cardapio desta data.</p>
            )}
          </div>
          {canUseExtraBeef ? (
            <label className="checkbox-line">
              <input
                checked={isExtraBeefSelected}
                onChange={(event) => onExtraBeefChange(event.target.checked)}
                type="checkbox"
              />
              Adicionar 1 bife - + {formatCurrency((extraBeef?.price_cents ?? 0) / 100)}
            </label>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}

function StructuredOptionPicker({
  groups,
  onSelectedOptionsChange,
  selectedOptionIds,
}: {
  groups: StructuredProductOptionGroup[]
  onSelectedOptionsChange: (optionIds: string[]) => void
  selectedOptionIds: string[]
}) {
  return (
    <div className="option-picker">
      <div>
        <strong>Regras do produto</strong>
        <p>Componentes fixos ja acompanham o item. Escolha somente o que precisa ser decidido na montagem.</p>
      </div>
      {groups.map((group) => {
        const componentTokens = group.component_options.map((option) => componentOptionToken(option.id))
        const productTokens = group.product_options.map((option) => productOptionToken(option.id))
        const groupTokens = [...componentTokens, ...productTokens]
        const isSingle = group.max_choices === 1 || ['single', 'included_choice', 'variation'].includes(group.selection_mode)

        if (group.selection_mode === 'fixed') {
          return (
            <div className="option-picker__group" key={group.id}>
              <div className="option-picker__heading">
                <span>{includedGroupLabel(group.label)}</span>
                <small>Desmarque somente quando o cliente pedir para retirar.</small>
              </div>
              <div className="option-picker__grid">
                {group.component_options.map((option) => {
                  const token = removedComponentToken(option.component_id)
                  const disabled = !option.link_active || option.requires_confirmation || !option.available
                  const checked = !disabled && !selectedOptionIds.includes(token)

                  return (
                    <label className={optionChoiceClassName(checked, disabled)} key={option.id}>
                      <input
                        checked={checked}
                        disabled={disabled}
                        onChange={() => {
                          onSelectedOptionsChange(
                            checked
                              ? [...selectedOptionIds, token]
                              : selectedOptionIds.filter((optionId) => optionId !== token),
                          )
                        }}
                        type="checkbox"
                      />
                      <span className="option-choice__box" aria-hidden="true" />
                      <span className="option-choice__content">
                        <strong>{option.name}</strong>
                        <small>{fixedComponentHint(option, checked)}</small>
                      </span>
                    </label>
                  )
                })}
              </div>
            </div>
          )
        }

        return (
          <div className="option-picker__group" key={group.id}>
            <div className="option-picker__heading">
              <span>{group.label}</span>
              <small>{groupHelperText(group)}</small>
            </div>
            <div className="option-picker__grid">
              {group.component_options.map((option) => {
                const token = componentOptionToken(option.id)
                const checked = selectedOptionIds.includes(token)
                const disabled = !option.link_active || option.requires_confirmation || !option.available

                return (
                  <label className={optionChoiceClassName(checked, disabled)} key={token}>
                    <input
                      checked={checked}
                      disabled={disabled}
                      name={`structured-group-${group.id}`}
                      onChange={() => onSelectedOptionsChange(toggleStructuredSelection(selectedOptionIds, token, groupTokens, isSingle))}
                      type={isSingle ? 'radio' : 'checkbox'}
                    />
                    <span className="option-choice__box" aria-hidden="true" />
                    <span className="option-choice__content">
                      <strong>{option.name}</strong>
                      <small>{structuredOptionHint(option)}</small>
                    </span>
                  </label>
                )
              })}
              {group.product_options.map((option) => {
                const token = productOptionToken(option.id)
                const checked = selectedOptionIds.includes(token)
                const disabled = !option.link_active || option.requires_confirmation || !option.available

                return (
                  <label className={optionChoiceClassName(checked, disabled)} key={token}>
                    <input
                      checked={checked}
                      disabled={disabled}
                      name={`structured-group-${group.id}`}
                      onChange={() => onSelectedOptionsChange(toggleStructuredSelection(selectedOptionIds, token, groupTokens, isSingle))}
                      type={isSingle ? 'radio' : 'checkbox'}
                    />
                    <span className="option-choice__box" aria-hidden="true" />
                    <span className="option-choice__content">
                      <strong>{option.selectable_product.name}</strong>
                      <small>{structuredProductOptionHint(option)}</small>
                    </span>
                  </label>
                )
              })}
            </div>
          </div>
        )
      })}
    </div>
  )
}

function LegacyOptionPicker({
  groups,
  onSelectedOptionsChange,
  selectedOptionIds,
}: {
  groups: Array<{ groupLabel: string; options: MenuOption[] }>
  onSelectedOptionsChange: (optionIds: string[]) => void
  selectedOptionIds: string[]
}) {
  return (
    <div className="option-picker">
      <div>
        <strong>Opcoes legadas</strong>
        <p>Este produto ainda usa o cadastro legado de opcoes.</p>
      </div>
      {groups.map((group) => (
        <div className="option-picker__group" key={group.groupLabel}>
          <span>{group.groupLabel}</span>
          <div className="option-picker__grid">
            {group.options.map((option) => {
              const checked = selectedOptionIds.includes(option.id)

              return (
                <label className={optionChoiceClassName(checked, !option.availableToday)} key={option.id}>
                  <input
                    checked={checked}
                    disabled={!option.availableToday}
                    onChange={() => {
                      const nextIds = checked
                        ? selectedOptionIds.filter((optionId) => optionId !== option.id)
                        : [...selectedOptionIds, option.id]

                      onSelectedOptionsChange(nextIds)
                    }}
                    type="checkbox"
                  />
                  <span className="option-choice__box" aria-hidden="true" />
                  <span className="option-choice__content">
                    <strong>{option.name}</strong>
                    <small>
                      {option.availableToday
                        ? option.priceDelta > 0
                          ? `+ ${formatCurrency(option.priceDelta)}`
                          : 'Sem adicional'
                        : option.dailyReason ?? 'Esgotado hoje'}
                    </small>
                  </span>
                </label>
              )
            })}
          </div>
        </div>
      ))}
    </div>
  )
}

function AddItemCompositionSummary({
  extraBeefSelected,
  meatMode,
  product,
  quantity,
  selectedOptionIds,
}: {
  extraBeefSelected: boolean
  meatMode: MeatModeSelection
  product: Product
  quantity: number
  selectedOptionIds: string[]
}) {
  const summary = buildCompositionSummary(product, selectedOptionIds, meatMode, extraBeefSelected)
  const unitPrice = summary.unitPrice
  const subtotal = unitPrice * Math.max(1, quantity)

  return (
    <aside className="add-item-summary" aria-label="Resumo do item">
      <div className="add-item-summary__header">
        <div>
          <span className="mini-label">Resumo</span>
          <strong>{product.name}</strong>
        </div>
        <Badge tone="brand">{formatCurrency(unitPrice)}</Badge>
      </div>
      {summary.composition.length > 0 ? (
        <div>
          <span>Composição</span>
          <p>{summary.composition.join(', ')}</p>
        </div>
      ) : null}
      {summary.removals.length > 0 ? (
        <div>
          <span>Retirados</span>
          <p>{summary.removals.join(', ')}</p>
        </div>
      ) : null}
      {summary.additions.length > 0 ? (
        <div>
          <span>Adicionais</span>
          <p>{summary.additions.join(', ')}</p>
        </div>
      ) : null}
      <div className="add-item-summary__footer">
        <span>{Math.max(1, quantity)} unidade(s)</span>
        <strong>Subtotal {formatCurrency(subtotal)}</strong>
      </div>
    </aside>
  )
}

function buildCompositionSummary(
  product: Product,
  selectedOptionIds: string[],
  meatMode: MeatModeSelection,
  extraBeefSelected: boolean,
): { composition: string[]; removals: string[]; additions: string[]; unitPrice: number } {
  const selectedTokens = new Set(selectedOptionIds)
  const composition: string[] = []
  const removals: string[] = []
  const additions: string[] = []

  for (const group of product.structuredGroups ?? []) {
    if (product.meatConfiguration && ['variacao_bife', 'bife_adicional'].includes(group.code)) {
      continue
    }

    if (group.selection_mode === 'fixed') {
      for (const option of group.component_options) {
        if (!option.link_active || option.requires_confirmation || !option.available) {
          continue
        }

        if (selectedTokens.has(removedComponentToken(option.component_id))) {
          removals.push(`Sem ${option.name}`)
        } else {
          composition.push(option.name)
        }
      }

      continue
    }

    for (const option of group.component_options) {
      if (selectedTokens.has(componentOptionToken(option.id))) {
        composition.push(option.name)
      }
    }

    for (const option of group.product_options) {
      if (selectedTokens.has(productOptionToken(option.id))) {
        composition.push(option.selectable_product.name)
      }
    }
  }

  if (product.meatConfiguration) {
    if (meatMode === 'beef_only') {
      composition.push('Somente bife')
    } else {
      for (const meat of product.dailyMeatOptions ?? []) {
        if (selectedTokens.has(dailyMeatToken(meat.component.id))) {
          composition.push(meat.component.display_name || meat.component.name)
        }
      }

      if (extraBeefSelected) {
        const extraBeef = product.additions?.find((addition) => addition.code === 'extra_beef')
        additions.push(`Bife adicional${extraBeef ? ` - ${formatCurrency(extraBeef.price_cents / 100)}` : ''}`)
      }
    }
  }

  const beefOnlyPrice = product.meatConfiguration?.beef_only.final_price_cents
  const extraBeef = product.additions?.find((addition) => addition.code === 'extra_beef')
  const unitPrice = meatMode === 'beef_only' && beefOnlyPrice !== null && beefOnlyPrice !== undefined
    ? beefOnlyPrice / 100
    : product.price + (extraBeefSelected && extraBeef ? extraBeef.price_cents / 100 : 0)

  return { composition, removals, additions, unitPrice }
}

function handleCustomerSearchKeyDown(
  event: KeyboardEvent<HTMLInputElement>,
  activeIndex: number,
  customers: CustomerSummary[],
  setActiveIndex: (index: number) => void,
  onSelectCustomer: (customer: CustomerSummary) => void,
  onClose: () => void,
) {
  if (event.key === 'Escape') {
    event.preventDefault()
    onClose()
    return
  }

  if (customers.length === 0) {
    return
  }

  if (event.key === 'ArrowDown') {
    event.preventDefault()
    setActiveIndex(Math.min(activeIndex + 1, customers.length - 1))
  }

  if (event.key === 'ArrowUp') {
    event.preventDefault()
    setActiveIndex(Math.max(activeIndex - 1, 0))
  }

  if (event.key === 'Enter') {
    event.preventDefault()
    const customer = customers[activeIndex]

    if (customer) {
      onSelectCustomer(customer)
    }
  }
}

function toggleStructuredSelection(current: string[], token: string, groupTokens: string[], isSingle: boolean): string[] {
  if (current.includes(token)) {
    return isSingle ? current : current.filter((optionId) => optionId !== token)
  }

  if (isSingle) {
    return [...current.filter((optionId) => !groupTokens.includes(optionId)), token]
  }

  return [...current, token]
}

function structuredOptionHint(option: StructuredComponentOption): string {
  if (!option.link_active || option.requires_confirmation) {
    return 'Configuracao pendente'
  }

  if (!option.available) {
    return option.availability.reason ?? 'Indisponivel hoje'
  }

  if (option.final_price_cents !== null) {
    return `Valor final ${formatCurrency(option.final_price_cents / 100)}`
  }

  if (option.price_delta_cents > 0) {
    return `+ ${formatCurrency(option.price_delta_cents / 100)}`
  }

  return 'Incluido no preco'
}

function structuredProductOptionHint(option: StructuredProductOption): string {
  if (!option.link_active || option.requires_confirmation) {
    return 'Configuracao pendente'
  }

  if (!option.available) {
    return option.availability.reason ?? 'Indisponivel hoje'
  }

  if (option.final_price_cents !== null) {
    return `Valor final ${formatCurrency(option.final_price_cents / 100)}`
  }

  if (option.price_delta_cents > 0) {
    return `+ ${formatCurrency(option.price_delta_cents / 100)}`
  }

  return 'Incluido no combo'
}

function groupHelperText(group: StructuredProductOptionGroup): string {
  const choices = group.max_choices === 1 ? 'escolha uma opcao' : `ate ${group.max_choices ?? 'varias'} opcoes`
  const quantity = group.min_quantity && group.max_quantity && group.min_quantity === group.max_quantity
    ? ` · ${group.min_quantity} unidade${group.min_quantity > 1 ? 's' : ''}`
    : ''
  const sameOnly = group.same_component_only ? ' · sem mistura' : ''

  return `${group.required ? 'Obrigatorio' : 'Opcional'} · ${choices}${quantity}${sameOnly}`
}

function optionChoiceClassName(checked: boolean, disabled: boolean): string {
  return [
    'option-choice',
    checked ? 'is-selected' : '',
    disabled ? 'is-disabled' : '',
  ].filter(Boolean).join(' ')
}

function componentOptionToken(id: number): string {
  return `component:${id}`
}

function productOptionToken(id: number): string {
  return `product:${id}`
}

function fixedComponentHint(option: StructuredComponentOption, checked: boolean): string {
  if (!option.link_active || option.requires_confirmation) {
    return 'Configuração pendente'
  }

  if (!option.available) {
    return option.availability.reason ?? 'Indisponível hoje'
  }

  return checked ? 'Incluído no preço' : 'Retirar deste item'
}

function dailyMeatToken(id: number): string {
  return `daily-meat:${id}`
}

function removedComponentToken(id: number): string {
  return `remove-component:${id}`
}

function isDailyMeatToken(token: string): boolean {
  return token.startsWith('daily-meat:')
}

function includedGroupLabel(label: string): string {
  if (label.toLowerCase().includes('fix')) {
    return 'Incluídos na marmita'
  }

  return label
}

function groupOptions(options: MenuOption[]): Array<{ groupLabel: string; options: MenuOption[] }> {
  const orderedLabels = ['Bases/guarnicoes', 'Saladas', 'Carnes', 'Bebidas', 'Adicionais', 'Componentes']

  return orderedLabels
    .map((groupLabel) => ({
      groupLabel,
      options: options.filter((option) => option.groupLabel === groupLabel),
    }))
    .filter((group) => group.options.length > 0)
}
