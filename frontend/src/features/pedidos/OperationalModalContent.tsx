import { EmptyState, LoadingState } from '../../components/ui/States'
import { Icon } from '../../components/ui/Icon'
import type { AppModal, Conversation, MenuOption, Order, PrintPreviewResult, Product } from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

export type AutomationModeSelection = 'assisted' | 'manual'

type OperationalModalContentProps = {
  actionError: string | null
  automationMode: AutomationModeSelection
  beneficiaryName: string
  isActionBusy: boolean
  itemNotes: string
  itemQuantity: number
  modal: AppModal
  onAutomationModeChange: (mode: AutomationModeSelection) => void
  onBeneficiaryNameChange: (value: string) => void
  onItemNotesChange: (value: string) => void
  onItemQuantityChange: (value: number) => void
  onProductChange: (productId: string) => void
  onSelectedOptionsChange: (optionIds: string[]) => void
  printPreview: PrintPreviewResult | null
  products: Product[]
  selectedConversation?: Conversation
  selectedOrder?: Order
  selectedOptionIds: string[]
  selectedProductId: string
}

export function OperationalModalContent({
  actionError,
  automationMode,
  beneficiaryName,
  isActionBusy,
  itemNotes,
  itemQuantity,
  modal,
  onAutomationModeChange,
  onBeneficiaryNameChange,
  onItemNotesChange,
  onItemQuantityChange,
  onProductChange,
  onSelectedOptionsChange,
  printPreview,
  products,
  selectedConversation,
  selectedOrder,
  selectedOptionIds,
  selectedProductId,
}: OperationalModalContentProps) {
  if (modal === 'add-product') {
    const selectedProduct = products.find((product) => product.id === selectedProductId) ?? products[0]
    const optionGroups = groupOptions(selectedProduct?.options ?? [])

    return (
      <div className="modal-fields">
        {selectedOrder ? (
          <p>
            Operação <strong>{selectedOrder.code}</strong>. O item será adicionado como edição operacional enquanto o registro ainda
            estiver aberto.
          </p>
        ) : (
          <p>Crie ou selecione uma operação antes de adicionar itens.</p>
        )}
        <label>
          Item
          <select onChange={(event) => onProductChange(event.target.value)} value={selectedProductId}>
            {products.map((product) => (
              <option key={product.id} value={product.id}>
                {product.name} - {formatCurrency(product.price)}
              </option>
            ))}
          </select>
        </label>
        {selectedProduct && optionGroups.length > 0 ? (
          <div className="option-picker">
            <div>
              <strong>Componentes e opcoes</strong>
              <p>A opção indisponível hoje fica bloqueada, mas o item principal continua disponível.</p>
            </div>
            {optionGroups.map((group) => (
              <div className="option-picker__group" key={group.groupLabel}>
                <span>{group.groupLabel}</span>
                <div className="option-picker__grid">
                  {group.options.map((option) => {
                    const checked = selectedOptionIds.includes(option.id)

                    return (
                      <label
                        className={[
                          'option-choice',
                          checked ? 'is-selected' : '',
                          option.availableToday ? '' : 'is-disabled',
                        ].filter(Boolean).join(' ')}
                        key={option.id}
                      >
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
        <label>
          Beneficiario / quem vai receber
          <input
            onChange={(event) => onBeneficiaryNameChange(event.target.value)}
            placeholder={selectedOrder?.customer.name ?? 'Nome a confirmar'}
            value={beneficiaryName}
          />
        </label>
        <label>
          Observacao por item
          <textarea
            onChange={(event) => onItemNotesChange(event.target.value)}
            placeholder="Ex.: observação específica para revisão comercial."
            value={itemNotes}
          />
        </label>
        {actionError ? <p className="form-error">{actionError}</p> : null}
      </div>
    )
  }

  if (modal === 'print-preview') {
    if (isActionBusy && !printPreview) {
      return <LoadingState description="Renderizando prévia HTML pelo Laravel..." title="Gerando prévia" />
    }

    return (
      <div className="modal-fields">
        {actionError ? <p className="form-error">{actionError}</p> : null}
        {printPreview?.html ? (
          <iframe className="ticket-frame" srcDoc={printPreview.html} title="Prévia HTML operacional" />
        ) : (
          <EmptyState description="A prévia será exibida aqui quando o backend gerar o registro." title="Sem prévia carregada" />
        )}
      </div>
    )
  }

  if (modal === 'confirm-payment') {
    return (
      <div className="modal-fields">
        <label>
          Status
          <select defaultValue="revisao">
            <option value="revisao">Conferencia humana</option>
            <option value="confirmado">Confirmado pelo atendente</option>
          </select>
        </label>
        <label>
          Observacao
          <textarea placeholder="Registrar decisao sem anexar comprovante real." />
        </label>
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
        <p>Opções previstas: tentar novamente, reemitir, copiar registro ou marcar como emitido manualmente.</p>
        <label>
          Motivo operacional
          <textarea placeholder="Descreva a falha de emissão de forma objetiva." />
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

function groupOptions(options: MenuOption[]): Array<{ groupLabel: string; options: MenuOption[] }> {
  const orderedLabels = ['Segmentos', 'Serviços', 'Campanhas', 'Canais', 'Extras', 'Componentes']

  return orderedLabels
    .map((groupLabel) => ({
      groupLabel,
      options: options.filter((option) => option.groupLabel === groupLabel),
    }))
    .filter((group) => group.options.length > 0)
}
