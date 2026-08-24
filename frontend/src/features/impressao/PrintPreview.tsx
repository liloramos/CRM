import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { Badge } from '../../components/ui/Badge'
import type { Order } from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'
import { getOrderOperationalState } from '../pedidos/orderOperationalState'

type PrintPreviewProps = {
  order: Order
  onPreviewTicket: (orderId: string) => void
  onPrintTicket: (orderId: string) => void
}

export function PrintPreview({ onPreviewTicket, onPrintTicket, order }: PrintPreviewProps) {
  const orderState = getOrderOperationalState(order)

  return (
    <Card className="print-panel">
      <SectionTitle
        action={<Badge tone={orderState.printBadge.tone}>{orderState.printBadge.label}</Badge>}
        eyebrow="Fluxo obrigatorio"
        title="Comanda / prévia de impressão"
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
          {order.deliveryLabel ? (
            <div className="receipt-row receipt-row--stack">
              <span>Entrega / referencia</span>
              <strong>{order.deliveryLabel}</strong>
            </div>
          ) : null}
          <hr />
          {order.items.map((item) => (
            <div className="receipt-item" key={item.id}>
              <strong>
                {item.quantity}x {item.name}
              </strong>
              {item.beneficiary ? <span>Para: {item.beneficiary}</span> : null}
              {item.additions.length > 0 ? <span>Opcoes: {item.additions.join(', ')}</span> : null}
              <span>Obs: {item.notes}</span>
              <b>{formatCurrency(item.totalPrice ?? item.quantity * item.unitPrice)}</b>
            </div>
          ))}
          <hr />
          <div className="receipt-row">
            <span>Entrega</span>
            <strong>{formatCurrency(order.deliveryFee)}</strong>
          </div>
          <div className="receipt-row">
            <span>Credito usado</span>
            <strong>{formatCurrency(order.creditUsed)}</strong>
          </div>
          <div className="receipt-row">
            <span>Pago</span>
            <strong>{formatCurrency(order.paid)}</strong>
          </div>
          <div className="receipt-row">
            <span>Falta</span>
            <strong>{formatCurrency(order.amountDue)}</strong>
          </div>
          <div className="receipt-row receipt-row--total">
            <span>Total</span>
            <strong>{formatCurrency(order.total)}</strong>
          </div>
          <p className="receipt-note">{order.generalNotes}</p>
          <p className="receipt-note">Prévia da comanda. A impressão física depende da configuração disponível.</p>
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
              <p>Prévia antes da impressão térmica.</p>
            </div>
          </div>
          <div className={order.printStatus === 'impresso' ? 'print-step is-done' : 'print-step'}>
            <span>3</span>
            <div>
              <strong>Imprimir antes do preparo</strong>
              <p>Bloqueio operacional até a impressão ou autorização manual.</p>
            </div>
          </div>
          <div className="print-actions">
            <Button disabled={!orderState.canPrint} icon="printer" onClick={() => onPrintTicket(order.id)} variant="primary">
              Imprimir comanda
            </Button>
            <Button disabled={!orderState.canPrint} icon="printer" onClick={() => onPreviewTicket(order.id)} variant="secondary">
              Gerar previa HTML
            </Button>
            <Button disabled={!orderState.canPrint} icon="arrow" onClick={() => onPreviewTicket(order.id)} variant="ghost">
              Regerar previa
            </Button>
          </div>
          {!orderState.canPrint ? (
            <p className="muted-text">
              {orderState.isCancelled ? 'Pedido cancelado não libera impressão operacional.' : 'Adicione itens antes de gerar a comanda.'}
            </p>
          ) : null}
        </div>
      </div>
    </Card>
  )
}
