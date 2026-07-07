import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { StatusBadge } from '../../components/ui/StatusBadge'
import type { AppModal, Order } from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

type PrintPreviewProps = {
  order: Order
  onOpenModal: (modal: AppModal) => void
}

export function PrintPreview({ onOpenModal, order }: PrintPreviewProps) {
  return (
    <Card className="print-panel">
      <SectionTitle
        action={<StatusBadge status={order.printStatus} type="print" />}
        eyebrow="Fluxo obrigatorio"
        title="Comanda / previa de impressao"
      />

      <div className="print-panel__layout">
        <div className="receipt-preview" aria-label="Previa visual da comanda">
          <div className="receipt-preview__brand">
            <strong>SOL RESTAURANTE</strong>
            <span>COMANDA DE PEDIDO</span>
          </div>
          <div className="receipt-row">
            <span>Pedido</span>
            <strong>{order.code}</strong>
          </div>
          <div className="receipt-row">
            <span>Canal</span>
            <strong>{order.channel}</strong>
          </div>
          <div className="receipt-row">
            <span>Tipo</span>
            <strong>{order.fulfillmentType}</strong>
          </div>
          <hr />
          <div className="receipt-row receipt-row--stack">
            <span>Cliente pagador</span>
            <strong>{order.customer.name}</strong>
          </div>
          {order.pickupPerson ? (
            <div className="receipt-row receipt-row--stack">
              <span>Retirada por</span>
              <strong>{order.pickupPerson}</strong>
            </div>
          ) : null}
          <hr />
          {order.items.map((item) => (
            <div className="receipt-item" key={item.id}>
              <strong>
                {item.quantity}x {item.name}
              </strong>
              <span>Para: {item.beneficiary}</span>
              <span>Obs: {item.notes}</span>
              <b>{formatCurrency(item.quantity * item.unitPrice)}</b>
            </div>
          ))}
          <hr />
          <div className="receipt-row">
            <span>Credito usado</span>
            <strong>{formatCurrency(order.creditUsed)}</strong>
          </div>
          <div className="receipt-row receipt-row--total">
            <span>Total</span>
            <strong>{formatCurrency(order.total)}</strong>
          </div>
          <p className="receipt-note">{order.generalNotes}</p>
        </div>

        <div className="print-panel__side">
          <div className="print-step is-current">
            <span>1</span>
            <div>
              <strong>Conferir pedido</strong>
              <p>Itens, beneficiarios, retirada/entrega e pagamento.</p>
            </div>
          </div>
          <div className="print-step is-current">
            <span>2</span>
            <div>
              <strong>Gerar comanda</strong>
              <p>Previa HTML antes da impressao termica.</p>
            </div>
          </div>
          <div className={order.printStatus === 'impresso' ? 'print-step is-done' : 'print-step'}>
            <span>3</span>
            <div>
              <strong>Imprimir antes do preparo</strong>
              <p>Bloqueio operacional ate impressao ou autorizacao manual.</p>
            </div>
          </div>
          <div className="print-actions">
            <Button icon="printer" onClick={() => onOpenModal('print-error')} variant="primary">
              Imprimir comanda
            </Button>
            <Button icon="arrow" onClick={() => onOpenModal('print-error')} variant="secondary">
              Reimprimir
            </Button>
          </div>
        </div>
      </div>
    </Card>
  )
}
