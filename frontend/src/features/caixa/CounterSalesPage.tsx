import { useEffect, useMemo, useRef, useState } from 'react'
import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Button, IconButton } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { DatePickerField } from '../../components/ui/DatePickerField'
import { Modal } from '../../components/ui/Modal'
import { SelectField } from '../../components/ui/SelectField'
import { LoadingState } from '../../components/ui/States'
import {
  cancelCounterSale,
  completeCounterSale,
  createCounterSaleDraft,
  describeApiError,
  finalizeCounterSaleDraft,
  getCounterSaleDrafts,
  getCounterSaleDetail,
  getCounterSaleHistory,
  getCounterSaleProducts,
  getOrderTicketPreviewUrl,
  searchCustomers,
  updateCounterSaleDraftCustomer,
} from '../../services/crm.service'
import type {
  CounterSaleCustomer,
  CounterSaleDraft,
  CounterSaleDetail,
  CounterSaleHistory,
  CounterSaleHistoryFilters,
  CounterSaleProduct,
  CustomerSummary,
  OperationalSnapshot,
  SellerSummary,
} from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

type PaymentMethod = 'pix' | 'cash' | 'debit_card' | 'credit_card'
type CounterSalesView = 'sale' | 'history'
type ItemEditorMode = 'sale' | 'new-draft' | 'finalize-draft'

type CounterSaleCartItem = {
  id: string
  product: CounterSaleProduct
  quantity: number
  weightGrams?: number
  extraBeef: boolean
  selectedComponents: string[]
}

type CounterSaleCustomerInput = {
  customer: CounterSaleCustomer | null
  name: string
  phone: string
  saveCustomer: boolean
}

const emptyCustomerInput: CounterSaleCustomerInput = {
  customer: null,
  name: '',
  phone: '',
  saveCustomer: false,
}

type CounterSalesPageProps = {
  canManageOrders: boolean
  canViewPrinting: boolean
  onSaleChanged: () => Promise<void>
  sellerCandidates: SellerSummary[]
}

const paymentMethods: Array<{ value: PaymentMethod; label: string }> = [
  { value: 'cash', label: 'Dinheiro' },
  { value: 'pix', label: 'Pix' },
  { value: 'debit_card', label: 'Cartão' },
]

export function CounterSalesPage({ canManageOrders, canViewPrinting, onSaleChanged, sellerCandidates }: CounterSalesPageProps) {
  const [products, setProducts] = useState<CounterSaleProduct[]>([])
  const [cartItems, setCartItems] = useState<CounterSaleCartItem[]>([])
  const [activeCategory, setActiveCategory] = useState('all')
  const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>('cash')
  const [sellerUserId, setSellerUserId] = useState('')
  const [editingDraftSellerId, setEditingDraftSellerId] = useState('')
  const [activeView, setActiveView] = useState<CounterSalesView>('sale')
  const [selectedDate, setSelectedDate] = useState('')
  const [historyPaymentMethod, setHistoryPaymentMethod] = useState<CounterSaleHistoryFilters['paymentMethod']>()
  const [historyStatus, setHistoryStatus] = useState<CounterSaleHistoryFilters['status']>()
  const [historyRevision, setHistoryRevision] = useState(0)
  const [history, setHistory] = useState<CounterSaleHistory | null>(null)
  const [drafts, setDrafts] = useState<CounterSaleDraft[]>([])
  const [saleCustomer, setSaleCustomer] = useState<CounterSaleCustomerInput>(emptyCustomerInput)
  const [editingDraft, setEditingDraft] = useState<CounterSaleDraft | null>(null)
  const [editingDraftCustomer, setEditingDraftCustomer] = useState<CounterSaleCustomerInput>(emptyCustomerInput)
  const [editorMode, setEditorMode] = useState<ItemEditorMode>('sale')
  const [editingProduct, setEditingProduct] = useState<CounterSaleProduct | null>(null)
  const [weightInput, setWeightInput] = useState('')
  const [includeExtraBeef, setIncludeExtraBeef] = useState(false)
  const [selfServiceComponents, setSelfServiceComponents] = useState('')
  const [selectedSale, setSelectedSale] = useState<CounterSaleDetail | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [isHistoryLoading, setIsHistoryLoading] = useState(true)
  const [isDraftsLoading, setIsDraftsLoading] = useState(true)
  const [isDetailLoading, setIsDetailLoading] = useState(false)
  const [isCustomerUpdating, setIsCustomerUpdating] = useState(false)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [lastSale, setLastSale] = useState<OperationalSnapshot['orders'][number] | null>(null)
  const [lastOpenedDraft, setLastOpenedDraft] = useState<{ id: string; code: string; productName: string } | null>(null)
  const cartSequence = useRef(0)

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

  useEffect(() => {
    let isCurrent = true

    void getCounterSaleDrafts()
      .then((data) => {
        if (isCurrent) setDrafts(data)
      })
      .catch((requestError) => {
        if (isCurrent) setError(describeApiError(requestError, 'Não foi possível carregar as comandas abertas.'))
      })
      .finally(() => {
        if (isCurrent) setIsDraftsLoading(false)
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

  async function refreshDrafts() {
    setIsDraftsLoading(true)

    try {
      setDrafts(await getCounterSaleDrafts())
    } catch (requestError) {
      setError(describeApiError(requestError, 'A operação foi concluída, mas não foi possível atualizar as comandas abertas.'))
    } finally {
      setIsDraftsLoading(false)
    }
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

  const total = useMemo(
    () => cartItems.reduce((sum, item) => sum + cartItemTotalCents(item), 0),
    [cartItems],
  )

  function addProduct(product: CounterSaleProduct) {
    if (requiresItemEditor(product)) {
      setEditorMode('sale')
      setEditingDraft(null)
      setEditingProduct(product)
      setWeightInput('')
      setIncludeExtraBeef(false)
      setSelfServiceComponents('')
      setError(null)
      return
    }

    setCartItems((current) => {
      const lineId = `product-${product.id}`
      const existing = current.find((item) => item.id === lineId)

      if (existing) {
        return current.map((item) => item.id === lineId ? { ...item, quantity: item.quantity + 1 } : item)
      }

      return [...current, {
        id: lineId,
        product,
        quantity: 1,
        extraBeef: false,
        selectedComponents: [],
      }]
    })
  }

  function changeQuantity(lineId: string, delta: number) {
    setCartItems((current) => current
      .map((item) => item.id === lineId ? { ...item, quantity: Math.max(0, item.quantity + delta) } : item)
      .filter((item) => item.quantity > 0))
  }

  function removeCartItem(lineId: string) {
    setCartItems((current) => current.filter((item) => item.id !== lineId))
  }

  function closeItemEditor() {
    setEditingProduct(null)
    setEditingDraft(null)
    setEditingDraftCustomer(emptyCustomerInput)
    setEditingDraftSellerId('')
    setEditorMode('sale')
    setWeightInput('')
    setIncludeExtraBeef(false)
    setSelfServiceComponents('')
  }

  function addConfiguredItem() {
    if (!editingProduct) return

    const weightGrams = isWeightProduct(editingProduct) ? parseWeightGrams(weightInput) : undefined
    if (isWeightProduct(editingProduct) && weightGrams === undefined) return

    cartSequence.current += 1
    setCartItems((current) => [...current, {
      id: `configured-${editingProduct.id}-${cartSequence.current}`,
      product: editingProduct,
      quantity: 1,
      weightGrams,
      extraBeef: includeExtraBeef,
      selectedComponents: isSelfService(editingProduct)
        ? splitComponentNotes(selfServiceComponents)
        : [],
    }])
    closeItemEditor()
  }

  function startDraft(product: CounterSaleProduct) {
    if (!canOpenDraft(product)) return

    setEditorMode('new-draft')
    setEditingDraft(null)
    setEditingDraftCustomer(emptyCustomerInput)
    setEditingProduct(product)
    setWeightInput('')
    setIncludeExtraBeef(false)
    setSelfServiceComponents('')
    setError(null)
  }

  function reopenDraft(draft: CounterSaleDraft) {
    const product = products.find((candidate) => candidate.id === draft.productId)
    if (!product) {
      setError(`O produto da comanda ${draft.code} não está disponível no catálogo atual.`)
      return
    }

    setEditorMode('finalize-draft')
    setEditingDraft(draft)
    setEditingDraftCustomer(customerInputFromDraft(draft))
    setEditingDraftSellerId(draft.seller?.id ?? '')
    setEditingProduct(product)
    setWeightInput(draft.weightGrams ? String(draft.weightGrams) : '')
    setIncludeExtraBeef(draft.hasExtraBeef)
    setSelfServiceComponents(draft.selectedComponents.join(', '))
    setError(null)
  }

  async function submitDraftEditor() {
    if (!editingProduct || isSubmitting) return

    const selectedComponents = isSelfService(editingProduct)
      ? splitComponentNotes(selfServiceComponents)
      : undefined
    const additions = includeExtraBeef ? [{ code: 'extra_beef' as const, quantity: 1 as const }] : undefined

    setError(null)
    setIsSubmitting(true)

    try {
      if (editorMode === 'new-draft') {
        const response = await createCounterSaleDraft({
          product_id: editingProduct.id,
          selected_components: selectedComponents,
          additions,
          seller_user_id: sellerUserId ? Number(sellerUserId) : null,
          ...customerPayload(saleCustomer),
        })
        setLastOpenedDraft({ id: response.data.id, code: response.data.code, productName: editingProduct.name })
        setSaleCustomer(emptyCustomerInput)
        setSellerUserId('')
        closeItemEditor()
        await refreshDrafts()
        return
      }

      if (!editingDraft) return
      const weightGrams = isWeightProduct(editingProduct) ? parseWeightGrams(weightInput) : undefined
      if (isWeightProduct(editingProduct) && weightGrams === undefined) return

      const response = await finalizeCounterSaleDraft(editingDraft.id, {
        weight_grams: weightGrams,
        selected_components: selectedComponents,
        additions,
        payment_method: paymentMethod,
        seller_user_id: editingDraftSellerId ? Number(editingDraftSellerId) : null,
        ...customerPayload(editingDraftCustomer),
      })
      setLastSale(response.data)
      setLastOpenedDraft(null)
      setSellerUserId('')
      closeItemEditor()
      await Promise.all([refreshDrafts(), onSaleChanged()])
      refreshHistory()
    } catch (requestError) {
      setError(describeApiError(requestError, editorMode === 'new-draft'
        ? 'Não foi possível abrir a comanda.'
        : 'Não foi possível finalizar a comanda.'))
    } finally {
      setIsSubmitting(false)
    }
  }

  async function changeEditingDraftCustomer(customer: CounterSaleCustomerInput) {
    if (!editingDraft || isCustomerUpdating) return

    setError(null)
    setIsCustomerUpdating(true)

    try {
      const updatedDraft = await updateCounterSaleDraftCustomer(editingDraft.id, customerPayload(customer))
      setEditingDraft(updatedDraft)
      setEditingDraftCustomer(customerInputFromDraft(updatedDraft))
      setDrafts((current) => current.map((draft) => draft.id === updatedDraft.id ? updatedDraft : draft))
    } catch (requestError) {
      setError(describeApiError(requestError, 'Não foi possível atualizar o cliente desta comanda.'))
    } finally {
      setIsCustomerUpdating(false)
    }
  }

  function openCounterDocument(orderId: string, autoprint: boolean, documentName: 'comprovante' | 'ficha') {
    setError(null)

    if (!canViewPrinting) {
      setError(`Você não tem permissão para visualizar ${documentName === 'ficha' ? 'fichas' : 'comprovantes'}.`)
      return
    }

    const documentWindow = window.open(
      getOrderTicketPreviewUrl(orderId, autoprint, 'cashier'),
      '_blank',
      'popup=yes,width=420,height=720',
    )

    if (!documentWindow) {
      setError(`O navegador bloqueou a janela d${documentName === 'ficha' ? 'a ficha' : 'o comprovante'}. Libere pop-ups e tente novamente.`)
      return
    }

    documentWindow.opener = null
    documentWindow.focus()
  }

  async function completeSale() {
    if (!canManageOrders || cartItems.length === 0 || isSubmitting) return

    setError(null)
    setIsSubmitting(true)

    try {
      const response = await completeCounterSale({
        items: cartItems.map((item) => ({
          product_id: item.product.id,
          quantity: item.quantity,
          weight_grams: item.weightGrams,
          selected_components: isSelfService(item.product) ? item.selectedComponents : undefined,
          additions: item.extraBeef ? [{ code: 'extra_beef', quantity: 1 }] : undefined,
        })),
        payment_method: paymentMethod,
        seller_user_id: sellerUserId ? Number(sellerUserId) : null,
        ...customerPayload(saleCustomer),
      })

      setLastSale(response.data)
      setCartItems([])
      setSaleCustomer(emptyCustomerInput)
      setSellerUserId('')
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

  function openReceipt(orderId: string, autoprint: boolean) {
    openCounterDocument(orderId, autoprint, 'comprovante')
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

  async function cancelDraft(draft: CounterSaleDraft) {
    if (!canManageOrders || isSubmitting) return

    const confirmed = window.confirm(`Cancelar a comanda ${draft.code}? Nenhum pagamento será criado.`)
    if (!confirmed) return

    setError(null)
    setIsSubmitting(true)

    try {
      await cancelCounterSale(draft.id, {
        reason: 'comanda_abandonada',
        notes: 'Comanda aberta cancelada pela atendente no Caixa.',
      })
      if (editingDraft?.id === draft.id) closeItemEditor()
      if (lastOpenedDraft?.id === draft.id) setLastOpenedDraft(null)
      await Promise.all([refreshDrafts(), onSaleChanged()])
      refreshHistory()
    } catch (requestError) {
      setError(describeApiError(requestError, 'Não foi possível cancelar a comanda aberta.'))
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
        <>
          <Card className="counter-drafts" tone={drafts.length > 0 ? 'glow' : 'default'}>
            <div className="counter-drafts__header">
              <div>
                <span>Fluxo de preparação</span>
                <h2>Comandas abertas</h2>
              </div>
              <strong>{drafts.length}</strong>
            </div>
            {isDraftsLoading ? (
              <p className="counter-drafts__empty">Atualizando comandas...</p>
            ) : drafts.length === 0 ? (
              <p className="counter-drafts__empty">Nenhuma comanda aguardando fechamento.</p>
            ) : (
              <div className="counter-drafts__list">
                {drafts.map((draft) => (
                  <article className="counter-drafts__item" key={draft.id}>
                    <div>
                      <strong>{draft.code}</strong>
                      <span>{draft.productName}</span>
                      {customerDisplayName(draft) ? <span>{customerDisplayName(draft)}</span> : null}
                      <span>Atendente: {draft.sellerName ?? 'Não atribuído'}</span>
                      <small>{draft.weightPending ? 'Peso pendente' : draft.statusLabel} · {draft.timeLabel}</small>
                    </div>
                    <div className="counter-drafts__actions">
                      <Button disabled={!canManageOrders || isSubmitting} onClick={() => reopenDraft(draft)} size="sm" variant="secondary">
                        Abrir
                      </Button>
                      <Button disabled={!canManageOrders || isSubmitting} onClick={() => void cancelDraft(draft)} size="sm" variant="danger">
                        Cancelar
                      </Button>
                    </div>
                  </article>
                ))}
              </div>
            )}
          </Card>

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
                  const quantity = cartItems
                    .filter((item) => item.product.id === product.id)
                    .reduce((sum, item) => sum + item.quantity, 0)
                  const configurable = requiresItemEditor(product)

                  return (
                    <article className="counter-sales__product" key={product.id}>
                      <div className="counter-sales__product-image" aria-hidden="true">
                        {product.image_url ? <img alt="" src={product.image_url} /> : <span>Caixa</span>}
                      </div>
                      <div className="counter-sales__product-content">
                        <span>{product.category?.name ?? 'Produto'}</span>
                        <h2 title={product.name}>{product.name}</h2>
                        <strong>{formatProductPrice(product)}</strong>
                      </div>
                      {!configurable && quantity > 0 ? (
                        <div className="counter-sales__product-quantity" aria-label={`Quantidade de ${product.name}`}>
                          <IconButton icon="close" label={`Remover uma unidade de ${product.name}`} onClick={() => changeQuantity(`product-${product.id}`, -1)} />
                          <strong>{quantity}</strong>
                          <IconButton icon="plus" label={`Adicionar uma unidade de ${product.name}`} onClick={() => changeQuantity(`product-${product.id}`, 1)} />
                        </div>
                      ) : (
                        <div className="counter-sales__product-add">
                          {configurable && quantity > 0 ? <span>{quantity} no carrinho</span> : null}
                          <Button icon="plus" onClick={() => addProduct(product)} size="sm" variant="secondary">
                            {isWeightProduct(product) ? 'Informar peso' : 'Adicionar'}
                          </Button>
                          {canOpenDraft(product) ? (
                            <Button disabled={!canManageOrders || isSubmitting} onClick={() => startDraft(product)} size="sm" variant="ghost">
                              Abrir comanda
                            </Button>
                          ) : null}
                        </div>
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
              <CounterSaleCustomerPicker
                disabled={isSubmitting}
                onChange={setSaleCustomer}
                value={saleCustomer}
              />
              <SelectField
                disabled={!canManageOrders || isSubmitting}
                label="Responsável / Atendente"
                onChange={setSellerUserId}
                options={counterSellerOptions(sellerCandidates)}
                searchable={sellerCandidates.length > 8}
                value={sellerUserId}
              />
              {cartItems.length === 0 ? (
                <p className="counter-sales__cart-empty">Selecione produtos para iniciar a venda.</p>
              ) : (
                <div className="counter-sales__cart-items">
                  {cartItems.map((item) => (
                    <div className="counter-sales__cart-item" key={item.id}>
                      <div>
                        <strong>{item.product.name}</strong>
                        {item.weightGrams !== undefined ? (
                          <span>{item.weightGrams} g · {formatCents(priceCents(item.product))}/kg</span>
                        ) : (
                          <span>{formatCents(priceCents(item.product))}{item.quantity > 1 ? ' por unidade' : ''}</span>
                        )}
                        {item.extraBeef ? (
                          <span className="counter-sales__cart-addition">
                            + {extraBeefName(item.product)} <b>{formatCents(extraBeefPriceCents(item.product))}</b>
                          </span>
                        ) : null}
                        {item.selectedComponents.length > 0 ? (
                          <small className="counter-sales__cart-components">Itens: {item.selectedComponents.join(', ')}</small>
                        ) : null}
                      </div>
                      {requiresItemEditor(item.product) ? (
                        <IconButton icon="close" label={`Remover ${item.product.name} do carrinho`} onClick={() => removeCartItem(item.id)} />
                      ) : (
                        <div className="counter-sales__cart-item-actions">
                          <IconButton icon="close" label={`Diminuir ${item.product.name}`} onClick={() => changeQuantity(item.id, -1)} />
                          <span>{item.quantity}</span>
                          <IconButton icon="plus" label={`Aumentar ${item.product.name}`} onClick={() => changeQuantity(item.id, 1)} />
                        </div>
                      )}
                      <strong>{formatCents(cartItemTotalCents(item))}</strong>
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

            {lastOpenedDraft ? (
              <Card className="counter-sales__opened-draft" tone="glow">
                <span>Comanda aberta</span>
                <strong>{lastOpenedDraft.code}</strong>
                <p>{lastOpenedDraft.productName} · pagamento ainda não registrado.</p>
                <div>
                  <Button disabled={!canViewPrinting} icon="printer" onClick={() => openCounterDocument(lastOpenedDraft.id, false, 'ficha')} size="sm" variant="secondary">
                    Visualizar ficha
                  </Button>
                  <Button disabled={!canViewPrinting} icon="printer" onClick={() => openCounterDocument(lastOpenedDraft.id, true, 'ficha')} size="sm" variant="primary">
                    Imprimir ficha
                  </Button>
                  <Button onClick={() => setLastOpenedDraft(null)} size="sm" variant="ghost">
                    Continuar
                  </Button>
                </div>
              </Card>
            ) : null}

            {lastSale ? (
              <Card className="counter-sales__last-sale" tone={lastSale.status === 'cancelado' ? 'danger' : 'success'}>
                <span>{lastSale.status === 'cancelado' ? 'Venda anulada' : 'Venda concluída'}</span>
                <strong>{lastSale.code} · {formatCurrency(lastSale.total)}</strong>
                <p>{lastSale.status === 'cancelado' ? 'O histórico foi preservado e não houve estorno automático.' : 'Pagamento confirmado pela atendente.'}</p>
                <Button onClick={() => void openSaleDetail(lastSale.id)} size="sm" variant="secondary">
                  Ver detalhes
                </Button>
                {lastSale.status !== 'cancelado' ? (
                  <>
                    <Button disabled={!canViewPrinting} icon="printer" onClick={() => openReceipt(lastSale.id, false)} size="sm" variant="secondary">
                      Visualizar comprovante
                    </Button>
                    <Button disabled={!canViewPrinting} icon="printer" onClick={() => openReceipt(lastSale.id, true)} size="sm" variant="primary">
                      Imprimir comprovante
                    </Button>
                    <Button disabled={!canManageOrders || isSubmitting} onClick={() => void cancelSale(lastSale.id, lastSale.code)} size="sm" variant="danger">
                      Cancelar venda
                    </Button>
                  </>
                ) : null}
              </Card>
            ) : null}
          </aside>
          </div>
        </>
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
        closeDisabled={isSubmitting || isCustomerUpdating}
        description={editorDescription(editorMode, editingProduct, editingDraft)}
        onClose={closeItemEditor}
        onPrimary={() => editorMode === 'sale' ? addConfiguredItem() : void submitDraftEditor()}
        open={editingProduct !== null}
        primaryDisabled={isSubmitting || isCustomerUpdating || !editingProduct || (editorMode !== 'new-draft' && !isEditorInputValid(editingProduct, weightInput))}
        primaryLabel={editorPrimaryLabel(editorMode, isSubmitting)}
        title={editingDraft ? `Comanda ${editingDraft.code}` : editingProduct?.name ?? 'Configurar item'}
      >
        {editingProduct ? (
          <>
            {editorMode !== 'sale' ? (
              <CounterSaleCustomerPicker
                disabled={isSubmitting || isCustomerUpdating}
                onApply={editorMode === 'finalize-draft' ? changeEditingDraftCustomer : undefined}
                onChange={editorMode === 'finalize-draft' ? setEditingDraftCustomer : setSaleCustomer}
                value={editorMode === 'finalize-draft' ? editingDraftCustomer : saleCustomer}
              />
            ) : null}
            {editorMode !== 'sale' ? (
              <SelectField
                disabled={!canManageOrders || isSubmitting || isCustomerUpdating}
                label="Responsável / Atendente"
                onChange={editorMode === 'finalize-draft' ? setEditingDraftSellerId : setSellerUserId}
                options={counterSellerOptions(
                  sellerCandidates,
                  editorMode === 'finalize-draft' ? editingDraft : null,
                )}
                searchable={sellerCandidates.length > 8}
                value={editorMode === 'finalize-draft' ? editingDraftSellerId : sellerUserId}
              />
            ) : null}
            <CounterSaleItemEditor
              disabled={isSubmitting || isCustomerUpdating}
              draftOpening={editorMode === 'new-draft'}
              includeExtraBeef={includeExtraBeef}
              onExtraBeefChange={setIncludeExtraBeef}
              onSelfServiceComponentsChange={setSelfServiceComponents}
              onWeightChange={setWeightInput}
              product={editingProduct}
              selfServiceComponents={selfServiceComponents}
              weightInput={weightInput}
            />
            {editorMode === 'finalize-draft' ? (
              <div className="counter-sales-editor__payment">
                <span>Forma de pagamento</span>
                <div className="counter-sales__payment-methods" role="radiogroup" aria-label="Forma de pagamento da comanda">
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
            ) : null}
          </>
        ) : null}
      </Modal>

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

function CounterSaleItemEditor({
  disabled,
  draftOpening,
  includeExtraBeef,
  onExtraBeefChange,
  onSelfServiceComponentsChange,
  onWeightChange,
  product,
  selfServiceComponents,
  weightInput,
}: {
  disabled: boolean
  draftOpening: boolean
  includeExtraBeef: boolean
  onExtraBeefChange: (checked: boolean) => void
  onSelfServiceComponentsChange: (value: string) => void
  onWeightChange: (value: string) => void
  product: CounterSaleProduct
  selfServiceComponents: string
  weightInput: string
}) {
  const weightGrams = isWeightProduct(product) ? parseWeightGrams(weightInput) : undefined
  const baseSubtotal = weightGrams !== undefined
    ? weightSubtotalCents(weightGrams, priceCents(product))
    : isWeightProduct(product) ? 0 : priceCents(product)
  const addition = extraBeefAddition(product)
  const previewTotal = baseSubtotal + (includeExtraBeef ? (addition?.price_cents ?? 0) : 0)
  const invalidWeight = weightInput !== '' && weightGrams === undefined

  return (
    <div className="counter-sales-editor">
      {isWeightProduct(product) ? (
        draftOpening ? (
          <div className="counter-sales-editor__pending-weight">
            <span>Peso</span>
            <strong>Será preenchido no fechamento</strong>
            <small>A ficha sairá com espaço em branco para anotação manual.</small>
          </div>
        ) : (
          <label className="counter-sales-editor__weight" htmlFor={`counter-weight-${product.id}`}>
            <span>Peso em gramas</span>
            <span className={invalidWeight ? 'counter-sales-editor__weight-control is-invalid' : 'counter-sales-editor__weight-control'}>
              <input
                aria-describedby={invalidWeight ? `counter-weight-error-${product.id}` : undefined}
                id={`counter-weight-${product.id}`}
                inputMode="numeric"
                min="1"
                onChange={(event) => onWeightChange(event.target.value)}
                placeholder="Ex.: 540"
                step="1"
                type="number"
                value={weightInput}
              />
              <b>g</b>
            </span>
            {invalidWeight ? <small id={`counter-weight-error-${product.id}`}>Informe um peso inteiro maior que zero.</small> : null}
          </label>
        )
      ) : null}

      {isSelfService(product) ? (
        <label className="counter-sales-editor__components">
          <span>Itens no prato <small>(opcional)</small></span>
          <textarea
            onChange={(event) => onSelfServiceComponentsChange(event.target.value)}
            placeholder="arroz, feijão, porco, salada"
            value={selfServiceComponents}
          />
        </label>
      ) : null}

      {addition ? (
        <label className="counter-sales-editor__addition">
          <input
            aria-label={`Adicionar ${addition.name}`}
            checked={includeExtraBeef}
            className="counter-sales-editor__beef-checkbox"
            disabled={disabled}
            onChange={(event) => onExtraBeefChange(event.target.checked)}
            type="checkbox"
          />
          <span>
            <strong>{addition.name}</strong>
            <small>+ {formatCents(addition.price_cents)}</small>
          </span>
        </label>
      ) : null}

      <div className="counter-sales-editor__preview" aria-live="polite">
        {isWeightProduct(product) ? (
          <>
            <div><span>Peso</span><strong>{draftOpening ? 'Pendente' : weightGrams !== undefined ? `${weightGrams} g` : '—'}</strong></div>
            <div><span>Preço por kg</span><strong>{formatCents(priceCents(product))}/kg</strong></div>
          </>
        ) : (
          <div><span>Preço do prato</span><strong>{formatCents(priceCents(product))}</strong></div>
        )}
        {includeExtraBeef && addition ? (
          <div><span>{addition.name}</span><strong>+ {formatCents(addition.price_cents)}</strong></div>
        ) : null}
        {draftOpening && isWeightProduct(product) ? (
          <div className="counter-sales-editor__preview-total">
            <span>Total</span>
            <strong>Após pesagem</strong>
          </div>
        ) : (
          <div className="counter-sales-editor__preview-total">
            <span>Subtotal</span>
            <strong>{formatCents(previewTotal)}</strong>
          </div>
        )}
      </div>
    </div>
  )
}

function CounterSaleCustomerPicker({
  disabled,
  onApply,
  onChange,
  value,
}: {
  disabled: boolean
  onApply?: (value: CounterSaleCustomerInput) => Promise<void>
  onChange: (value: CounterSaleCustomerInput) => void
  value: CounterSaleCustomerInput
}) {
  const [isOpen, setIsOpen] = useState(false)
  const [isLoading, setIsLoading] = useState(false)
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<CustomerSummary[]>([])
  const [searchError, setSearchError] = useState<string | null>(null)
  const rootRef = useRef<HTMLDivElement>(null)
  const searchValue = value.customer ? query : value.name

  useEffect(() => {
    if (!isOpen) return undefined

    function closeOnOutsideClick(event: globalThis.MouseEvent) {
      if (!rootRef.current?.contains(event.target as Node)) setIsOpen(false)
    }

    document.addEventListener('mousedown', closeOnOutsideClick)

    return () => document.removeEventListener('mousedown', closeOnOutsideClick)
  }, [isOpen])

  useEffect(() => {
    if (!isOpen) return undefined

    let isCurrent = true
    const timeout = window.setTimeout(() => {
      setIsLoading(true)
      setSearchError(null)

      void searchCustomers(searchValue, 8)
        .then((customers) => {
          if (isCurrent) setResults(customers)
        })
        .catch((requestError) => {
          if (isCurrent) setSearchError(describeApiError(requestError, 'Não foi possível buscar clientes.'))
        })
        .finally(() => {
          if (isCurrent) setIsLoading(false)
        })
    }, searchValue.trim() ? 220 : 0)

    return () => {
      isCurrent = false
      window.clearTimeout(timeout)
    }
  }, [isOpen, searchValue])

  function chooseCustomer(customer: CounterSaleCustomer) {
    onChange({ customer, name: '', phone: '', saveCustomer: false })
    setIsOpen(false)
    setQuery('')
  }

  function clearCustomer() {
    onChange(emptyCustomerInput)
    setIsOpen(false)
    setQuery('')
  }

  function changeFreeName(name: string) {
    setQuery(name)
    onChange({ customer: null, name, phone: '', saveCustomer: false })
    setIsOpen(true)
  }

  const freeName = value.customer ? '' : value.name.trim()

  return (
    <div className="counter-customer-picker" ref={rootRef}>
      <div className="counter-customer-picker__header">
        <strong>Cliente</strong>
        <small>Opcional</small>
      </div>

      {value.customer && !isOpen ? (
        <div className="counter-customer-picker__selected">
          <div>
            <strong>{value.customer.name}</strong>
            <span>{value.customer.phoneLabel}</span>
          </div>
          <div>
            <button disabled={disabled} onClick={() => setIsOpen(true)} type="button">Trocar</button>
            <button disabled={disabled} onClick={clearCustomer} type="button">Remover</button>
          </div>
        </div>
      ) : (
        <div className="counter-customer-picker__search">
          <input
            aria-label="Buscar cliente por nome ou telefone"
            autoComplete="off"
            disabled={disabled}
            onChange={(event) => changeFreeName(event.target.value)}
            onFocus={() => setIsOpen(true)}
            onKeyDown={(event) => {
              if (event.key === 'Escape') setIsOpen(false)
            }}
            placeholder="Buscar por nome ou telefone"
            type="search"
            value={searchValue}
          />
          {!value.customer && !freeName && !isOpen ? <span>Nenhum cliente vinculado</span> : null}
        </div>
      )}

      {isOpen ? (
        <div className="counter-customer-picker__results" role="listbox">
          {isLoading ? <span>Buscando clientes...</span> : null}
          {searchError ? <span role="alert">{searchError}</span> : null}
          {freeName ? (
            <button className="counter-customer-picker__walk-in" onClick={() => setIsOpen(false)} type="button">
              <strong>Usar “{freeName}” nesta venda</strong>
              <small>Sem criar cadastro automaticamente</small>
            </button>
          ) : null}
          {!isLoading && !searchError && results.length === 0 && !freeName ? <span>Nenhum cliente encontrado.</span> : null}
          {!searchError ? results.map((customer) => (
            <button
              aria-selected={value.customer?.id === customer.id}
              key={customer.id}
              onClick={() => chooseCustomer(customer)}
              role="option"
              type="button"
            >
              <strong>{customer.name}</strong>
              <small>{customer.phoneLabel}</small>
            </button>
          )) : null}
        </div>
      ) : null}

      {!value.customer && freeName ? (
        <div className="counter-customer-picker__walk-in-options">
          <span>Nome avulso: <strong>{freeName}</strong></span>
          <label>
            <input
              checked={value.saveCustomer}
              className="counter-customer-picker__save-checkbox"
              disabled={disabled}
              onChange={(event) => onChange({ ...value, saveCustomer: event.target.checked, phone: event.target.checked ? value.phone : '' })}
              type="checkbox"
            />
            <span>Salvar como cliente para próximas vendas</span>
          </label>
          {value.saveCustomer ? (
            <label className="counter-customer-picker__phone">
              <span>Telefone <small>(opcional)</small></span>
              <input
                autoComplete="tel"
                disabled={disabled}
                onChange={(event) => onChange({ ...value, phone: event.target.value })}
                placeholder="(62) 9 9999-9999"
                type="tel"
                value={value.phone}
              />
            </label>
          ) : null}
        </div>
      ) : null}

      {onApply ? (
        <Button disabled={disabled} onClick={() => void onApply(value)} size="sm" variant="secondary">
          Aplicar à comanda
        </Button>
      ) : null}
    </div>
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
        <div className="counter-sales-history__field counter-sales-history__field--date">
          <DatePickerField label="Data" onChange={onSelectedDateChange} value={selectedDate} />
        </div>
        <button className={!selectedDate ? 'counter-sales__filter is-active' : 'counter-sales__filter'} onClick={() => onSelectedDateChange('')} type="button">
          Hoje
        </button>
        <div className="counter-sales-history__field counter-sales-history__field--payment">
          <SelectField
            label="Pagamento"
            onChange={(value) => onPaymentMethodChange((value || undefined) as CounterSaleHistoryFilters['paymentMethod'])}
            options={[
              { value: '', label: 'Todos' },
              { value: 'cash', label: 'Dinheiro' },
              { value: 'pix', label: 'Pix' },
              { value: 'debit_card', label: 'Cartão de débito' },
              { value: 'credit_card', label: 'Cartão de crédito' },
            ]}
            value={paymentMethod ?? ''}
          />
        </div>
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
                        <small>
                          {sale.itemsQuantity} {sale.itemsQuantity === 1 ? 'item' : 'itens'} · {sale.paymentMethodLabel}
                          {customerDisplayName(sale) ? ` · ${customerDisplayName(sale)}` : ''}
                        </small>
                        <small>Atendente: {sale.sellerName ?? 'Não atribuído'}</small>
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
        <div><span>Atendente</span><strong>{detail.sellerName ?? 'Não atribuído'}</strong></div>
        <div><span>Total</span><strong>{formatCents(detail.totalCents)}</strong></div>
        {customerDisplayName(detail) ? <div><span>Cliente</span><strong>{customerDisplayName(detail)}</strong></div> : null}
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
                {item.weightGrams !== null && item.pricePerKgCents !== null ? (
                  <span>{item.weightGrams} g · {formatCents(item.pricePerKgCents)}/kg</span>
                ) : (
                  <span>{item.quantity} × {formatCents(item.unitPriceCents)}</span>
                )}
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

function counterSellerOptions(
  sellers: SellerSummary[],
  current: { seller: SellerSummary | null; sellerName: string | null } | null = null,
) {
  const currentIsUnavailable = current?.seller && !sellers.some((seller) => seller.id === current.seller?.id)
  const historical = current?.sellerName && (!current.seller || currentIsUnavailable)
    ? [{
        value: current.seller?.id ?? '__historical_seller__',
        label: `${current.sellerName} (histórico)`,
        disabled: true,
      }]
    : []

  return [
    { value: '', label: 'Não atribuído' },
    ...historical,
    ...sellers.map((seller) => ({ value: seller.id, label: seller.name })),
  ]
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

function isWeightProduct(product: CounterSaleProduct): boolean {
  return product.pricing_mode === 'weight'
}

function isSelfService(product: CounterSaleProduct): boolean {
  return product.menu_rule_code === 'self_service_counter'
}

function canOpenDraft(product: CounterSaleProduct): boolean {
  return ['self_service_counter', 'counter_weight_standard', 'counter_weight_meat_only'].includes(product.menu_rule_code ?? '')
}

function requiresItemEditor(product: CounterSaleProduct): boolean {
  return isWeightProduct(product) || isSelfService(product)
}

function editorDescription(
  mode: ItemEditorMode,
  product: CounterSaleProduct | null,
  draft: CounterSaleDraft | null,
): string {
  if (mode === 'new-draft') {
    return product && isWeightProduct(product)
      ? 'Abra a comanda agora. O peso será anotado na ficha e informado no fechamento.'
      : 'Registre os detalhes já conhecidos ou abra a comanda para completar depois.'
  }

  if (mode === 'finalize-draft') {
    return `Complete os dados da comanda ${draft?.code ?? ''} e confirme o pagamento.`
  }

  return product && isWeightProduct(product)
    ? 'Informe o peso em gramas. O backend confirmará o valor final.'
    : 'Configure este prato antes de adicioná-lo à venda.'
}

function editorPrimaryLabel(mode: ItemEditorMode, isSubmitting: boolean): string {
  if (isSubmitting) return mode === 'new-draft' ? 'Abrindo...' : 'Finalizando...'
  if (mode === 'new-draft') return 'Abrir comanda'
  if (mode === 'finalize-draft') return 'Finalizar comanda'

  return 'Adicionar ao carrinho'
}

function extraBeefAddition(product: CounterSaleProduct) {
  return product.additions.find((addition) => addition.code === 'extra_beef' && addition.max_quantity >= 1)
}

function extraBeefPriceCents(product: CounterSaleProduct): number {
  return extraBeefAddition(product)?.price_cents ?? 0
}

function extraBeefName(product: CounterSaleProduct): string {
  return extraBeefAddition(product)?.name ?? 'Bife adicional'
}

function parseWeightGrams(value: string): number | undefined {
  if (!/^\d+$/.test(value)) return undefined

  const weight = Number(value)

  return Number.isSafeInteger(weight) && weight > 0 ? weight : undefined
}

function isEditorInputValid(product: CounterSaleProduct, weightInput: string): boolean {
  return !isWeightProduct(product) || parseWeightGrams(weightInput) !== undefined
}

function weightSubtotalCents(weightGrams: number, pricePerKgCents: number): number {
  return Math.floor(((weightGrams * pricePerKgCents) + 500) / 1000)
}

function cartItemTotalCents(item: CounterSaleCartItem): number {
  const base = item.weightGrams !== undefined
    ? weightSubtotalCents(item.weightGrams, priceCents(item.product))
    : priceCents(item.product) * item.quantity

  return base + (item.extraBeef ? extraBeefPriceCents(item.product) : 0)
}

function formatProductPrice(product: CounterSaleProduct): string {
  const price = formatCents(priceCents(product))

  return isWeightProduct(product) ? `${price}/kg` : price
}

function splitComponentNotes(value: string): string[] {
  return value.split(/[,\n]/).map((component) => component.trim()).filter(Boolean)
}

function customerInputFromDraft(draft: CounterSaleDraft): CounterSaleCustomerInput {
  if (draft.customer) {
    return { customer: draft.customer, name: '', phone: '', saveCustomer: false }
  }

  return {
    customer: null,
    name: draft.customerSnapshot?.name ?? '',
    phone: draft.customerSnapshot?.phone ?? '',
    saveCustomer: false,
  }
}

function customerPayload(input: CounterSaleCustomerInput) {
  if (input.customer) {
    const id = Number(input.customer.id)

    return {
      customer_id: Number.isSafeInteger(id) && id > 0 ? id : null,
      customer_name: null,
      customer_phone: null,
      save_customer: false,
    }
  }

  const name = input.name.trim()

  return {
    customer_id: null,
    customer_name: name || null,
    customer_phone: input.saveCustomer ? input.phone.trim() || null : null,
    save_customer: input.saveCustomer && Boolean(name),
  }
}

function customerDisplayName(value: {
  customer: CounterSaleCustomer | null
  customerSnapshot: { name: string; phone: string | null } | null
}): string | null {
  return value.customer?.name ?? value.customerSnapshot?.name ?? null
}

function formatCents(cents: number): string {
  return formatCurrency(cents / 100)
}
