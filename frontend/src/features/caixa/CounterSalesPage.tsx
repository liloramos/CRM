import { useEffect, useMemo, useState } from 'react'
import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Button, IconButton } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { LoadingState } from '../../components/ui/States'
import {
  cancelCounterSale,
  completeCounterSale,
  describeApiError,
  getCounterSaleProducts,
} from '../../services/crm.service'
import type { CounterSaleProduct, OperationalSnapshot } from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

type PaymentMethod = 'pix' | 'cash' | 'debit_card' | 'credit_card'

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
  const [isLoading, setIsLoading] = useState(true)
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
    } catch (requestError) {
      setError(describeApiError(requestError, 'Não foi possível concluir a venda de balcão.'))
    } finally {
      setIsSubmitting(false)
    }
  }

  async function cancelLastSale() {
    if (!lastSale || isSubmitting) return

    const confirmed = window.confirm(`Anular a venda ${lastSale.code}? O pagamento será marcado como anulado, sem estorno automático.`)
    if (!confirmed) return

    setError(null)
    setIsSubmitting(true)

    try {
      const response = await cancelCounterSale(lastSale.id, { reason: 'cancelamento_caixa', notes: 'Cancelamento confirmado pela atendente no Caixa.' })
      setLastSale(response.data)
      await onSaleChanged()
    } catch (requestError) {
      setError(describeApiError(requestError, 'Não foi possível anular esta venda de balcão.'))
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
        description="Registre vendas rápidas com produtos e preços definidos pelo catálogo."
        title="Caixa"
      />

      {error ? <div className="counter-sales__error" role="alert">{error}</div> : null}

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
                      <strong>{formatCurrency(priceCents(product) / 100)}</strong>
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
                      <span>{formatCurrency(priceCents(product) / 100)} por unidade</span>
                    </div>
                    <div className="counter-sales__cart-item-actions">
                      <IconButton icon="close" label={`Diminuir ${product.name}`} onClick={() => changeQuantity(product.id, -1)} />
                      <span>{quantity}</span>
                      <IconButton icon="plus" label={`Aumentar ${product.name}`} onClick={() => changeQuantity(product.id, 1)} />
                    </div>
                    <strong>{formatCurrency((priceCents(product) * quantity) / 100)}</strong>
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
              <strong>{formatCurrency(total / 100)}</strong>
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
              {lastSale.status !== 'cancelado' ? (
                <Button disabled={!canManageOrders || isSubmitting} onClick={() => void cancelLastSale()} size="sm" variant="danger">
                  Anular venda
                </Button>
              ) : null}
            </Card>
          ) : null}
        </aside>
      </div>
    </PageContainer>
  )
}

function priceCents(product: CounterSaleProduct): number {
  return product.base_price_cents ?? 0
}
