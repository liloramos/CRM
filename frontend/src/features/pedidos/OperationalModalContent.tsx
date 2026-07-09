import { EmptyState, LoadingState } from '../../components/ui/States'
import type { AppModal, MenuOption, Order, PrintPreviewResult, Product } from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

type OperationalModalContentProps = {
  actionError: string | null
  beneficiaryName: string
  isActionBusy: boolean
  itemNotes: string
  itemQuantity: number
  modal: AppModal
  onBeneficiaryNameChange: (value: string) => void
  onItemNotesChange: (value: string) => void
  onItemQuantityChange: (value: number) => void
  onProductChange: (productId: string) => void
  onSelectedOptionsChange: (optionIds: string[]) => void
  printPreview: PrintPreviewResult | null
  products: Product[]
  selectedOrder?: Order
  selectedOptionIds: string[]
  selectedProductId: string
}

export function OperationalModalContent({
  actionError,
  beneficiaryName,
  isActionBusy,
  itemNotes,
  itemQuantity,
  modal,
  onBeneficiaryNameChange,
  onItemNotesChange,
  onItemQuantityChange,
  onProductChange,
  onSelectedOptionsChange,
  printPreview,
  products,
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
            Pedido <strong>{selectedOrder.code}</strong>. O item sera adicionado como edicao operacional enquanto o pedido ainda
            estiver aberto.
          </p>
        ) : (
          <p>Crie ou selecione um pedido antes de adicionar itens.</p>
        )}
        <label>
          Produto
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
              <p>A opcao esgotada hoje fica bloqueada, mas o produto continua vendavel.</p>
            </div>
            {optionGroups.map((group) => (
              <div className="option-picker__group" key={group.groupLabel}>
                <span>{group.groupLabel}</span>
                <div className="option-picker__grid">
                  {group.options.map((option) => {
                    const checked = selectedOptionIds.includes(option.id)

                    return (
                      <label className={option.availableToday ? 'option-choice' : 'option-choice is-disabled'} key={option.id}>
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
                        <span>
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
            placeholder="Ex.: sem salada, retirar cebola, separar para retirada."
            value={itemNotes}
          />
        </label>
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
    return (
      <div className="mode-options">
        <label>
          <input defaultChecked name="mode" type="radio" />
          IA assistindo com revisao humana
        </label>
        <label>
          <input name="mode" type="radio" />
          Atendimento manual
        </label>
        <p>A IA nunca confirma ambiguidades, pagamentos, credito ou entrega sozinha.</p>
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

function groupOptions(options: MenuOption[]): Array<{ groupLabel: string; options: MenuOption[] }> {
  const orderedLabels = ['Bases/guarnicoes', 'Saladas', 'Carnes', 'Bebidas', 'Adicionais', 'Componentes']

  return orderedLabels
    .map((groupLabel) => ({
      groupLabel,
      options: options.filter((option) => option.groupLabel === groupLabel),
    }))
    .filter((group) => group.options.length > 0)
}
