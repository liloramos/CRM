import { EmptyState, LoadingState } from '../../components/ui/States'
import type { AppModal, Order, PrintPreviewResult, Product } from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

type OperationalModalContentProps = {
  actionError: string | null
  isActionBusy: boolean
  itemNotes: string
  itemQuantity: number
  modal: AppModal
  onItemNotesChange: (value: string) => void
  onItemQuantityChange: (value: number) => void
  onProductChange: (productId: string) => void
  printPreview: PrintPreviewResult | null
  products: Product[]
  selectedOrder?: Order
  selectedProductId: string
}

export function OperationalModalContent({
  actionError,
  isActionBusy,
  itemNotes,
  itemQuantity,
  modal,
  onItemNotesChange,
  onItemQuantityChange,
  onProductChange,
  printPreview,
  products,
  selectedOrder,
  selectedProductId,
}: OperationalModalContentProps) {
  if (modal === 'add-product') {
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
