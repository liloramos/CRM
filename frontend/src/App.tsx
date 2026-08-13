import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import './App.css'
import { AppShell } from './components/layout/AppShell'
import { Modal } from './components/ui/Modal'
import { LoadingState } from './components/ui/States'
import { modalDescription, modalTitle } from './constants/modals'
import { LoginPage } from './features/auth/LoginPage'
import { useAuth } from './features/auth/auth-state'
import { MenuPage } from './features/cardapio/MenuPage'
import { CustomersPage } from './features/clientes/CustomersPage'
import { ConversationsPage } from './features/conversas/ConversationsPage'
import { SettingsPage } from './features/configuracoes/SettingsPage'
import { DashboardPage } from './features/dashboard/DashboardPage'
import { DeliveryPage } from './features/entregas/DeliveryPage'
import { FinancePage } from './features/financeiro/FinancePage'
import { OperationalModalContent, type AutomationModeSelection } from './features/pedidos/OperationalModalContent'
import { OrdersPage } from './features/pedidos/OrdersPage'
import { ReportsPage } from './features/relatorios/ReportsPage'
import {
  addOrderItem,
  cancelOrder,
  cleanupTestOrders,
  confirmOrderPayment,
  createCustomer,
  createDraftOrder,
  deleteDraftOrder,
  deleteOrdersPermanently,
  deleteOrderPermanently,
  describeApiError,
  generateTicketPreview,
  acknowledgeConversationAlert,
  approveConversationPaymentProof,
  getConversations,
  getOrderTicketPreviewUrl,
  getOperationalSnapshot,
  markConversationAsRead as markConversationAsReadRequest,
  toggleConversationMessagePin,
  rejectConversationPaymentProof,
  resolveConversationAlert,
  retryConversationMessage,
  searchCustomers,
  sendConversationMessage,
  sendConversationMedia,
  type ConversationMediaSendOptions,
  setConversationAutomationMode,
  updateCustomer,
  updateOrderStatus,
  ApiError,
} from './services/crm.service'
import type {
  AddItemContext,
  AppModal,
  AuthUser,
  BackendOrderStatus,
  Conversation,
  ConversationAlert,
  CustomerSummary,
  FulfillmentApiType,
  OperationalSnapshot,
  PrintPreviewResult,
  Product,
  RouteKey,
  SnapshotSource,
  StructuredComponentOption,
  StructuredProductOption,
} from './types/crm'
import type { UpdateCustomerPayload } from './services/crm.service'
import { getOrderOperationalState, isOrderInActiveQueue } from './features/pedidos/orderOperationalState'

type OrderItemOptionPayload =
  | {
      structured_options: Array<{
        component_link_id?: number
        product_link_id?: number
        quantity?: number
      }>
      included_component_ids?: number[]
      removed_component_ids?: number[]
      meat_mode?: 'traditional' | 'beef_only'
      traditional_meat_component_ids?: number[]
      additions?: Array<{
        code: string
        quantity: number
      }>
      options?: never
    }
  | {
      options: Array<{
        product_option_id: string
        quantity?: number
      }>
      structured_options?: never
      included_component_ids?: never
      removed_component_ids?: never
      meat_mode?: never
      traditional_meat_component_ids?: never
      additions?: never
    }

type BlockedOrderDeletion = {
  order_id: string
  code?: string | null
  reasons: string[]
}

type MeatModeSelection = 'traditional' | 'beef_only'

function App() {
  const { logout, status: authStatus, user } = useAuth()
  const [activeRoute, setActiveRoute] = useState<RouteKey>('dashboard')
  const [snapshot, setSnapshot] = useState<OperationalSnapshot | null>(null)
  const [snapshotSource, setSnapshotSource] = useState<SnapshotSource>('api')
  const [isLoadingSnapshot, setIsLoadingSnapshot] = useState(false)
  const [snapshotError, setSnapshotError] = useState<string | null>(null)
  const [isLoadingConversations, setIsLoadingConversations] = useState(false)
  const [conversationError, setConversationError] = useState<string | null>(null)
  const [conversationAlerts, setConversationAlerts] = useState<ConversationAlert[]>([])
  const conversationSyncAtRef = useRef<string | null>(null)
  const conversationPollingBusyRef = useRef(false)
  const conversationReadBusyRef = useRef<Set<string>>(new Set())
  const [selectedOrderId, setSelectedOrderId] = useState<string | null>(null)
  const [selectedConversationId, setSelectedConversationId] = useState<string | null>(null)
  const [activeModal, setActiveModal] = useState<AppModal>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [isActionBusy, setIsActionBusy] = useState(false)
  const [selectedProductId, setSelectedProductId] = useState<string>('')
  const [itemQuantity, setItemQuantity] = useState(1)
  const [itemNotes, setItemNotes] = useState('')
  const [itemHasDifferentBeneficiary, setItemHasDifferentBeneficiary] = useState(false)
  const [beneficiaryName, setBeneficiaryName] = useState('')
  const [selectedOptionIds, setSelectedOptionIds] = useState<string[]>([])
  const [itemMeatMode, setItemMeatMode] = useState<MeatModeSelection>('traditional')
  const [itemExtraBeef, setItemExtraBeef] = useState(false)
  const [printPreview, setPrintPreview] = useState<PrintPreviewResult | null>(null)
  const [automationMode, setAutomationMode] = useState<AutomationModeSelection>('assisted')
  const [addItemContext, setAddItemContext] = useState<AddItemContext | null>(null)
  const [newOrderCustomerQuery, setNewOrderCustomerQuery] = useState('')
  const [newOrderCustomerResults, setNewOrderCustomerResults] = useState<CustomerSummary[]>([])
  const [selectedNewOrderCustomer, setSelectedNewOrderCustomer] = useState<CustomerSummary | null>(null)
  const [isSearchingCustomers, setIsSearchingCustomers] = useState(false)
  const [newCustomerMode, setNewCustomerMode] = useState(false)
  const [newCustomerName, setNewCustomerName] = useState('')
  const [newCustomerPhone, setNewCustomerPhone] = useState('')
  const [newOrderWalkInPhone, setNewOrderWalkInPhone] = useState('')
  const [newOrderNotes, setNewOrderNotes] = useState('')
  const [newOrderFulfillmentType, setNewOrderFulfillmentType] = useState<FulfillmentApiType>('pickup')
  const [statusTarget, setStatusTarget] = useState<BackendOrderStatus | ''>('')
  const [statusReason, setStatusReason] = useState('')
  const [statusNotes, setStatusNotes] = useState('')
  const [cancelReason, setCancelReason] = useState('')
  const [cancelNotes, setCancelNotes] = useState('')
  const [paymentMethod, setPaymentMethod] = useState<'pix' | 'cash' | 'debit_card' | 'credit_card' | 'customer_credit' | 'other'>('pix')
  const [paymentAmount, setPaymentAmount] = useState('')
  const [paymentNotes, setPaymentNotes] = useState('')
  const [deleteConfirmation, setDeleteConfirmation] = useState('')
  const [bulkDeleteOrderIds, setBulkDeleteOrderIds] = useState<string[]>([])
  const [blockedOrderDeletions, setBlockedOrderDeletions] = useState<BlockedOrderDeletion[]>([])

  const loadSnapshot = useCallback(async () => {
    setIsLoadingSnapshot(true)
    setSnapshotError(null)

    try {
      const response = await getOperationalSnapshot()
      setSnapshot(response.snapshot)
      setSnapshotSource(response.source)
      setSelectedOrderId((current) => {
        const currentOrder = current ? response.snapshot.orders.find((order) => order.id === current) : undefined
        const firstActiveOrder = response.snapshot.orders.find(isOrderInActiveQueue)

        if (currentOrder) {
          return current
        }

        return firstActiveOrder?.id ?? null
      })
      setSelectedConversationId((current) => current ?? response.snapshot.conversations[0]?.id ?? null)
      setSelectedProductId((current) => current || response.snapshot.products[0]?.id || '')
    } catch (error) {
      setSnapshotError(describeApiError(error, 'Não foi possível atualizar o painel.'))
      setSnapshot((current) => current ?? emptyOperationalSnapshot(user))
      setSnapshotSource('api')
    } finally {
      setIsLoadingSnapshot(false)
    }
  }, [user])

  const replaceConversation = useCallback((conversation: Conversation) => {
    setSnapshot((current) => {
      if (!current) {
        return current
      }

      return {
        ...current,
        conversations: mergeConversations(current.conversations, [conversation]),
      }
    })
    setSelectedConversationId(conversation.id)
  }, [])

  const markConversationRead = useCallback(async (conversationId: string) => {
    if (conversationReadBusyRef.current.has(conversationId)) {
      return
    }

    conversationReadBusyRef.current.add(conversationId)

    try {
      const conversation = await markConversationAsReadRequest(conversationId)
      setSnapshot((current) => {
        if (!current) {
          return current
        }

        return {
          ...current,
          conversations: mergeConversations(current.conversations, [conversation]),
        }
      })
    } catch {
      // Polling restores the server state if the local read request fails.
    } finally {
      conversationReadBusyRef.current.delete(conversationId)
    }
  }, [])

  const replaceCustomer = useCallback((customer: CustomerSummary) => {
    setSnapshot((current) => {
      if (!current) {
        return current
      }

      const customerExists = current.customers.some((candidate) => candidate.id === customer.id)

      return {
        ...current,
        customers: customerExists
          ? current.customers.map((candidate) => (candidate.id === customer.id ? customer : candidate))
          : [...current.customers, customer].sort((first, second) => first.name.localeCompare(second.name, 'pt-BR')),
        conversations: current.conversations.map((conversation) => (
          conversation.customer.id === customer.id ? { ...conversation, customer } : conversation
        )),
      }
    })
  }, [])

  const loadConversations = useCallback(async (incremental = false) => {
    if (!user?.permissions.includes('whatsapp.view') || conversationPollingBusyRef.current) {
      return
    }

    conversationPollingBusyRef.current = true
    setIsLoadingConversations(!incremental)
    setConversationError(null)

    try {
      const response = await getConversations({
        since: incremental ? conversationSyncAtRef.current : null,
      })

      setConversationAlerts(response.alerts)
      conversationSyncAtRef.current = response.generatedAt ?? new Date().toISOString()
      setSnapshot((current) => {
        if (!current) {
          return current
        }

        const conversations = incremental
          ? mergeConversations(current.conversations, response.conversations)
          : response.conversations

        return {
          ...current,
          conversations,
        }
      })
      setSelectedConversationId((current) => {
        const candidateIds = new Set(response.conversations.map((conversation) => conversation.id))
        if (current && (incremental || candidateIds.has(current))) {
          return current
        }

        return response.conversations[0]?.id ?? (incremental ? current : null)
      })
    } catch (error) {
      if (error instanceof ApiError && error.status === 403) {
        setConversationError('Seu usuário não tem permissão para visualizar conversas do WhatsApp.')
      } else {
        setConversationError(describeApiError(error, 'Não foi possível carregar as conversas.'))
      }
    } finally {
      conversationPollingBusyRef.current = false
      setIsLoadingConversations(false)
    }
  }, [user])

  useEffect(() => {
    if (authStatus === 'authenticated') {
      const timeout = window.setTimeout(() => {
        void loadSnapshot()
      }, 0)

      return () => window.clearTimeout(timeout)
    }

    return undefined
  }, [authStatus, loadSnapshot])

  useEffect(() => {
    if (authStatus !== 'authenticated' || activeRoute !== 'conversas') {
      return undefined
    }

    void loadConversations(false)

    const revalidateWhenVisible = () => {
      if (!document.hidden) {
        void loadConversations(true)
      }
    }

    const interval = window.setInterval(() => {
      if (document.hidden) {
        return
      }

      void loadConversations(true)
    }, 3000)

    window.addEventListener('focus', revalidateWhenVisible)
    document.addEventListener('visibilitychange', revalidateWhenVisible)

    return () => {
      window.clearInterval(interval)
      window.removeEventListener('focus', revalidateWhenVisible)
      document.removeEventListener('visibilitychange', revalidateWhenVisible)
    }
  }, [activeRoute, authStatus, loadConversations])

  useEffect(() => {
    if (activeRoute === 'conversas' && selectedConversationId && !document.hidden) {
      void loadConversations(true)
    }
  }, [activeRoute, loadConversations, selectedConversationId])

  useEffect(() => {
    if (activeModal !== 'new-order' || newCustomerMode) {
      return undefined
    }

    const timeout = window.setTimeout(() => {
      setIsSearchingCustomers(true)
      searchCustomers(newOrderCustomerQuery)
        .then((customers) => {
          setNewOrderCustomerResults(customers)
        })
        .catch(() => {
          setNewOrderCustomerResults([])
        })
        .finally(() => {
          setIsSearchingCustomers(false)
        })
    }, 260)

    return () => window.clearTimeout(timeout)
  }, [activeModal, newCustomerMode, newOrderCustomerQuery])

  const selectedOrder = useMemo(() => {
    if (!snapshot) {
      return undefined
    }

    return snapshot.orders.find((order) => order.id === selectedOrderId) ?? snapshot.orders.find(isOrderInActiveQueue)
  }, [selectedOrderId, snapshot])

  const selectedConversation = useMemo(() => {
    if (!snapshot) {
      return undefined
    }

    return snapshot.conversations.find((conversation) => conversation.id === selectedConversationId) ?? snapshot.conversations[0]
  }, [selectedConversationId, snapshot])
  const selectedConversationReadId = selectedConversation?.id
  const selectedConversationUnread = selectedConversation?.unread ?? 0

  useEffect(() => {
    if (activeRoute !== 'conversas' || document.hidden || !selectedConversationReadId || selectedConversationUnread <= 0) {
      return undefined
    }

    const markVisibleConversationRead = () => {
      if (!document.hidden && selectedConversationUnread > 0) {
        void markConversationRead(selectedConversationReadId)
      }
    }

    markVisibleConversationRead()
    document.addEventListener('visibilitychange', markVisibleConversationRead)

    return () => document.removeEventListener('visibilitychange', markVisibleConversationRead)
  }, [activeRoute, markConversationRead, selectedConversationReadId, selectedConversationUnread])

  const linkedOrder = selectedConversation?.activeOrder
    ?? (selectedConversation?.linkedOrderId
      ? snapshot?.orders.find((order) => order.id === selectedConversation.linkedOrderId)
      : undefined)
  const canManageOrders = user?.permissions.includes('orders.manage') ?? false
  const canPermanentlyDeleteOrders = snapshot?.capabilities.can_permanently_delete_orders ?? canManageOrders
  const canRunDestructiveTestCleanup = snapshot?.capabilities.can_run_destructive_test_cleanup ?? false

  function handleNewOrder() {
    openNewOrderModal()
  }

  async function handleCreateOrder() {
    setIsActionBusy(true)
    setActionError(null)

    try {
      let customer = selectedNewOrderCustomer
      let customerName = newOrderCustomerQuery.trim()
      let customerPhone = newOrderWalkInPhone.trim()

      if (newCustomerMode) {
        if (!newCustomerName.trim()) {
          setActionError('Informe o nome do cliente para criar um pedido real.')
          return
        }

        customer = await createCustomer({
          name: newCustomerName.trim(),
          phone: newCustomerPhone.trim() || undefined,
        })
      }

      if (customer && isPersistedBackendId(customer.id)) {
        customerName = customer.name
        customerPhone = customer.phoneLabel === 'Sem telefone cadastrado' ? '' : customer.phoneLabel
      }

      if (!customerName) {
        setActionError('Selecione um cliente cadastrado ou digite o nome do cliente avulso.')
        return
      }

      const response = await createDraftOrder({
        payer_customer_id: customer && isPersistedBackendId(customer.id) ? customer.id : null,
        customer_name_snapshot: customerName,
        customer_phone_snapshot: customerPhone || null,
        fulfillment_type: newOrderFulfillmentType,
        general_notes: newOrderNotes.trim() || 'Pedido manual criado na operacao local.',
        pickup_person_name: customerName,
      })

      setSelectedOrderId(response.data.id)
      setActiveRoute('pedidos')
      setActiveModal(null)
      resetNewOrderForm()
      await loadSnapshot()
    } catch (error) {
      setActionError(error instanceof Error ? error.message : 'Nao foi possivel criar o pedido.')
    } finally {
      setIsActionBusy(false)
    }
  }

  async function handleModalPrimary() {
    if (activeModal === 'new-order') {
      await handleCreateOrder()
      return
    }

    if (activeModal === 'add-product') {
      await handleAddItem()
      return
    }

    if (activeModal === 'cancel-order') {
      await handleCancelOrder()
      return
    }

    if (activeModal === 'delete-draft') {
      await handleDeleteDraft()
      return
    }

    if (activeModal === 'delete-order-permanent') {
      await handleDeleteOrderPermanently()
      return
    }

    if (activeModal === 'delete-orders-bulk') {
      await handleBulkDeleteOrders()
      return
    }

    if (activeModal === 'cleanup-test-orders') {
      await handleCleanupTestOrders()
      return
    }

    if (activeModal === 'change-status') {
      await handleChangeStatus()
      return
    }

    if (activeModal === 'confirm-payment') {
      await handleConfirmPayment()
      return
    }

    if (activeModal === 'toggle-ai') {
      await handleToggleAutomationMode()
      return
    }

    setActiveModal(null)
  }

  async function handleAddItem() {
    if (!addItemContext || addItemContext.source !== 'api' || !isPersistedBackendId(addItemContext.orderId)) {
      setActionError('O pedido nao possui um ID valido.')
      return
    }

    if (!isPersistedBackendId(addItemContext.product.id)) {
      setActionError('O produto nao possui um ID valido.')
      return
    }

    setIsActionBusy(true)
    setActionError(null)

    try {
      const optionPayload = buildOrderItemOptions(addItemContext.product, selectedOptionIds, itemMeatMode, itemExtraBeef)

      const response = await addOrderItem(addItemContext.orderId, {
        product_id: addItemContext.product.id,
        quantity: itemQuantity,
        item_notes: itemNotes,
        beneficiary_name: itemHasDifferentBeneficiary ? beneficiaryName.trim() || null : null,
        ...optionPayload,
      })

      setSelectedOrderId(response.data.id)
      setSnapshot((current) => {
        if (!current) {
          return current
        }

        const exists = current.orders.some((order) => order.id === response.data.id)

        return {
          ...current,
          orders: exists
            ? current.orders.map((order) => (order.id === response.data.id ? response.data : order))
            : [response.data, ...current.orders],
        }
      })
      setActiveModal(null)
      setAddItemContext(null)
      setItemNotes('')
      setItemQuantity(1)
      setItemHasDifferentBeneficiary(false)
      setBeneficiaryName('')
      setSelectedOptionIds([])
      setItemMeatMode('traditional')
      setItemExtraBeef(false)
      await loadSnapshot()
    } catch (error) {
      setActionError(error instanceof Error ? error.message : 'Nao foi possivel adicionar o item.')
    } finally {
      setIsActionBusy(false)
    }
  }

  function handleProductChange(productId: string) {
    setSelectedProductId(productId)
    setSelectedOptionIds([])
    setItemMeatMode('traditional')
    setItemExtraBeef(false)

    if (activeModal !== 'add-product') {
      return
    }

    const product = snapshot?.products.find((candidate) => candidate.id === productId)

    if (!product || !isPersistedBackendId(product.id)) {
      setActionError('O produto nao possui um ID valido.')
      return
    }

    setActionError(null)
    setAddItemContext((current) => (current ? { ...current, product } : current))
  }

  function handleMeatModeChange(mode: MeatModeSelection) {
    setItemMeatMode(mode)
    setSelectedOptionIds((current) => current.filter((token) => !isDailyMeatToken(token)))

    if (mode === 'beef_only') {
      setItemExtraBeef(false)
    }
  }

  async function handleTicketPreview(orderId: string) {
    setActiveModal('print-preview')
    setPrintPreview(null)
    setActionError(null)
    setIsActionBusy(true)

    try {
      const response = await generateTicketPreview(orderId)
      setSelectedOrderId(response.data.order.id)
      setPrintPreview(response.data.preview)
      await loadSnapshot()
    } catch (error) {
      setActionError(error instanceof Error ? error.message : 'Nao foi possivel gerar a previa da comanda.')
    } finally {
      setIsActionBusy(false)
    }
  }

  async function handleCancelOrder() {
    if (!selectedOrder || !isPersistedBackendId(selectedOrder.id)) {
      setActionError('O pedido nao possui um ID valido.')
      return
    }

    if (!cancelReason.trim()) {
      setActionError('Informe o motivo do cancelamento.')
      return
    }

    setIsActionBusy(true)
    setActionError(null)

    try {
      const nextActiveOrderId = snapshot?.orders.find((order) => order.id !== selectedOrder.id && isOrderInActiveQueue(order))?.id ?? null
      const response = await cancelOrder(selectedOrder.id, {
        reason: cancelReason.trim(),
        notes: cancelNotes.trim() || undefined,
      })

      applyUpdatedOrder(response.data, false)
      setSelectedOrderId(nextActiveOrderId)
      setActiveModal(null)
      setCancelReason('')
      setCancelNotes('')
      await loadSnapshot()
    } catch (error) {
      setActionError(error instanceof Error ? error.message : 'Nao foi possivel cancelar o pedido.')
    } finally {
      setIsActionBusy(false)
    }
  }

  async function handleDeleteDraft() {
    if (!selectedOrder || !isPersistedBackendId(selectedOrder.id)) {
      setActionError('O pedido nao possui um ID valido.')
      return
    }

    if (!getOrderOperationalState(selectedOrder).canDeleteDraft) {
      setActionError('Somente rascunho vazio, sem pagamento ou impressao, pode ser excluido.')
      return
    }

    setIsActionBusy(true)
    setActionError(null)

    try {
      const nextActiveOrderId = snapshot?.orders.find((order) => order.id !== selectedOrder.id && isOrderInActiveQueue(order))?.id ?? null

      await deleteDraftOrder(selectedOrder.id)
      setSnapshot((current) => {
        if (!current) {
          return current
        }

        return {
          ...current,
          orders: current.orders.filter((order) => order.id !== selectedOrder.id),
        }
      })
      setSelectedOrderId(nextActiveOrderId)
      setActiveModal(null)
      await loadSnapshot()
    } catch (error) {
      setActionError(error instanceof Error ? error.message : 'Nao foi possivel excluir o rascunho.')
    } finally {
      setIsActionBusy(false)
    }
  }

  async function handleDeleteOrderPermanently() {
    if (!selectedOrder || !isPersistedBackendId(selectedOrder.id)) {
      setActionError('O pedido nao possui um ID valido.')
      return
    }

    if (deleteConfirmation !== 'EXCLUIR') {
      setActionError('Digite EXCLUIR para confirmar a exclusao permanente.')
      return
    }

    setIsActionBusy(true)
    setActionError(null)

    try {
      const nextOrderId = snapshot?.orders.find((order) => order.id !== selectedOrder.id && isOrderInActiveQueue(order))?.id ?? null

      const response = await deleteOrderPermanently(selectedOrder.id, deleteConfirmation)
      removeOrdersFromSnapshot([selectedOrder.id])
      setSelectedOrderId(nextOrderId)
      setActiveModal(null)
      setDeleteConfirmation('')
      setBlockedOrderDeletions(response.data.blocked ?? [])
      await loadSnapshot()
    } catch (error) {
      setBlockedOrderDeletions(blockedDeletionsFromError(error))
      setActionError(error instanceof Error ? error.message : 'Nao foi possivel excluir o pedido.')
    } finally {
      setIsActionBusy(false)
    }
  }

  async function handleBulkDeleteOrders() {
    if (bulkDeleteOrderIds.length === 0) {
      setActionError('Selecione ao menos um pedido para limpeza.')
      return
    }

    if (deleteConfirmation !== 'EXCLUIR') {
      setActionError('Digite EXCLUIR para confirmar a limpeza dos pedidos selecionados.')
      return
    }

    setIsActionBusy(true)
    setActionError(null)

    try {
      const selectedSet = new Set(bulkDeleteOrderIds)
      const nextOrderId = snapshot?.orders.find((order) => !selectedSet.has(order.id) && isOrderInActiveQueue(order))?.id ?? null

      const response = await deleteOrdersPermanently(bulkDeleteOrderIds, deleteConfirmation)
      removeOrdersFromSnapshot(response.data.order_ids)
      setSelectedOrderId(nextOrderId)
      setBulkDeleteOrderIds((current) => current.filter((orderId) => !response.data.order_ids.includes(orderId)))
      setDeleteConfirmation('')
      setBlockedOrderDeletions(response.data.blocked ?? [])
      setActiveModal(null)
      await loadSnapshot()
    } catch (error) {
      setBlockedOrderDeletions(blockedDeletionsFromError(error))
      setActionError(error instanceof Error ? error.message : 'Nao foi possivel excluir os pedidos selecionados.')
    } finally {
      setIsActionBusy(false)
    }
  }

  async function handleCleanupTestOrders() {
    if (bulkDeleteOrderIds.length === 0) {
      setActionError('Selecione ao menos um pedido para limpeza de teste.')
      return
    }

    if (deleteConfirmation !== 'EXCLUIR') {
      setActionError('Digite EXCLUIR para confirmar a limpeza ampla dos registros de teste.')
      return
    }

    setIsActionBusy(true)
    setActionError(null)
    setBlockedOrderDeletions([])

    try {
      const selectedSet = new Set(bulkDeleteOrderIds)
      const nextOrderId = snapshot?.orders.find((order) => !selectedSet.has(order.id) && isOrderInActiveQueue(order))?.id ?? null
      const response = await cleanupTestOrders(bulkDeleteOrderIds, deleteConfirmation)

      removeOrdersFromSnapshot(response.data.order_ids)
      setSelectedOrderId(nextOrderId)
      setBulkDeleteOrderIds((current) => current.filter((orderId) => !response.data.order_ids.includes(orderId)))
      setDeleteConfirmation('')
      setActiveModal(null)
      await loadSnapshot()
    } catch (error) {
      setActionError(error instanceof Error ? error.message : 'Nao foi possivel executar a limpeza ampla de teste.')
    } finally {
      setIsActionBusy(false)
    }
  }

  async function handleChangeStatus() {
    if (!selectedOrder || !isPersistedBackendId(selectedOrder.id)) {
      setActionError('O pedido nao possui um ID valido.')
      return
    }

    if (!statusTarget) {
      setActionError('Escolha um status valido para este pedido.')
      return
    }

    setIsActionBusy(true)
    setActionError(null)

    try {
      const response = await updateOrderStatus(selectedOrder.id, {
        status: statusTarget,
        reason: statusReason.trim() || 'manual_status_change',
        notes: statusNotes.trim() || undefined,
      })

      applyUpdatedOrder(response.data)
      setActiveModal(null)
      setStatusReason('')
      setStatusNotes('')
      await loadSnapshot()
    } catch (error) {
      setActionError(error instanceof Error ? error.message : 'Nao foi possivel alterar o status.')
    } finally {
      setIsActionBusy(false)
    }
  }

  async function handleConfirmPayment() {
    if (!selectedOrder || !isPersistedBackendId(selectedOrder.id)) {
      setActionError('O pedido nao possui um ID valido.')
      return
    }

    const amountCents = paymentAmount.trim() ? parseCurrencyInputToCents(paymentAmount) : undefined

    if (amountCents !== undefined && amountCents <= 0) {
      setActionError('Informe um valor de pagamento maior que zero.')
      return
    }

    setIsActionBusy(true)
    setActionError(null)

    try {
      const response = await confirmOrderPayment(selectedOrder.id, {
        method: paymentMethod,
        amount_cents: amountCents,
        notes: paymentNotes.trim() || undefined,
      })

      applyUpdatedOrder(response.data)
      setActiveModal(null)
      setPaymentAmount('')
      setPaymentNotes('')
      await loadSnapshot()
    } catch (error) {
      setActionError(error instanceof Error ? error.message : 'Nao foi possivel confirmar o pagamento.')
    } finally {
      setIsActionBusy(false)
    }
  }

  function handlePrintTicket(orderId: string) {
    setActionError(null)

    if (!orderId.trim() || snapshotSource !== 'api') {
      setActionError('Selecione um pedido real ja salvo no backend antes de imprimir a comanda.')
      setActiveModal('print-error')
      return
    }

    const order = snapshot?.orders.find((candidate) => candidate.id === orderId)

    if (order && !getOrderOperationalState(order).canPrint) {
      setActionError(order.status === 'cancelado' ? 'Pedido cancelado nao libera impressao operacional.' : 'Adicione itens ao pedido antes de imprimir.')
      setActiveModal('print-error')
      return
    }

    const ticketWindow = window.open(
      getOrderTicketPreviewUrl(orderId, true),
      '_blank',
      'popup=yes,width=420,height=720',
    )

    if (!ticketWindow) {
      setActionError('O navegador bloqueou a janela de impressao. Libere pop-ups para este sistema e tente novamente.')
      setActiveModal('print-error')
      return
    }

    ticketWindow.opener = null
    ticketWindow.focus()
  }

  async function handleToggleAutomationMode() {
    if (!selectedConversation) {
      setActionError('Selecione uma conversa antes de alternar IA/manual.')
      return
    }

    setIsActionBusy(true)
    setActionError(null)

    try {
      await setConversationAutomationMode(selectedConversation.id, {
        mode: automationMode,
        reason:
          automationMode === 'manual'
            ? 'Atendimento manual assumido pela interface operacional.'
            : 'IA assistida reativada pela interface operacional.',
      })
      setActiveModal(null)
      await loadSnapshot()
    } catch (error) {
      setActionError(error instanceof Error ? error.message : 'Nao foi possivel alternar o modo da conversa.')
    } finally {
      setIsActionBusy(false)
    }
  }

  async function handleConversationModeChange(conversationId: string, mode: 'assisted' | 'automatic' | 'manual') {
    setIsActionBusy(true)
    setConversationError(null)

    try {
      const conversation = await setConversationAutomationMode(conversationId, {
        mode,
        reason:
          mode === 'manual'
            ? 'Atendimento manual assumido pela interface operacional.'
            : 'Automação reativada pela interface operacional.',
      })
      replaceConversation(conversation)
      void loadConversations(true)
    } catch (error) {
      setConversationError(error instanceof Error ? error.message : 'Não foi possível alterar o modo da conversa.')
    } finally {
      setIsActionBusy(false)
    }
  }

  async function handleConversationSendMessage(
    conversationId: string,
    body: string,
    clientReference: string,
    replyToMessageId?: string,
  ) {
    setIsActionBusy(true)
    setConversationError(null)

    try {
      const conversation = await sendConversationMessage(conversationId, body, clientReference, replyToMessageId)
      replaceConversation(conversation)
      void loadConversations(true)
    } catch (error) {
      const failedConversation = conversationFromApiError(error)
      if (failedConversation) {
        replaceConversation(failedConversation)
      }
      setConversationError(isWhatsAppDeliveryError(error)
        ? null
        : error instanceof Error ? error.message : 'Não foi possível enviar a mensagem.')
      throw error
    } finally {
      setIsActionBusy(false)
    }
  }

  async function handleConversationSendMedia(conversationId: string, file: File, mediaType: 'image' | 'video' | 'document' | 'audio', caption: string, options?: ConversationMediaSendOptions) {
    setIsActionBusy(true)
    try {
      const conversation = await sendConversationMedia(conversationId, file, mediaType, caption, options)
      replaceConversation(conversation)
      void loadConversations(true)
    } finally {
      setIsActionBusy(false)
    }
  }

  async function handleConversationMessagePin(conversationId: string, messageId: string) {
    const conversation = await toggleConversationMessagePin(conversationId, messageId)
    replaceConversation(conversation)
  }

  async function handleConversationRetryMessage(conversationId: string, messageId: string) {
    setIsActionBusy(true)
    setConversationError(null)

    try {
      const conversation = await retryConversationMessage(conversationId, messageId)
      replaceConversation(conversation)
      void loadConversations(true)
    } catch (error) {
      const failedConversation = conversationFromApiError(error)
      if (failedConversation) {
        replaceConversation(failedConversation)
      }
      setConversationError(isWhatsAppDeliveryError(error)
        ? null
        : error instanceof Error ? error.message : 'Não foi possível reenviar a mensagem.')
      throw error
    } finally {
      setIsActionBusy(false)
    }
  }

  async function handleCreateCustomerFromPage(payload: UpdateCustomerPayload) {
    const customer = await createCustomer(payload)
    replaceCustomer(customer)
  }

  async function handleUpdateCustomerFromPage(customerId: string, payload: UpdateCustomerPayload) {
    const customer = await updateCustomer(customerId, payload)
    replaceCustomer(customer)
  }

  async function handleConversationAlertAction(conversationId: string, alertId: string, action: 'acknowledge' | 'resolve') {
    setConversationError(null)

    try {
      const alert = action === 'acknowledge'
        ? await acknowledgeConversationAlert(conversationId, alertId)
        : await resolveConversationAlert(conversationId, alertId)

      setConversationAlerts((current) => mergeConversationAlerts(current, [alert]))
      setSnapshot((current) => {
        if (!current) {
          return current
        }

        return {
          ...current,
          conversations: current.conversations.map((conversation) => {
            if (conversation.id !== conversationId) {
              return conversation
            }

            return {
              ...conversation,
              alerts: mergeConversationAlerts(conversation.alerts ?? [], [alert]),
            }
          }),
        }
      })
    } catch (error) {
      setConversationError(error instanceof Error ? error.message : 'Não foi possível atualizar o alerta.')
    }
  }

  async function handleApproveConversationPayment(conversationId: string, proofId: string, confirmedAmountCents: number, notes?: string) {
    setIsActionBusy(true)
    setConversationError(null)

    try {
      const conversation = await approveConversationPaymentProof(conversationId, proofId, {
        confirmed_amount_cents: confirmedAmountCents,
        notes,
      })
      replaceConversation(conversation)
      await loadSnapshot()
    } catch (error) {
      setConversationError(error instanceof Error ? error.message : 'Não foi possível aprovar o pagamento.')
    } finally {
      setIsActionBusy(false)
    }
  }

  async function handleRejectConversationPayment(conversationId: string, proofId: string, reason: string) {
    setIsActionBusy(true)
    setConversationError(null)

    try {
      const conversation = await rejectConversationPaymentProof(conversationId, proofId, { reason })
      replaceConversation(conversation)
      await loadSnapshot()
    } catch (error) {
      setConversationError(error instanceof Error ? error.message : 'Não foi possível rejeitar o comprovante.')
    } finally {
      setIsActionBusy(false)
    }
  }

  function openModal(modal: AppModal) {
    setActionError(null)
    if (modal === 'new-order') {
      openNewOrderModal()
      return
    }
    if (modal === 'add-product') {
      openAddProductModal()
      return
    }
    if (modal === 'cancel-order') {
      setCancelReason('')
      setCancelNotes('')
    }
    if (modal === 'delete-draft') {
      setActionError(null)
    }
    if (modal === 'delete-order-permanent' || modal === 'delete-orders-bulk') {
      setDeleteConfirmation('')
      setBlockedOrderDeletions([])
    }
    if (modal === 'cleanup-test-orders') {
      setDeleteConfirmation('')
      setBlockedOrderDeletions([])
    }
    if (modal === 'change-status') {
      const nextTransition = selectedOrder?.availableTransitions[0]?.status ?? ''
      setStatusTarget(nextTransition)
      setStatusReason('manual_status_change')
      setStatusNotes('')
    }
    if (modal === 'confirm-payment') {
      setPaymentMethod('pix')
      setPaymentAmount(selectedOrder && selectedOrder.amountDue > 0 ? formatDecimalInput(selectedOrder.amountDue) : '')
      setPaymentNotes('')
    }
    if (modal === 'toggle-ai') {
      setAutomationMode(selectedConversation?.mode === 'manual' || selectedConversation?.mode === 'atencao' ? 'manual' : 'assisted')
    }
    setActiveModal(modal)
  }

  function openNewOrderModal() {
    resetNewOrderForm()
    setActiveModal('new-order')
  }

  function openAddProductModal() {
    setItemHasDifferentBeneficiary(false)
    setBeneficiaryName('')
    setItemNotes('')
    setItemQuantity(1)
    setSelectedOptionIds([])
    setItemMeatMode('traditional')
    setItemExtraBeef(false)

    const product = selectedProductForAddItem(snapshot?.products ?? [], selectedProductId)

    if (!selectedOrder || snapshotSource !== 'api' || !isPersistedBackendId(selectedOrder.id)) {
      setAddItemContext(null)
      setActionError('O pedido nao possui um ID valido.')
      setActiveModal('add-product')
      return
    }

    if (!getOrderOperationalState(selectedOrder).canReceiveItems) {
      setAddItemContext(null)
      setActionError('Este pedido nao aceita novos itens no status atual.')
      setActiveModal('add-product')
      return
    }

    if (!product || !isPersistedBackendId(product.id)) {
      setAddItemContext(null)
      setSelectedProductId('')
      setActionError('O produto nao possui um ID valido.')
      setActiveModal('add-product')
      return
    }

    setSelectedProductId(product.id)
    setAddItemContext({
      orderId: selectedOrder.id,
      orderCode: selectedOrder.code,
      defaultBeneficiaryName: '',
      product,
      source: snapshotSource,
    })
    setActiveModal('add-product')
  }

  function closeModal() {
    if (activeModal === 'add-product') {
      setAddItemContext(null)
      setItemHasDifferentBeneficiary(false)
      setBeneficiaryName('')
      setSelectedOptionIds([])
      setItemMeatMode('traditional')
      setItemExtraBeef(false)
    }

    if (activeModal === 'new-order') {
      resetNewOrderForm()
    }

    if (activeModal === 'delete-order-permanent' || activeModal === 'delete-orders-bulk' || activeModal === 'cleanup-test-orders') {
      setDeleteConfirmation('')
      setBlockedOrderDeletions([])
    }

    setActiveModal(null)
  }

  function applyUpdatedOrder(order: OperationalSnapshot['orders'][number], shouldSelect = true) {
    if (shouldSelect) {
      setSelectedOrderId(order.id)
    }

    setSnapshot((current) => {
      if (!current) {
        return current
      }

      const exists = current.orders.some((candidate) => candidate.id === order.id)

      return {
        ...current,
        orders: exists
          ? current.orders.map((candidate) => (candidate.id === order.id ? order : candidate))
          : [order, ...current.orders],
      }
    })
  }

  function removeOrdersFromSnapshot(orderIds: string[]) {
    const deletedIds = new Set(orderIds)

    setSnapshot((current) => {
      if (!current) {
        return current
      }

      return {
        ...current,
        orders: current.orders.filter((order) => !deletedIds.has(order.id)),
      }
    })
  }

  function resetNewOrderForm() {
    setNewOrderCustomerQuery('')
    setNewOrderCustomerResults([])
    setSelectedNewOrderCustomer(null)
    setIsSearchingCustomers(false)
    setNewCustomerMode(false)
    setNewCustomerName('')
    setNewCustomerPhone('')
    setNewOrderWalkInPhone('')
    setNewOrderNotes('')
    setNewOrderFulfillmentType('pickup')
  }

  function renderPage() {
    if (!snapshot) {
      return null
    }

    switch (activeRoute) {
      case 'login':
      case 'cadastro':
      case 'dashboard':
        return (
          <DashboardPage
            conversations={snapshot.conversations}
            error={snapshotError}
            financeEntries={snapshot.financeEntries}
            financialSummary={snapshot.financialSummary}
            isLoading={isLoadingSnapshot}
            onNavigate={setActiveRoute}
            onNewOrder={() => void handleNewOrder()}
            onRetry={loadSnapshot}
            orders={snapshot.orders}
            paymentMethods={snapshot.paymentMethods}
            source={snapshotSource}
          />
        )
      case 'conversas':
        return (
          <ConversationsPage
            alerts={conversationAlerts}
            conversations={snapshot.conversations}
            error={conversationError}
            isActionBusy={isActionBusy}
            isLoading={isLoadingConversations}
            linkedOrder={linkedOrder}
            onAcknowledgeAlert={(conversationId, alertId) => void handleConversationAlertAction(conversationId, alertId, 'acknowledge')}
            onApprovePayment={handleApproveConversationPayment}
            onChangeMode={handleConversationModeChange}
            onOpenOrders={() => setActiveRoute('pedidos')}
            onPreviewTicket={handleTicketPreview}
            onRejectPayment={handleRejectConversationPayment}
            onResolveAlert={(conversationId, alertId) => void handleConversationAlertAction(conversationId, alertId, 'resolve')}
            onSelectConversation={setSelectedConversationId}
            onRetryMessage={handleConversationRetryMessage}
            onSendMessage={handleConversationSendMessage}
            onSendMedia={handleConversationSendMedia}
            onToggleMessagePin={handleConversationMessagePin}
            onUpdateCustomer={handleUpdateCustomerFromPage}
            selectedConversation={selectedConversation}
          />
        )
      case 'pedidos':
        return (
          <OrdersPage
            canManageOrders={canManageOrders}
            canPermanentlyDeleteOrders={canPermanentlyDeleteOrders}
            canRunDestructiveTestCleanup={canRunDestructiveTestCleanup}
            isLoading={isLoadingSnapshot}
            onNewOrder={handleNewOrder}
            onOpenModal={openModal}
            onPreviewTicket={handleTicketPreview}
            onPrintTicket={handlePrintTicket}
            onRequestBulkDelete={(orderIds) => {
              setBulkDeleteOrderIds(orderIds)
              setDeleteConfirmation('')
              setBlockedOrderDeletions([])
              setActionError(null)
              setActiveModal('delete-orders-bulk')
            }}
            onRequestCleanupTestOrders={(orderIds) => {
              setBulkDeleteOrderIds(orderIds)
              setDeleteConfirmation('')
              setBlockedOrderDeletions([])
              setActionError(null)
              setActiveModal('cleanup-test-orders')
            }}
            onRequestPermanentDelete={() => {
              setDeleteConfirmation('')
              setBlockedOrderDeletions([])
              setActionError(null)
              setActiveModal('delete-order-permanent')
            }}
            onSelectOrder={setSelectedOrderId}
            orders={snapshot.orders}
            selectedOrder={selectedOrder}
          />
        )
      case 'cardapio':
        return (
          <MenuPage
            onOpenModal={openModal}
            user={user}
          />
        )
      case 'entregas':
        return <DeliveryPage deliveries={snapshot.deliveries} />
      case 'pagamentos':
        return (
          <FinancePage
            entries={snapshot.financeEntries}
            expenses={snapshot.expenses}
            mode="pagamentos"
            onOpenModal={openModal}
            paymentMethods={snapshot.paymentMethods}
            summary={snapshot.financialSummary}
          />
        )
      case 'financeiro':
        return (
          <FinancePage
            entries={snapshot.financeEntries}
            expenses={snapshot.expenses}
            mode="financeiro"
            onOpenModal={openModal}
            paymentMethods={snapshot.paymentMethods}
            summary={snapshot.financialSummary}
          />
        )
      case 'clientes':
        return (
          <CustomersPage
            customers={snapshot.customers}
            onCreateCustomer={handleCreateCustomerFromPage}
            onUpdateCustomer={handleUpdateCustomerFromPage}
          />
        )
      case 'relatorios':
        return <ReportsPage />
      case 'whatsapp':
        return <SettingsPage integrations={snapshot.integrations} onNavigate={setActiveRoute} onOpenModal={openModal} variant="whatsapp" />
      case 'ia':
        return <SettingsPage integrations={snapshot.integrations} onNavigate={setActiveRoute} onOpenModal={openModal} variant="ia" />
      case 'perfil':
        return <SettingsPage integrations={snapshot.integrations} onNavigate={setActiveRoute} onOpenModal={openModal} variant="perfil" />
      case 'configuracoes':
      default:
        return (
          <SettingsPage integrations={snapshot.integrations} onNavigate={setActiveRoute} onOpenModal={openModal} variant="configuracoes" />
        )
    }
  }

  if (authStatus === 'checking') {
    return (
      <main className="center-screen">
        <LoadingState description="Preparando seu acesso..." title="Carregando CRM" />
      </main>
    )
  }

  if (authStatus === 'unauthenticated') {
    return <LoginPage />
  }

  if (!snapshot && isLoadingSnapshot) {
    return (
      <main className="center-screen">
        <LoadingState description="Atualizando pedidos, pagamentos e atendimento..." title="Sincronizando operação" />
      </main>
    )
  }

  if (!snapshot) {
    return null
  }

  return (
    <AppShell
      activeRoute={activeRoute}
      onLogout={() => void logout()}
      onNavigate={setActiveRoute}
      user={user}
    >
      {renderPage()}
      <Modal
        closeDisabled={isActionBusy}
        danger={
          activeModal === 'cancel-order'
          || activeModal === 'delete-draft'
          || activeModal === 'delete-order-permanent'
          || activeModal === 'delete-orders-bulk'
          || activeModal === 'cleanup-test-orders'
          || activeModal === 'print-error'
          || activeModal === 'whatsapp-error'
        }
        description={modalDescription(activeModal)}
        onClose={closeModal}
        onPrimary={() => void handleModalPrimary()}
        open={activeModal !== null}
        primaryDisabled={
          isActionBusy
          || (activeModal === 'toggle-ai' && !selectedConversation)
          || (activeModal === 'add-product' && !addItemContext)
          || (activeModal === 'delete-draft' && (!selectedOrder || !getOrderOperationalState(selectedOrder).canDeleteDraft))
          || ((activeModal === 'delete-order-permanent' || activeModal === 'delete-orders-bulk' || activeModal === 'cleanup-test-orders') && deleteConfirmation !== 'EXCLUIR')
        }
        primaryLabel={primaryLabelForModal(activeModal)}
        size={activeModal === 'add-product' || activeModal === 'print-preview' || activeModal === 'new-order' ? 'lg' : 'md'}
        title={modalTitle(activeModal)}
      >
        <OperationalModalContent
          addItemContext={addItemContext}
          actionError={actionError}
          automationMode={automationMode}
          beneficiaryName={beneficiaryName}
          blockedOrderDeletions={blockedOrderDeletions}
          bulkDeleteCount={bulkDeleteOrderIds.length}
          cancelNotes={cancelNotes}
          cancelReason={cancelReason}
          deleteConfirmation={deleteConfirmation}
          itemHasDifferentBeneficiary={itemHasDifferentBeneficiary}
          isActionBusy={isActionBusy}
          isSearchingCustomers={isSearchingCustomers}
          itemExtraBeef={itemExtraBeef}
          itemNotes={itemNotes}
          itemMeatMode={itemMeatMode}
          itemQuantity={itemQuantity}
          modal={activeModal}
          newCustomerMode={newCustomerMode}
          newCustomerName={newCustomerName}
          newCustomerPhone={newCustomerPhone}
          newOrderWalkInPhone={newOrderWalkInPhone}
          newOrderCustomerQuery={newOrderCustomerQuery}
          newOrderCustomerResults={newOrderCustomerResults}
          newOrderFulfillmentType={newOrderFulfillmentType}
          newOrderNotes={newOrderNotes}
          onAutomationModeChange={setAutomationMode}
          onBeneficiaryNameChange={setBeneficiaryName}
          onCancelNotesChange={setCancelNotes}
          onCancelReasonChange={setCancelReason}
          onItemNotesChange={setItemNotes}
          onItemQuantityChange={setItemQuantity}
          onItemHasDifferentBeneficiaryChange={setItemHasDifferentBeneficiary}
          onItemExtraBeefChange={setItemExtraBeef}
          onItemMeatModeChange={handleMeatModeChange}
          onNewCustomerModeChange={setNewCustomerMode}
          onNewCustomerNameChange={setNewCustomerName}
          onNewCustomerPhoneChange={setNewCustomerPhone}
          onNewOrderCustomerQueryChange={setNewOrderCustomerQuery}
          onNewOrderFulfillmentTypeChange={setNewOrderFulfillmentType}
          onNewOrderNotesChange={setNewOrderNotes}
          onNewOrderWalkInPhoneChange={setNewOrderWalkInPhone}
          onPaymentAmountChange={setPaymentAmount}
          onPaymentMethodChange={setPaymentMethod}
          onPaymentNotesChange={setPaymentNotes}
          onProductChange={handleProductChange}
          onSelectNewOrderCustomer={setSelectedNewOrderCustomer}
          onSelectedOptionsChange={setSelectedOptionIds}
          onStatusNotesChange={setStatusNotes}
          onStatusReasonChange={setStatusReason}
          onStatusTargetChange={setStatusTarget}
          onDeleteConfirmationChange={setDeleteConfirmation}
          paymentAmount={paymentAmount}
          paymentMethod={paymentMethod}
          paymentNotes={paymentNotes}
          printPreview={printPreview}
          products={snapshot.products}
          selectedConversation={selectedConversation}
          selectedNewOrderCustomer={selectedNewOrderCustomer}
          selectedOrder={selectedOrder}
          selectedOptionIds={selectedOptionIds}
          selectedProductId={selectedProductId}
          statusNotes={statusNotes}
          statusReason={statusReason}
          statusTarget={statusTarget}
        />
      </Modal>
    </AppShell>
  )
}

function selectedProductForAddItem(products: Product[], selectedProductId: string): Product | undefined {
  return (
    products.find((product) => product.id === selectedProductId && isPersistedBackendId(product.id)) ??
    products.find((product) => product.available && isPersistedBackendId(product.id)) ??
    products.find((product) => isPersistedBackendId(product.id))
  )
}

function buildOrderItemOptions(
  product: Product,
  selectedOptionIds: string[],
  meatMode: MeatModeSelection,
  extraBeefSelected: boolean,
): OrderItemOptionPayload {
  const groups = product.structuredGroups ?? []

  if (groups.length === 0) {
    return {
      options: selectedOptionIds.map((optionId) => ({
        product_option_id: optionId,
        quantity: 1,
      })),
    }
  }

  const selectedTokens = new Set(selectedOptionIds)
  const removedComponentIds = selectedOptionIds
    .filter(isRemovedComponentToken)
    .map((token) => Number(token.replace('remove-component:', '')))
    .filter((value) => Number.isFinite(value))
  const removedComponentSet = new Set(removedComponentIds)
  const includedComponentIds = groups
    .filter((group) => group.selection_mode === 'fixed')
    .flatMap((group) => group.component_options)
    .filter((option) => option.link_active && !option.requires_confirmation && option.available && !removedComponentSet.has(option.component_id))
    .map((option) => option.component_id)
  const dailyMeatIds = selectedOptionIds
    .filter(isDailyMeatToken)
    .map((token) => Number(token.replace('daily-meat:', '')))
    .filter((value) => Number.isFinite(value))
  const structuredOptions: Array<{
    component_link_id?: number
    product_link_id?: number
    quantity?: number
  }> = []
  const hasBeefRules = product.meatConfiguration !== null && product.meatConfiguration !== undefined

  for (const group of groups) {
    if (hasBeefRules && ['variacao_bife', 'bife_adicional'].includes(group.code)) {
      continue
    }

    if (group.selection_mode === 'fixed') {
      continue
    }

    const componentSelections = group.component_options.filter((option) => selectedTokens.has(componentOptionToken(option.id)))
    const productSelections = group.product_options.filter((option) => selectedTokens.has(productOptionToken(option.id)))
    const choiceCount = componentSelections.length + productSelections.length
    const minChoices = group.min_choices ?? (group.required ? 1 : 0)
    const maxChoices = group.max_choices

    if (choiceCount < minChoices) {
      throw new Error(`Escolha obrigatoria ausente em ${group.label}.`)
    }

    if (maxChoices !== null && choiceCount > maxChoices) {
      throw new Error(`Escolhas acima do limite em ${group.label}.`)
    }

    if (group.same_component_only && choiceCount > 1) {
      throw new Error(`${group.label} nao permite mistura de opcoes.`)
    }

    for (const option of componentSelections) {
      assertStructuredComponentOptionAvailable(option)
      structuredOptions.push({
        component_link_id: option.id,
        quantity: selectionQuantity(group.min_quantity, group.max_quantity, option.included_quantity),
      })
    }

    for (const option of productSelections) {
      assertStructuredProductOptionAvailable(option)
      structuredOptions.push({
        product_link_id: option.id,
        quantity: selectionQuantity(group.min_quantity, group.max_quantity, option.included_quantity),
      })
    }
  }

  if (!hasBeefRules) {
    return {
      structured_options: structuredOptions,
      included_component_ids: includedComponentIds,
      removed_component_ids: removedComponentIds,
    }
  }

  if (meatMode === 'beef_only') {
    if (dailyMeatIds.length > 0 || extraBeefSelected) {
      throw new Error('Somente bife nao pode ser combinado com outras carnes.')
    }

    return {
      structured_options: structuredOptions,
      included_component_ids: includedComponentIds,
      removed_component_ids: removedComponentIds,
      meat_mode: 'beef_only',
      traditional_meat_component_ids: [],
      additions: [],
    }
  }

  const minMeats = product.meatConfiguration?.traditional.selection_rules.min ?? 0
  const maxMeats = product.meatConfiguration?.traditional.selection_rules.max ?? null

  if (dailyMeatIds.length < minMeats) {
    throw new Error('Escolha as carnes obrigatorias desta marmita.')
  }

  if (maxMeats !== null && dailyMeatIds.length > maxMeats) {
    throw new Error('Escolhas acima do limite em carnes.')
  }

  return {
    structured_options: structuredOptions,
    included_component_ids: includedComponentIds,
    removed_component_ids: removedComponentIds,
    meat_mode: 'traditional',
    traditional_meat_component_ids: dailyMeatIds,
    additions: extraBeefSelected ? [{ code: 'extra_beef', quantity: 1 }] : [],
  }
}

function assertStructuredComponentOptionAvailable(option: StructuredComponentOption) {
  if (!option.link_active || option.requires_confirmation || !option.available) {
    throw new Error(`${option.name} nao esta disponivel para este item.`)
  }
}

function assertStructuredProductOptionAvailable(option: StructuredProductOption) {
  if (!option.link_active || option.requires_confirmation || !option.available) {
    throw new Error(`${option.selectable_product.name} nao esta disponivel para este item.`)
  }
}

function selectionQuantity(minQuantity: number | null, maxQuantity: number | null, includedQuantity: number | null): number {
  if (minQuantity !== null && maxQuantity !== null && minQuantity === maxQuantity) {
    return minQuantity
  }

  return Math.max(1, includedQuantity ?? 1)
}

function componentOptionToken(id: number): string {
  return `component:${id}`
}

function productOptionToken(id: number): string {
  return `product:${id}`
}

function isDailyMeatToken(token: string): boolean {
  return token.startsWith('daily-meat:')
}

function isRemovedComponentToken(token: string): boolean {
  return token.startsWith('remove-component:')
}

function parseCurrencyInputToCents(value: string): number {
  const normalized = value.trim().replace(/\./g, '').replace(',', '.')
  const amount = Number.parseFloat(normalized)

  if (Number.isNaN(amount)) {
    return 0
  }

  return Math.round(amount * 100)
}

function formatDecimalInput(value: number): string {
  return value.toFixed(2).replace('.', ',')
}

function isPersistedBackendId(value: string | null | undefined): value is string {
  return typeof value === 'string' && /^[1-9]\d*$/.test(value.trim())
}

function blockedDeletionsFromError(error: unknown): BlockedOrderDeletion[] {
  if (!(error instanceof ApiError) || typeof error.details !== 'object' || error.details === null) {
    return []
  }

  const blocked = 'blocked' in error.details ? error.details.blocked : undefined

  if (!Array.isArray(blocked)) {
    return []
  }

  return blocked.flatMap((entry): BlockedOrderDeletion[] => {
    if (!entry || typeof entry !== 'object' || !('order_id' in entry) || !('reasons' in entry) || !Array.isArray(entry.reasons)) {
      return []
    }

    return [{
      order_id: String(entry.order_id),
      code: 'code' in entry && entry.code !== null ? String(entry.code) : null,
      reasons: entry.reasons.map(String),
    }]
  })
}

function conversationFromApiError(error: unknown): Conversation | null {
  if (!(error instanceof ApiError) || typeof error.details !== 'object' || error.details === null) {
    return null
  }

  const data = 'data' in error.details ? error.details.data : null

  if (!data || typeof data !== 'object' || !('id' in data) || !('messages' in data)) {
    return null
  }

  return data as Conversation
}

function isWhatsAppDeliveryError(error: unknown): boolean {
  return error instanceof ApiError && (error.code?.startsWith('whatsapp_') ?? false)
}

function primaryLabelForModal(modal: AppModal): string {
  switch (modal) {
    case 'new-order':
      return 'Criar pedido'
    case 'delete-draft':
      return 'Excluir rascunho'
    case 'delete-order-permanent':
      return 'Excluir permanentemente'
    case 'delete-orders-bulk':
      return 'Excluir selecionados'
    case 'cleanup-test-orders':
      return 'Limpar pedidos de teste'
    case 'confirm-payment':
      return 'Confirmar pagamento'
    case 'change-status':
      return 'Alterar status'
    case 'cancel-order':
      return 'Cancelar pedido'
    case 'add-product':
      return 'Adicionar item ao pedido'
    case 'toggle-ai':
      return 'Confirmar alteracao'
    default:
      return 'Confirmar'
  }
}

function emptyOperationalSnapshot(user: AuthUser | null): OperationalSnapshot {
  return {
    company: user?.company ?? undefined,
    capabilities: {
      can_permanently_delete_orders: user?.permissions.includes('orders.manage') ?? false,
      can_run_destructive_test_cleanup: false,
      destructive_cleanup_environment: 'unknown',
    },
    orders: [],
    conversations: [],
    customers: [],
    products: [],
    deliveries: [],
    financeEntries: [],
    financialSummary: {
      dateLabel: 'Hoje',
      ordersCount: 0,
      paidOrders: 0,
      pendingOrders: 0,
      grossRevenue: 0,
      confirmedRevenue: 0,
      pendingAmount: 0,
      expensesAmount: 0,
      netProfit: 0,
      pixAmount: 0,
      creditUsed: 0,
      customerCreditBalance: 0,
      averageTicket: 0,
    },
    expenses: [],
    paymentMethods: [],
    integrations: [],
  }
}

function mergeConversations(current: Conversation[], incoming: Conversation[]): Conversation[] {
  const byId = new Map<string, Conversation>()

  current.forEach((conversation) => {
    byId.set(conversation.id, conversation)
  })
  incoming.forEach((conversation) => {
    byId.set(conversation.id, conversation)
  })

  return Array.from(byId.values()).sort((first, second) => {
    const firstTime = first.lastMessageAt ? Date.parse(first.lastMessageAt) : 0
    const secondTime = second.lastMessageAt ? Date.parse(second.lastMessageAt) : 0

    return secondTime - firstTime
  })
}

function mergeConversationAlerts(current: ConversationAlert[], incoming: ConversationAlert[]): ConversationAlert[] {
  const byId = new Map<string, ConversationAlert>()

  current.forEach((alert) => {
    byId.set(alert.id, alert)
  })
  incoming.forEach((alert) => {
    byId.set(alert.id, alert)
  })

  return Array.from(byId.values()).sort((first, second) => {
    const firstTime = first.createdAt ? Date.parse(first.createdAt) : 0
    const secondTime = second.createdAt ? Date.parse(second.createdAt) : 0

    return secondTime - firstTime
  })
}

export default App
