import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { DataTable, type DataTableColumn } from '../../components/ui/DataTable'
import { EmptyState, LoadingState } from '../../components/ui/States'
import { StatusBadge } from '../../components/ui/StatusBadge'
import { PrintPreview } from '../impressao/PrintPreview'
import type { AppModal, Order, OrderItem } from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

type OrdersPageProps = {
  isLoading: boolean
  orders: Order[]
  selectedOrder?: Order
  onNewOrder: () => void
  onOpenModal: (modal: AppModal) => void
  onPreviewTicket: (orderId: string) => void
  onSelectOrder: (orderId: string) => void
}

const itemColumns: DataTableColumn<OrderItem>[] = [
  {
    key: 'item',
    header: 'Item',
    render: (item) => (
      <div className="table-main">
        <strong>{item.name}</strong>
        {item.additions.length > 0 ? <small>{item.additions.join(', ')}</small> : null}
        <span>{item.notes}</span>
      </div>
    ),
  },
  { key: 'beneficiary', header: 'Para', render: (item) => item.beneficiary },
  { key: 'quantity', header: 'Qtd.', render: (item) => `${item.quantity}x`, align: 'right' },
  { key: 'total', header: 'Subtotal', render: (item) => formatCurrency(item.quantity * item.unitPrice), align: 'right' },
]

export function OrdersPage({
  isLoading,
  onNewOrder,
  onOpenModal,
  onPreviewTicket,
  onSelectOrder,
  orders,
  selectedOrder,
}: OrdersPageProps) {
  return (
    <PageContainer density="wide">
      <PageHeader
        actions={
          <>
            <Button icon="plus" onClick={selectedOrder ? () => onOpenModal('add-product') : onNewOrder} variant="secondary">
              {selectedOrder ? 'Novo item' : 'Criar rascunho'}
            </Button>
            <Button disabled={!selectedOrder} icon="printer" onClick={() => selectedOrder && onPreviewTicket(selectedOrder.id)} variant="primary">
              Previa da comanda
            </Button>
          </>
        }
        description="Fila de pedidos com conferencia humana, pagamento e impressao antes do preparo."
        title="Pedidos"
      />

      <div className="orders-layout">
        <Card className="orders-list-card">
          <SectionTitle eyebrow="Fila operacional" title="Pedidos ativos" />
          <div className="orders-list">
            {orders.map((order) => (
              <button
                className={selectedOrder?.id === order.id ? 'order-list-item is-active' : 'order-list-item'}
                key={order.id}
                onClick={() => onSelectOrder(order.id)}
                type="button"
              >
                <div>
                  <strong>{order.code}</strong>
                  <span>{order.customer.name}</span>
                </div>
                <StatusBadge status={order.status} type="order" />
              </button>
            ))}
            {orders.length === 0 ? (
              <EmptyState
                actionLabel="Criar rascunho"
                description="Nenhum pedido veio da API ainda. Crie um rascunho manual para iniciar a fila local."
                onAction={onNewOrder}
                title="Fila vazia"
              />
            ) : null}
          </div>
        </Card>

        <div className="orders-main">
          {isLoading ? <LoadingState description="Atualizando fila pelo backend..." title="Sincronizando pedidos" /> : null}

          {selectedOrder ? (
            <>
              <div className="order-kpis">
                <Card>
                  <span className="mini-label">Status atual</span>
                  <StatusBadge status={selectedOrder.status} type="order" />
                </Card>
                <Card>
                  <span className="mini-label">Pagamento</span>
                  <strong>{formatCurrency(selectedOrder.paid)} recebido</strong>
                </Card>
                <Card>
                  <span className="mini-label">Total</span>
                  <strong>{formatCurrency(selectedOrder.total)}</strong>
                </Card>
                <Card>
                  <span className="mini-label">Comanda</span>
                  <StatusBadge status={selectedOrder.printStatus} type="print" />
                </Card>
              </div>

              <Card>
                <SectionTitle
                  action={
                    <Button icon="plus" onClick={() => onOpenModal('add-product')} variant="ghost">
                      Adicionar item
                    </Button>
                  }
                  title="Itens do pedido"
                />
                <DataTable columns={itemColumns} data={selectedOrder.items} getRowKey={(item) => item.id} />
                {selectedOrder.items.length === 0 ? (
                  <EmptyState
                    actionLabel="Adicionar item"
                    description="Rascunho criado. Escolha um produto do cardapio para montar o pedido."
                    onAction={() => onOpenModal('add-product')}
                    title="Pedido sem itens"
                  />
                ) : null}
              </Card>

              <div className="order-detail-grid">
                <Card>
                  <SectionTitle title="Cliente e retirada" />
                  <div className="detail-list">
                    <span>Cliente pagador</span>
                    <strong>{selectedOrder.customer.name}</strong>
                    <span>Retirada/entrega</span>
                    <strong>{selectedOrder.pickupPerson ?? selectedOrder.deliveryLabel ?? 'A confirmar'}</strong>
                    <span>Credito</span>
                    <strong>{formatCurrency(selectedOrder.customer.creditBalance)}</strong>
                  </div>
                </Card>
                <Card>
                  <SectionTitle title="Historico e observacoes" />
                  <div className="timeline">
                    {selectedOrder.history.map((entry) => (
                      <div className="timeline__item" key={entry.id}>
                        <span />
                        <div>
                          <strong>{entry.title}</strong>
                          <p>{entry.description}</p>
                        </div>
                        <small>{entry.timeLabel}</small>
                      </div>
                    ))}
                    {selectedOrder.history.length === 0 ? <p className="muted-text">Sem historico registrado.</p> : null}
                  </div>
                </Card>
              </div>

              <PrintPreview onPreviewTicket={onPreviewTicket} order={selectedOrder} />
            </>
          ) : (
            <EmptyState
              actionLabel="Criar primeiro rascunho"
              description="A tela esta conectada ao backend. Crie um rascunho para iniciar um pedido manual seguro."
              onAction={onNewOrder}
              title="Nenhum pedido selecionado"
            />
          )}
        </div>

        <Card className="order-side-panel">
          <SectionTitle title="Acoes criticas" />
          <div className="side-actions">
            <Button disabled={!selectedOrder} icon="check" onClick={() => onOpenModal('confirm-payment')} variant="primary">
              Confirmar pagamento
            </Button>
            <Button disabled={!selectedOrder} icon="arrow" onClick={() => onOpenModal('change-status')} variant="secondary">
              Alterar status
            </Button>
            <Button disabled={!selectedOrder} icon="alert" onClick={() => onOpenModal('cancel-order')} variant="danger">
              Cancelar pedido
            </Button>
          </div>
          <div className="attention-box">
            <Badge tone="manual">Confirmacao humana</Badge>
            <p>Ambiguidades, credito e comprovantes nao devem ser decididos pela IA sem atendente.</p>
          </div>
        </Card>
      </div>
    </PageContainer>
  )
}
