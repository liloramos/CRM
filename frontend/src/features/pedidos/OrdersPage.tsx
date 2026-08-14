import { useState } from 'react'
import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { DataTable, type DataTableColumn } from '../../components/ui/DataTable'
import { EmptyState, LoadingState } from '../../components/ui/States'
import type { AppModal, Order, OrderItem } from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'
import {
  getOrderOperationalState,
  orderMatchesQueueFilter,
  type OrderQueueFilter,
} from './orderOperationalState'
import type { FulfillmentAction } from '../../services/crm.service'

type OrdersPageProps = {
  isLoading: boolean
  orders: Order[]
  selectedOrder?: Order
  onNewOrder: () => void
  onAdvanceOrder: (orderId: string, action: FulfillmentAction) => Promise<void>
  onOpenModal: (modal: AppModal) => void
  onPreviewTicket: (orderId: string) => void
  onPrintTicket: (orderId: string) => void
  onRequestBulkDelete: (orderIds: string[]) => void
  onRequestCleanupTestOrders: (orderIds: string[]) => void
  onRequestPermanentDelete: (orderId: string) => void
  onSelectOrder: (orderId: string) => void
  canManageOrders: boolean
  canPermanentlyDeleteOrders: boolean
  canRunDestructiveTestCleanup: boolean
}

const QUEUE_FILTERS: Array<{ key: OrderQueueFilter; label: string }> = [
  { key: 'active', label: 'Ativos' },
  { key: 'finished', label: 'Concluidos' },
  { key: 'cancelled', label: 'Cancelados' },
  { key: 'all', label: 'Todos' },
]

export function OrdersPage({
  canManageOrders,
  isLoading,
  onNewOrder,
  onAdvanceOrder,
  onOpenModal,
  onPreviewTicket,
  onPrintTicket,
  onRequestBulkDelete,
  onRequestCleanupTestOrders,
  onRequestPermanentDelete,
  onSelectOrder,
  orders,
  selectedOrder,
  canPermanentlyDeleteOrders,
  canRunDestructiveTestCleanup,
}: OrdersPageProps) {
  const [queueFilter, setQueueFilter] = useState<OrderQueueFilter>('active')
  const [bulkSelection, setBulkSelection] = useState<string[]>([])
  const selectedOrderState = selectedOrder ? getOrderOperationalState(selectedOrder) : null
  const filteredOrders = orders.filter((order) => orderMatchesQueueFilter(order, queueFilter))
  const canSelectForCleanup = canPermanentlyDeleteOrders && queueFilter !== 'active'
  const visibleOrderIds = filteredOrders.map((order) => order.id)
  const selectedVisibleIds = bulkSelection.filter((orderId) => visibleOrderIds.includes(orderId))
  const showBeneficiaryColumn = selectedOrder?.items.some((item) => item.beneficiary) ?? false
  const itemColumns = orderItemColumns(showBeneficiaryColumn)

  return (
    <PageContainer density="wide">
      <PageHeader
        actions={
          <div className="orders-header-actions">
            <div className="orders-header-actions__primary">
              <Button icon="plus" onClick={onNewOrder} variant="primary">
                Novo pedido
              </Button>
              <Button disabled={!selectedOrderState?.canReceiveItems} icon="plus" onClick={() => onOpenModal('add-product')} variant="secondary">
                Adicionar item
              </Button>
            </div>
            <div className="orders-header-actions__secondary">
              <Button disabled={!selectedOrderState?.canPrint} icon="printer" onClick={() => selectedOrder && onPreviewTicket(selectedOrder.id)} variant="ghost">
                Visualizar comanda
              </Button>
              <Button disabled={!selectedOrderState?.canPrint} icon="printer" onClick={() => selectedOrder && onPrintTicket(selectedOrder.id)} variant="secondary">
                Imprimir
              </Button>
            </div>
          </div>
        }
        description="Fila de pedidos com conferencia humana, pagamento e impressao antes do preparo."
        title="Pedidos"
      />

      <div className="orders-layout">
        <Card className="orders-list-card">
          <SectionTitle
            action={
              <div className="queue-filter-tabs" role="tablist" aria-label="Filtrar pedidos">
                {QUEUE_FILTERS.map((filter) => (
                  <button
                    aria-selected={queueFilter === filter.key}
                    className={queueFilter === filter.key ? 'queue-filter-tab is-active' : 'queue-filter-tab'}
                    key={filter.key}
                    onClick={() => {
                      setQueueFilter(filter.key)
                      setBulkSelection([])
                    }}
                    role="tab"
                    type="button"
                  >
                    {filter.label}
                  </button>
                ))}
              </div>
            }
            eyebrow="Fila operacional"
            title={queueFilter === 'active' ? 'Pedidos ativos' : 'Pedidos'}
          />
          {canSelectForCleanup && filteredOrders.length > 0 ? (
            <div className="bulk-cleanup-bar">
              <span>{selectedVisibleIds.length} selecionado(s)</span>
              <button
                className="text-button"
                onClick={() => setBulkSelection(selectedVisibleIds.length === filteredOrders.length ? [] : visibleOrderIds)}
                type="button"
              >
                {selectedVisibleIds.length === filteredOrders.length ? 'Limpar selecao' : 'Selecionar lista'}
              </button>
              <Button
                disabled={selectedVisibleIds.length === 0}
                icon="close"
                onClick={() => onRequestBulkDelete(selectedVisibleIds)}
                variant="danger"
              >
                Excluir selecionados
              </Button>
              {canRunDestructiveTestCleanup ? (
                <Button
                  disabled={selectedVisibleIds.length === 0}
                  icon="close"
                  onClick={() => onRequestCleanupTestOrders(selectedVisibleIds)}
                  variant="ghost"
                >
                  Limpar pedidos de teste
                </Button>
              ) : canManageOrders ? (
                <small className="muted-text">Limpeza ampla de teste desativada neste ambiente.</small>
              ) : null}
            </div>
          ) : null}
          <div className="orders-list">
            {filteredOrders.map((order) => {
              const orderState = getOrderOperationalState(order)

              return (
                <div
                  className={[
                    selectedOrder?.id === order.id ? 'order-list-item is-active' : 'order-list-item',
                    orderState.isCancelled ? 'is-cancelled' : '',
                  ].filter(Boolean).join(' ')}
                  key={order.id}
                >
                  {canSelectForCleanup ? (
                    <label className="order-list-item__select" aria-label={`Selecionar pedido ${order.code}`}>
                      <input
                        checked={bulkSelection.includes(order.id)}
                        onChange={(event) => {
                          setBulkSelection((current) => (
                            event.target.checked
                              ? [...new Set([...current, order.id])]
                              : current.filter((candidate) => candidate !== order.id)
                          ))
                        }}
                        type="checkbox"
                      />
                    </label>
                  ) : null}
                  <button className="order-list-item__content" onClick={() => onSelectOrder(order.id)} type="button">
                    <div className="order-list-item__row order-list-item__row--top">
                      <strong title={`Pedido ${order.code}`}>Pedido {order.code}</strong>
                      <Badge tone={orderState.statusBadge.tone} size="sm">{orderState.statusBadge.label}</Badge>
                    </div>
                    <div className="order-list-item__customer" title={order.customer.name}>
                      {order.customer.name}
                    </div>
                    <div className="order-list-item__row order-list-item__row--meta">
                      <small>{order.createdLabel}</small>
                      <small>{formatCurrency(order.total)}</small>
                      {orderState.isCancelled ? null : <Badge tone={orderState.paymentBadge.tone} size="sm">{orderState.paymentBadge.label}</Badge>}
                    </div>
                  </button>
                </div>
              )
            })}
            {filteredOrders.length === 0 ? (
              <EmptyState
                actionLabel={queueFilter === 'active' ? 'Novo pedido' : undefined}
                description={emptyQueueDescription(queueFilter)}
                onAction={queueFilter === 'active' ? onNewOrder : undefined}
                title={emptyQueueTitle(queueFilter)}
              />
            ) : null}
          </div>
        </Card>

        <div className="orders-main">
          {isLoading ? <LoadingState description="Atualizando fila pelo backend..." title="Sincronizando pedidos" /> : null}

          {selectedOrder && selectedOrderState ? (
            <>
              {selectedOrderState.isCancelled ? (
                <div className="order-cancelled-notice" role="status">
                  <Badge tone="danger">Cancelado</Badge>
                  <p>Este pedido esta preservado no historico, mas saiu da fila operacional e nao aceita novas acoes de preparo.</p>
                </div>
              ) : null}

              <div className="order-kpis">
                <Card>
                  <span className="mini-label">Status atual</span>
                  <Badge tone={selectedOrderState.statusBadge.tone}>{selectedOrderState.statusBadge.label}</Badge>
                </Card>
                <Card>
                  <span className="mini-label">Pagamento</span>
                  <strong>{selectedOrderState.paymentBadge.label}</strong>
                  <small>{formatCurrency(selectedOrder.paid)} recebido</small>
                </Card>
                <Card>
                  <span className="mini-label">Total</span>
                  <strong>{formatCurrency(selectedOrder.total)}</strong>
                </Card>
                <Card>
                  <span className="mini-label">Comanda</span>
                  <Badge tone={selectedOrderState.printBadge.tone}>{selectedOrderState.printBadge.label}</Badge>
                </Card>
              </div>

              <Card>
                <SectionTitle
                  action={
                    <Button disabled={!selectedOrderState.canReceiveItems} icon="plus" onClick={() => onOpenModal('add-product')} variant="ghost">
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
                    onAction={selectedOrderState.canReceiveItems ? () => onOpenModal('add-product') : undefined}
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

            </>
          ) : (
            <EmptyState
              actionLabel="Novo pedido"
              description="A tela esta conectada ao backend. Crie um pedido para iniciar um atendimento manual seguro."
              onAction={onNewOrder}
              title="Nenhum pedido selecionado"
            />
          )}
        </div>

        <Card className="order-side-panel">
          <SectionTitle title="Acoes criticas" />
          <div className="side-actions">
            {selectedOrder?.backendStatus === 'in_preparation' ? <Button disabled={isLoading} onClick={() => void onAdvanceOrder(selectedOrder.id, 'ready')}>Marcar como pronto</Button> : null}
            {selectedOrder?.backendStatus === 'ready_for_pickup' && selectedOrder.fulfillmentType === 'entrega' ? <Button disabled={isLoading} onClick={() => void onAdvanceOrder(selectedOrder.id, 'start-delivery')}>Iniciar entrega</Button> : null}
            {selectedOrder?.backendStatus === 'ready_for_pickup' && selectedOrder.fulfillmentType === 'retirada' ? <Button disabled={isLoading} onClick={() => void onAdvanceOrder(selectedOrder.id, 'picked-up')}>Marcar como retirado</Button> : null}
            {selectedOrder?.backendStatus === 'out_for_delivery' ? <Button disabled={isLoading} onClick={() => void onAdvanceOrder(selectedOrder.id, 'delivered')}>Marcar como entregue</Button> : null}
            <Button disabled={!selectedOrderState?.canConfirmPayment} icon="check" onClick={() => onOpenModal('confirm-payment')} variant="primary">
              Confirmar pagamento
            </Button>
            {selectedOrder?.paymentStatus === 'pago' ? (
              <Button disabled={isLoading} icon="alert" onClick={() => onOpenModal('void-payment')} variant="secondary">
                Anular confirmação
              </Button>
            ) : null}
            <Button disabled={!selectedOrderState?.canChangeStatus} icon="arrow" onClick={() => onOpenModal('change-status')} variant="secondary">
              Alterar status
            </Button>
            <Button disabled={!selectedOrderState?.canCancel} icon="alert" onClick={() => onOpenModal('cancel-order')} variant="danger">
              Cancelar pedido
            </Button>
            <Button disabled={!selectedOrderState?.canDeleteDraft} icon="close" onClick={() => onOpenModal('delete-draft')} variant="ghost">
              Excluir rascunho
            </Button>
            {canPermanentlyDeleteOrders && selectedOrder ? (
              <Button icon="close" onClick={() => onRequestPermanentDelete(selectedOrder.id)} variant="ghost">
                Excluir permanentemente
              </Button>
            ) : null}
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

function orderItemColumns(showBeneficiary: boolean): DataTableColumn<OrderItem>[] {
  const columns: DataTableColumn<OrderItem>[] = [
    {
      key: 'item',
      header: 'Item',
      render: (item) => (
        <div className="table-main">
          <strong>{item.name}</strong>
          {item.composition && item.composition.length > 0 ? <small>{item.composition.join(', ')}</small> : null}
          {item.removals && item.removals.length > 0 ? <small className="table-main__removals">{item.removals.join(', ')}</small> : null}
          {item.additions.length > 0 ? <small>{item.additions.join(', ')}</small> : null}
          <span>{item.notes}</span>
        </div>
      ),
    },
  ]

  if (showBeneficiary) {
    columns.push({
      key: 'beneficiary',
      header: 'Para',
      render: (item) => item.beneficiary ?? '',
    })
  }

  columns.push(
    { key: 'quantity', header: 'Qtd.', render: (item) => `${item.quantity}x`, align: 'right' },
    { key: 'total', header: 'Subtotal', render: (item) => formatCurrency(item.totalPrice ?? item.quantity * item.unitPrice), align: 'right' },
  )

  return columns
}

function emptyQueueTitle(filter: OrderQueueFilter): string {
  switch (filter) {
    case 'cancelled':
      return 'Nenhum pedido cancelado'
    case 'finished':
      return 'Nenhum pedido concluido'
    case 'all':
      return 'Nenhum pedido encontrado'
    case 'active':
      return 'Fila vazia'
  }
}

function emptyQueueDescription(filter: OrderQueueFilter): string {
  switch (filter) {
    case 'cancelled':
      return 'Pedidos cancelados ficam preservados no historico, fora da fila ativa.'
    case 'finished':
      return 'Pedidos finalizados saem da operacao ativa.'
    case 'all':
      return 'Nenhum pedido veio da API para esta consulta.'
    case 'active':
      return 'Nenhum pedido ativo veio da API ainda. Crie um pedido manual para iniciar a fila local.'
  }
}
