import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { StatusBadge } from '../../components/ui/StatusBadge'
import type { Order } from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

type PrintPreviewProps = {
  order: Order
  onPreviewTicket: (orderId: string) => void
}

export function PrintPreview({ onPreviewTicket, order }: PrintPreviewProps) {
  return (
    <Card className="print-panel">
      <SectionTitle
        action={<StatusBadge status={order.printStatus} type="print" />}
        eyebrow="Fluxo obrigatório"
        title="Registro / prévia operacional"
      />

      <div className="print-panel__layout">
        <div className="receipt-preview" aria-label="Prévia visual operacional">
          <div className="receipt-preview__brand">
            <strong>MARCELO QUESSADA</strong>
            <span>REGISTRO OPERACIONAL</span>
          </div>
          <div className="receipt-row">
            <span>Operação</span>
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
              <span>Para: {item.beneficiary}</span>
              {item.additions.length > 0 ? <span>Opcoes: {item.additions.join(', ')}</span> : null}
              <span>Obs: {item.notes}</span>
              <b>{formatCurrency(item.quantity * item.unitPrice)}</b>
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
          <p className="receipt-note">Prévia HTML. Emissão final depende de confirmação/configuração.</p>
        </div>

        <div className="print-panel__side">
          <div className="print-step is-current">
            <span>1</span>
            <div>
              <strong>Conferir operação</strong>
              <p>Itens, beneficiários, responsável e status financeiro.</p>
            </div>
          </div>
          <div className="print-step is-current">
            <span>2</span>
            <div>
              <strong>Gerar registro</strong>
              <p>Prévia HTML antes da emissão final.</p>
            </div>
          </div>
          <div className={order.printStatus === 'impresso' ? 'print-step is-done' : 'print-step'}>
            <span>3</span>
            <div>
              <strong>Emitir antes da conclusão</strong>
              <p>Bloqueio operacional até emissão ou autorização manual.</p>
            </div>
          </div>
          <div className="print-actions">
            <Button icon="printer" onClick={() => onPreviewTicket(order.id)} variant="primary">
              Gerar prévia HTML
            </Button>
            <Button icon="arrow" onClick={() => onPreviewTicket(order.id)} variant="secondary">
              Regerar prévia
            </Button>
          </div>
        </div>
      </div>
    </Card>
  )
}
