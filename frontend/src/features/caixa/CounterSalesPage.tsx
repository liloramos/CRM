import { useEffect, useMemo, useState } from 'react'
import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Button, IconButton } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { Modal } from '../../components/ui/Modal'
import { LoadingState } from '../../components/ui/States'
import {
  cancelCounterSale,
  completeCounterSale,
  describeApiError,
  getCounterSaleDetail,
  getCounterSaleHistory,
  getCounterSaleProducts,
} from '../../services/crm.service'
import type {
  CounterSaleDetail,
  CounterSaleHistory,
  CounterSaleHistoryFilters,
  CounterSaleProduct,
  OperationalSnapshot,
} from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

type PaymentMethod = 'pix' | 'cash' | 'debit_card' | 'credit_card'
type CounterSalesView = 'sale' | 'history'

type CounterSalesPageProps = {
  canManageOrders: boolean
  onSaleChanged: () => Promise<void>
}

const paymentMethods: Array<{ value: PaymentMethod; label: string }> = [
  { value: 'cash', label: 'Dinheiro' },
  { value: 'pix', label: 'Pix' },
  { value: 'debit_card', label: 'Cartão' },
]

export function CounterSalesPage({ canManageOrders, onSaleChanged }: CounterSalesPageProps) {
  const [products, setProducts] = useState<CounterSaleProduct[]>([])
  const [cart, setCart] = useState<Record<string, number>>({})
  const [activeCategory, setActiveCategory] = useState('all')
  const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>('cash')
  const [activeView, setActiveView] = useState<CounterSalesView>('sale')
  const [selectedDate, setSelectedDate] = useState('')
  const [historyPaymentMethod, setHistoryPaymentMethod] = useState<CounterSaleHistoryFilters['paymentMethod']>()
  const [historyStatus, setHistoryStatus] = useState<CounterSaleHistoryFilters['status']>()
  const [historyRevision, setHistoryRevision] = useState(0)
  const [history, setHistory] = useState<CounterSaleHistory | null>(null)
  const [selectedSale, setSelectedSale] = useState<CounterSaleDetail | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [isHistoryLoading, setIsHistoryLoading] = useState(true)
  const [isDetailLoading, setIsDetailLoading] = useState(false)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [lastSale, setLastSale] = useState<OperationalSnapshot['orders'][number] | null>(null)

  useEffect(() => {
    let isCurrent = true

    void getCounterSaleProducts()
      .then((data) => {
        if (isCurrent) setProducts(data)
      })
      .catch((requestError) => {
        if (isCurrent) setError(describeApiError(requestError, 'Não foi possível carregar os produtos do Caixa.'))
      })
      .finally(() => {
        if (isCurrent) setIsLoading(false)
      })

    return () => {
      isCurrent = false
    }
  }, [])

  const historyFilters = useMemo<CounterSaleHistoryFilters>(() => ({
    dateFrom: selectedDate || undefined,
    dateTo: selectedDate || undefined,
    paymentMethod: historyPaymentMethod,
    status: historyStatus,
  }), [historyPaymentMethod, historyStatus, selectedDate])

  useEffect(() => {
    let isCurrent = true

    void getCounterSaleHistory(historyFilters)
      .then((data) => {
        if (isCurrent) setHistory(data)
      })
      .catch((requestError) => {
        if (isCurrent) setError(describeApiError(requestError, 'Não foi possível carregar o histórico do Caixa.'))
      })
      .finally(() => {
        if (isCurrent) setIsHistoryLoading(false)
      })

    return () => {
      isCurrent = false
    }
  }, [historyFilters, historyRevision])

  function refreshHistory() {
    setIsHistoryLoading(true)
    setHistoryRevision((current) => current + 1)
  }

  function updateHistoryDate(value: string) {
    setIsHistoryLoading(true)
    setSelectedDate(value)
  }

  function updateHistoryPaymentMethod(value: CounterSaleHistoryFilters['paymentMethod']) {
    setIsHistoryLoading(true)
    setHistoryPaymentMethod(value)
  }

  function updateHistoryStatus(value: CounterSaleHistoryFilters['status']) {
    setIsHistoryLoading(true)
    setHistoryStatus(value)
  }

  const categories = useMemo(() => {
    const values = new Map<string, string>()
    products.forEach((product) => {
      if (product.category) values.set(product.category.slug, product.category.name)
    })

    return [{ slug: 'all', name: 'Todos' }, ...Array.from(values, ([slug, name]) => ({ slug, name }))]
  }, [products])

  const visibleProducts = useMemo(
    () => products.filter((product) => activeCategory === 'all' || product.category?.slug === activeCategory),
    [activeCategory, products],
  )

  const cartItems = useMemo(() => products.flatMap((product) => {
    const quantity = cart[String(product.id)] ?? 0

    return quantity > 0 ? [{ product, quantity }] : []
  }), [cart, products])

  const total = useMemo(
    () => cartItems.reduce((sum, item) => sum + (priceCents(item.product) * item.quantity), 0),
    [cartItems],
  )

  function changeQuantity(productId: number, delta: number) {
    setCart((current) => {
      const key = String(productId)
      const nextQuantity = Math.max(0, (current[key] ?? 0) + delta)
      const next = { ...current }

      if (nextQuantity === 0) delete next[key]
      else next[key] = nextQuantity

      return next
    })
  }

  async function completeSale() {
    if (!canManageOrders || cartItems.length === 0 || isSubmitting) return

    setError(null)
    setIsSubmitting(true)

    try {
      const response = await completeCounterSale({
        items: cartItems.map(({ product, quantity }) => ({ product_id: product.id, quantity })),
        payment_method: paymentMethod,
      })

      setLastSale(response.data)
      setCart({})
      await onSaleChanged()
      refreshHistory()
    } catch (requestError) {
      setError(describeApiError(requestError, 'Não foi possível concluir a venda de balcão.'))
    } finally {
      setIsSubmitting(false)
    }
  }

  async function openSaleDetail(orderId: string) {
    setError(null)
    setIsDetailLoading(true)

    try {
      setSelectedSale(await getCounterSaleDetail(orderId))
    } catch (requestError) {
      setError(describeApiError(requestError, 'Não foi possível abrir os detalhes desta venda.'))
    } finally {
      setIsDetailLoading(false)
    }
  }

  async function cancelSale(orderId: string, code: string) {
    if (!canManageOrders || isSubmitting) return

    const confirmed = window.confirm(`Cancelar a venda ${code}? O pagamento será anulado no CRM, sem estorno automático.`)
    if (!confirmed) return

    setError(null)
    setIsSubmitting(true)

    try {
      const response = await cancelCounterSale(orderId, {
        reason: 'cancelamento_caixa',
        notes: 'Cancelamento confirmado pela atendente no Caixa.',
      })
      setLastSale(response.data)
      const [, detail] = await Promise.all([onSaleChanged(), getCounterSaleDetail(orderId)])
      setSelectedSale(detail)
      refreshHistory()
    } catch (requestError) {
      setError(describeApiError(requestError, 'Não foi possível cancelar esta venda de balcão.'))
    } finally {
      setIsSubmitting(false)
    }
  }

  if (isLoading) {
    return (
      <PageContainer>
        <LoadingState description="Carregando produtos vendáveis do catálogo." title="Preparando o Caixa" />
      </PageContainer>
    )
  }

  return (
    <PageContainer density="wide">
      <PageHeader
        actions={
          <div aria-label="Áreas do Caixa" className="counter-sales__view-toggle" role="tablist">
            <button
              aria-selected={activeView === 'sale'}
              className={activeView === 'sale' ? 'counter-sales__view-button is-active' : 'counter-sales__view-button'}
              onClick={() => setActiveView('sale')}
              role="tab"
              type="button"
            >
              Nova venda
            </button>
            <button
              aria-selected={activeView === 'history'}
              className={activeView === 'history' ? 'counter-sales__view-button is-active' : 'counter-sales__view-button'}
              onClick={() => setActiveView('history')}
              role="tab"
              type="button"
            >
              Histórico
            </button>
          </div>
        }
        description={activeView === 'sale'
          ? 'Registre vendas rápidas com produtos e preços definidos pelo catálogo.'
          : 'Acompanhe as vendas de balcão sem duplicar o Financeiro.'}
        title="Caixa"
      />

      {error ? <div className="counter-sales__error" role="alert">{error}</div> : null}

      {activeView === 'sale' ? (
        <div className="counter-sales">
          <section className="counter-sales__catalog" aria-label="Produtos disponíveis no Caixa">
            <div className="counter-sales__filters" role="tablist" aria-label="Categorias de produtos">
              {categories.map((category) => (
                <button
                  aria-selected={activeCategory === category.slug}
                  className={activeCategory === category.slug ? 'counter-sales__filter is-active' : 'counter-sales__filter'}
                  key={category.slug}
                  onClick={() => setActiveCategory(category.slug)}
                  role="tab"
                  type="button"
                >
                  {category.name}
                </button>
              ))}
            </div>

            {visibleProducts.length === 0 ? (
              <Card className="counter-sales__empty">
                <p>Não há produtos disponíveis nesta categoria hoje.</p>
              </Card>
            ) : (
              <div className="counter-sales__product-grid">
                {visibleProducts.map((product) => {
                  const quantity = cart[String(product.id)] ?? 0

                  return (
                    <article className="counter-sales__product" key={product.id}>
                      <div className="counter-sales__product-image" aria-hidden="true">
                        {product.image_url ? <img alt="" src={product.image_url} /> : <span>Caixa</span>}
                      </div>
                      <div className="counter-sales__product-content">
                        <span>{product.category?.name ?? 'Produto'}</span>
                        <h2>{product.name}</h2>
                        <strong>{formatCents(priceCents(product))}</strong>
                      </div>
                      {quantity > 0 ? (
                        <div className="counter-sales__product-quantity" aria-label={`Quantidade de ${product.name}`}>
                          <IconButton icon="close" label={`Remover uma unidade de ${product.name}`} onClick={() => changeQuantity(product.id, -1)} />
                          <strong>{quantity}</strong>
                          <IconButton icon="plus" label={`Adicionar uma unidade de ${product.name}`} onClick={() => changeQuantity(product.id, 1)} />
                        </div>
                      ) : (
                        <Button icon="plus" onClick={() => changeQuantity(product.id, 1)} size="sm" variant="secondary">
                          Adicionar
                        </Button>
                      )}
                    </article>
                  )
                })}
              </div>
            )}
          </section>

          <aside className="counter-sales__cart" aria-label="Carrinho da venda">
            <Card className="counter-sales__cart-card">
              <SectionTitle eyebrow="Venda atual" title="Carrinho" />
              {cartItems.length === 0 ? (
                <p className="counter-sales__cart-empty">Selecione produtos para iniciar a venda.</p>
              ) : (
                <div className="counter-sales__cart-items">
                  {cartItems.map(({ product, quantity }) => (
                    <div className="counter-sales__cart-item" key={product.id}>
                      <div>
                        <strong>{product.name}</strong>
                        <span>{formatCents(priceCents(product))} por unidade</span>
                      </div>
                      <div className="counter-sales__cart-item-actions">
                        <IconButton icon="close" label={`Diminuir ${product.name}`} onClick={() => changeQuantity(product.id, -1)} />
                        <span>{quantity}</span>
                        <IconButton icon="plus" label={`Aumentar ${product.name}`} onClick={() => changeQuantity(product.id, 1)} />
                      </div>
                      <strong>{formatCents(priceCents(product) * quantity)}</strong>
                    </div>
                  ))}
                </div>
              )}

              <div className="counter-sales__payment">
                <span>Forma de pagamento</span>
                <div className="counter-sales__payment-methods" role="radiogroup" aria-label="Forma de pagamento">
                  {paymentMethods.map((method) => (
                    <button
                      aria-checked={paymentMethod === method.value}
                      className={paymentMethod === method.value ? 'counter-sales__payment-method is-active' : 'counter-sales__payment-method'}
                      key={method.value}
                      onClick={() => setPaymentMethod(method.value)}
                      role="radio"
                      type="button"
                    >
                      {method.label}
                    </button>
                  ))}
                </div>
              </div>

              <div className="counter-sales__total">
                <span>Total</span>
                <strong>{formatCents(total)}</strong>
              </div>
              <Button disabled={!canManageOrders || cartItems.length === 0 || isSubmitting} icon="cash" onClick={() => void completeSale()} variant="primary">
                {isSubmitting ? 'Concluindo...' : 'Concluir venda'}
              </Button>
              {!canManageOrders ? <p className="counter-sales__permission">Você não tem permissão para concluir vendas.</p> : null}
            </Card>

            {lastSale ? (
              <Card className="counter-sales__last-sale" tone={lastSale.status === 'cancelado' ? 'danger' : 'success'}>
                <span>{lastSale.status === 'cancelado' ? 'Venda anulada' : 'Venda concluída'}</span>
                <strong>{lastSale.code} · {formatCurrency(lastSale.total)}</strong>
                <p>{lastSale.status === 'cancelado' ? 'O histórico foi preservado e não houve estorno automático.' : 'Pagamento confirmado pela atendente.'}</p>
                <Button onClick={() => void openSaleDetail(lastSale.id)} size="sm" variant="secondary">
                  Ver detalhes
                </Button>
                {lastSale.status !== 'cancelado' ? (
                  <Button disabled={!canManageOrders || isSubmitting} onClick={() => void cancelSale(lastSale.id, lastSale.code)} size="sm" variant="danger">
                    Cancelar venda
                  </Button>
                ) : null}
              </Card>
            ) : null}
          </aside>
        </div>
      ) : (
        <CounterSalesHistoryView
          history={history}
          isLoading={isHistoryLoading}
          onOpenSale={(orderId) => void openSaleDetail(orderId)}
          onRefresh={refreshHistory}
          onSelectedDateChange={updateHistoryDate}
          onPaymentMethodChange={updateHistoryPaymentMethod}
          onStatusChange={updateHistoryStatus}
          paymentMethod={historyPaymentMethod}
          selectedDate={selectedDate}
          status={historyStatus}
        />
      )}

      <Modal
        closeDisabled={isSubmitting}
        onClose={() => setSelectedSale(null)}
        open={selectedSale !== null || isDetailLoading}
        size="lg"
        title={selectedSale ? `Venda ${selectedSale.code}` : 'Carregando venda'}
      >
        {selectedSale ? (
          <CounterSaleDetailView
            canManageOrders={canManageOrders}
            detail={selectedSale}
            isSubmitting={isSubmitting}
            onCancel={() => void cancelSale(selectedSale.id, selectedSale.code)}
          />
        ) : <LoadingState description="Buscando os detalhes auditáveis da venda." title="Carregando" />}
      </Modal>
    </PageContainer>
  )
}

type CounterSalesHistoryViewProps = {
  history: CounterSaleHistory | null
  isLoading: boolean
  selectedDate: string
  paymentMethod: CounterSaleHistoryFilters['paymentMethod']
  status: CounterSaleHistoryFilters['status']
  onSelectedDateChange: (value: string) => void
  onPaymentMethodChange: (value: CounterSaleHistoryFilters['paymentMethod']) => void
  onStatusChange: (value: CounterSaleHistoryFilters['status']) => void
  onRefresh: () => void
  onOpenSale: (orderId: string) => void
}

function CounterSalesHistoryView({
  history,
  isLoading,
  onOpenSale,
  onPaymentMethodChange,
  onRefresh,
  onSelectedDateChange,
  onStatusChange,
  paymentMethod,
  selectedDate,
  status,
}: CounterSalesHistoryViewProps) {
  return (
    <section className="counter-sales-history" aria-label="Histórico de vendas do Caixa">
      <div className="counter-sales-history__filters">
        <label>
          <span>Data</span>
          <input onChange={(event) => onSelectedDateChange(event.target.value)} type="date" value={selectedDate} />
        </label>
        <button className={!selectedDate ? 'counter-sales__filter is-active' : 'counter-sales__filter'} onClick={() => onSelectedDateChange('')} type="button">
          Hoje
        </button>
        <label>
          <span>Pagamento</span>
          <select onChange={(event) => onPaymentMethodChange((event.target.value || undefined) as CounterSaleHistoryFilters['paymentMethod'])} value={paymentMethod ?? ''}>
            <option value="">Todos</option>
            <option value="cash">Dinheiro</option>
            <option value="pix">Pix</option>
            <option value="debit_card">Cartão de débito</option>
            <option value="credit_card">Cartão de crédito</option>
          </select>
        </label>
        <div aria-label="Status da venda" className="counter-sales-history__status" role="group">
          {([
            [undefined, 'Todas'],
            ['completed', 'Concluídas'],
            ['cancelled', 'Canceladas'],
          ] as const).map(([value, label]) => (
            <button
              className={status === value ? 'counter-sales__filter is-active' : 'counter-sales__filter'}
              key={label}
              onClick={() => onStatusChange(value)}
              type="button"
            >
              {label}
            </button>
          ))}
        </div>
        <IconButton icon="refresh" label="Atualizar histórico do Caixa" onClick={onRefresh} />
      </div>

      {isLoading || !history ? (
        <LoadingState description="Calculando o resumo operacional a partir das vendas registradas." title="Carregando histórico" />
      ) : (
        <>
          <div className="counter-sales-summary" aria-label={`Resumo do período ${history.summary.dateLabel}`}>
            <SummaryMetric label="Total vendido" value={formatCents(history.summary.totalSoldCents)} />
            <SummaryMetric label="Vendas concluídas" value={String(history.summary.completedSalesCount)} />
            <SummaryMetric label="Total cancelado" tone="danger" value={formatCents(history.summary.totalCancelledCents)} />
            <SummaryMetric label="Dinheiro" value={formatCents(history.summary.paymentTotals.cash)} />
            <SummaryMetric label="Pix" value={formatCents(history.summary.paymentTotals.pix)} />
            <SummaryMetric label="Cartão" value={formatCents(history.summary.paymentTotals.card)} />
          </div>

          <div className="counter-sales-history__content">
            <Card className="counter-sales-history__list">
              <SectionTitle eyebrow={history.summary.dateLabel} title="Vendas de balcão" />
              {history.sales.length === 0 ? (
                <p className="counter-sales__cart-empty">Nenhuma venda de balcão corresponde a estes filtros.</p>
              ) : (
                <div className="counter-sales-history__rows">
                  {history.sales.map((sale) => (
                    <article className="counter-sales-history__row" key={sale.id}>
                      <div>
                        <span>{sale.timeLabel}</span>
                        <strong>{sale.code}</strong>
                        <small>{sale.itemsQuantity} {sale.itemsQuantity === 1 ? 'item' : 'itens'} · {sale.paymentMethodLabel}</small>
                      </div>
                      <div className="counter-sales-history__row-total">
                        <span className={sale.status === 'cancelled' ? 'counter-sales-history__status-label is-cancelled' : 'counter-sales-history__status-label'}>{sale.statusLabel}</span>
                        <strong>{formatCents(sale.totalCents)}</strong>
                        <Button onClick={() => onOpenSale(sale.id)} size="sm" variant="secondary">Ver detalhes</Button>
                      </div>
                    </article>
                  ))}
                </div>
              )}
            </Card>

            <Card className="counter-sales-history__ranking">
              <SectionTitle eyebrow="Somente vendas concluídas" title="Mais vendidos" />
              {history.topProducts.length === 0 ? (
                <p className="counter-sales__cart-empty">Ainda não há produtos vendidos neste período.</p>
              ) : (
                <ol>
                  {history.topProducts.map((product) => (
                    <li key={product.productName}>
                      <div>
                        <strong>{product.productName}</strong>
                        <span>{product.quantity} {product.quantity === 1 ? 'unidade' : 'unidades'}</span>
                      </div>
                      <strong>{formatCents(product.totalCents)}</strong>
                    </li>
                  ))}
                </ol>
              )}
            </Card>
          </div>
        </>
      )}
    </section>
  )
}

function CounterSaleDetailView({ detail, canManageOrders, isSubmitting, onCancel }: {
  detail: CounterSaleDetail
  canManageOrders: boolean
  isSubmitting: boolean
  onCancel: () => void
}) {
  return (
    <div className="counter-sale-detail">
      <div className="counter-sale-detail__summary">
        <div><span>Origem</span><strong>{detail.originLabel}</strong></div>
        <div><span>Horário</span><strong>{detail.dateLabel} · {detail.timeLabel}</strong></div>
        <div><span>Status</span><strong>{detail.statusLabel}</strong></div>
        <div><span>Total</span><strong>{formatCents(detail.totalCents)}</strong></div>
      </div>

      <section className="counter-sale-detail__section" aria-label="Itens da venda">
        <h3>Produtos</h3>
        <div className="counter-sale-detail__items">
          {detail.items.map((item) => (
            <article className="counter-sale-detail__item" key={item.id}>
              <div className="counter-sale-detail__image" aria-hidden="true">
                {item.productImageUrl ? <img alt="" src={item.productImageUrl} /> : <span>Caixa</span>}
              </div>
              <div>
                <strong>{item.productName}</strong>
                <span>{item.quantity} × {formatCents(item.unitPriceCents)}</span>
              </div>
              <strong>{formatCents(item.subtotalCents)}</strong>
            </article>
          ))}
        </div>
      </section>

      <section className="counter-sale-detail__section" aria-label="Pagamento">
        <h3>Pagamento</h3>
        <div className="counter-sale-detail__payment">
          <div><span>Forma</span><strong>{detail.payment.methodLabel}</strong></div>
          <div><span>Situação</span><strong>{detail.payment.statusLabel}</strong></div>
          <div><span>Valor</span><strong>{formatCents(detail.payment.amountCents)}</strong></div>
          {detail.payment.confirmedAtLabel ? <div><span>Confirmado em</span><strong>{detail.payment.confirmedAtLabel}</strong></div> : null}
        </div>
        {detail.payment.voidReason ? <p className="counter-sale-detail__void">Motivo do cancelamento: {detail.payment.voidReason}</p> : null}
      </section>

      <section className="counter-sale-detail__section" aria-label="Histórico da venda">
        <h3>Histórico</h3>
        <div className="counter-sale-detail__history">
          {detail.history.map((entry) => (
            <div key={entry.id}>
              <strong>{entry.title}</strong>
              <span>{entry.description}</span>
              <small>{entry.actorName ? `${entry.actorName} · ` : ''}{entry.timeLabel}</small>
            </div>
          ))}
        </div>
      </section>

      {detail.isCancellable && canManageOrders ? (
        <Button disabled={isSubmitting} onClick={onCancel} variant="danger">
          {isSubmitting ? 'Cancelando...' : 'Cancelar venda'}
        </Button>
      ) : null}
    </div>
  )
}

function SummaryMetric({ label, tone = 'default', value }: { label: string; value: string; tone?: 'default' | 'danger' }) {
  return (
    <Card className={tone === 'danger' ? 'counter-sales-summary__metric is-danger' : 'counter-sales-summary__metric'}>
      <span>{label}</span>
      <strong>{value}</strong>
    </Card>
  )
}

function priceCents(product: CounterSaleProduct): number {
  return product.base_price_cents ?? 0
}

function formatCents(cents: number): string {
  return formatCurrency(cents / 100)
}
