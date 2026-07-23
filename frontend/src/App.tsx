import { useCallback, useEffect, useMemo, useState } from 'react'
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
  createDraftOrder,
  generateTicketPreview,
  getOperationalSnapshot,
  setConversationAutomationMode,
} from './services/crm.service'
import type { AppModal, AuthUser, OperationalSnapshot, PrintPreviewResult, RouteKey, SnapshotSource } from './types/crm'

function App() {
  const { logout, status: authStatus, user } = useAuth()
  const [activeRoute, setActiveRoute] = useState<RouteKey>('dashboard')
  const [snapshot, setSnapshot] = useState<OperationalSnapshot | null>(null)
  const [snapshotSource, setSnapshotSource] = useState<SnapshotSource>('api')
  const [isLoadingSnapshot, setIsLoadingSnapshot] = useState(false)
  const [snapshotError, setSnapshotError] = useState<string | null>(null)
  const [lastSyncedAt, setLastSyncedAt] = useState<Date | null>(null)
  const [selectedOrderId, setSelectedOrderId] = useState<string | null>(null)
  const [selectedConversationId, setSelectedConversationId] = useState<string | null>(null)
  const [activeModal, setActiveModal] = useState<AppModal>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [isActionBusy, setIsActionBusy] = useState(false)
  const [selectedProductId, setSelectedProductId] = useState<string>('')
  const [itemQuantity, setItemQuantity] = useState(1)
  const [itemNotes, setItemNotes] = useState('')
  const [beneficiaryName, setBeneficiaryName] = useState('')
  const [selectedOptionIds, setSelectedOptionIds] = useState<string[]>([])
  const [printPreview, setPrintPreview] = useState<PrintPreviewResult | null>(null)
  const [automationMode, setAutomationMode] = useState<AutomationModeSelection>('assisted')

  const loadSnapshot = useCallback(async () => {
    setIsLoadingSnapshot(true)
    setSnapshotError(null)

    try {
      const response = await getOperationalSnapshot()
      setSnapshot(response.snapshot)
      setSnapshotSource(response.source)
      setLastSyncedAt(new Date())
      setSelectedOrderId((current) => current ?? response.snapshot.orders[0]?.id ?? null)
      setSelectedConversationId((current) => current ?? response.snapshot.conversations[0]?.id ?? null)
      setSelectedProductId((current) => current || response.snapshot.products[0]?.id || '')
    } catch {
      setSnapshotError('Verifique sua conexão e tente novamente.')
      setSnapshot((current) => current ?? emptyOperationalSnapshot(user))
      setSnapshotSource('api')
    } finally {
      setIsLoadingSnapshot(false)
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

  const selectedOrder = useMemo(() => {
    if (!snapshot) {
      return undefined
    }

    return snapshot.orders.find((order) => order.id === selectedOrderId) ?? snapshot.orders[0]
  }, [selectedOrderId, snapshot])

  const selectedConversation = useMemo(() => {
    if (!snapshot) {
      return undefined
    }

    return snapshot.conversations.find((conversation) => conversation.id === selectedConversationId) ?? snapshot.conversations[0]
  }, [selectedConversationId, snapshot])

  const linkedOrder = selectedConversation?.linkedOrderId
    ? snapshot?.orders.find((order) => order.id === selectedConversation.linkedOrderId)
    : undefined

  async function handleNewOrder() {
    setIsActionBusy(true)
    setActionError(null)

    try {
      const response = await createDraftOrder({
        fulfillment_type: 'pickup',
        general_notes: 'Rascunho manual criado na operacao local.',
      })
      setSelectedOrderId(response.data.id)
      setActiveRoute('pedidos')
      await loadSnapshot()
    } catch (error) {
      setActionError(error instanceof Error ? error.message : 'Nao foi possivel criar o rascunho.')
      setActiveModal('add-product')
    } finally {
      setIsActionBusy(false)
    }
  }

  async function handleModalPrimary() {
    if (activeModal === 'add-product') {
      await handleAddItem()
      return
    }

    if (activeModal === 'toggle-ai') {
      await handleToggleAutomationMode()
      return
    }

    setActiveModal(null)
  }

  async function handleAddItem() {
    if (!selectedOrder || !selectedProductId) {
      setActionError('Selecione um pedido e um produto antes de adicionar item.')
      return
    }

    setIsActionBusy(true)
    setActionError(null)

    try {
      const response = await addOrderItem(selectedOrder.id, {
        product_id: selectedProductId,
        quantity: itemQuantity,
        item_notes: itemNotes,
        beneficiary_name: beneficiaryName.trim() || selectedOrder.pickupPerson || selectedOrder.customer.name,
        options: selectedOptionIds.map((optionId) => ({
          product_option_id: optionId,
          quantity: 1,
        })),
      })

      setSelectedOrderId(response.data.id)
      setActiveModal(null)
      setItemNotes('')
      setItemQuantity(1)
      setBeneficiaryName('')
      setSelectedOptionIds([])
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

  function openModal(modal: AppModal) {
    setActionError(null)
    if (modal === 'add-product') {
      setBeneficiaryName('')
      setSelectedOptionIds([])
    }
    if (modal === 'toggle-ai') {
      setAutomationMode(selectedConversation?.mode === 'manual' || selectedConversation?.mode === 'atencao' ? 'manual' : 'assisted')
    }
    setActiveModal(modal)
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
            conversations={snapshot.conversations}
            linkedOrder={linkedOrder}
            onOpenModal={openModal}
            onPreviewTicket={handleTicketPreview}
            onSelectConversation={setSelectedConversationId}
            selectedConversation={selectedConversation}
          />
        )
      case 'pedidos':
        return (
          <OrdersPage
            isLoading={isLoadingSnapshot}
            onNewOrder={handleNewOrder}
            onOpenModal={openModal}
            onPreviewTicket={handleTicketPreview}
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
        return <CustomersPage customers={snapshot.customers} />
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
      isSyncing={isLoadingSnapshot}
      lastSyncedAt={lastSyncedAt}
      onLogout={() => void logout()}
      onNavigate={setActiveRoute}
      onRefresh={() => void loadSnapshot()}
      user={user}
    >
      {renderPage()}
      <Modal
        closeDisabled={isActionBusy}
        danger={activeModal === 'cancel-order' || activeModal === 'print-error' || activeModal === 'whatsapp-error'}
        description={modalDescription(activeModal)}
        onClose={() => setActiveModal(null)}
        onPrimary={() => void handleModalPrimary()}
        open={activeModal !== null}
        primaryDisabled={isActionBusy || (activeModal === 'toggle-ai' && !selectedConversation)}
        primaryLabel={primaryLabelForModal(activeModal)}
        size={activeModal === 'add-product' || activeModal === 'print-preview' ? 'lg' : 'md'}
        title={modalTitle(activeModal)}
      >
        <OperationalModalContent
          actionError={actionError}
          automationMode={automationMode}
          beneficiaryName={beneficiaryName}
          isActionBusy={isActionBusy}
          itemNotes={itemNotes}
          itemQuantity={itemQuantity}
          modal={activeModal}
          onAutomationModeChange={setAutomationMode}
          onBeneficiaryNameChange={setBeneficiaryName}
          onItemNotesChange={setItemNotes}
          onItemQuantityChange={setItemQuantity}
          onProductChange={handleProductChange}
          onSelectedOptionsChange={setSelectedOptionIds}
          printPreview={printPreview}
          products={snapshot.products}
          selectedConversation={selectedConversation}
          selectedOrder={selectedOrder}
          selectedOptionIds={selectedOptionIds}
          selectedProductId={selectedProductId}
        />
      </Modal>
    </AppShell>
  )
}

function primaryLabelForModal(modal: AppModal): string {
  switch (modal) {
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

export default App
