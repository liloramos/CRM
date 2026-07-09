import { useCallback, useEffect, useMemo, useState } from 'react'
import './App.css'
import { AppShell } from './components/layout/AppShell'
import { Modal } from './components/ui/Modal'
import { ErrorState, LoadingState } from './components/ui/States'
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
import { OperationalModalContent } from './features/pedidos/OperationalModalContent'
import { OrdersPage } from './features/pedidos/OrdersPage'
import { ReportsPage } from './features/relatorios/ReportsPage'
import {
  addOrderItem,
  createDraftOrder,
  generateTicketPreview,
  getOperationalSnapshot,
  updateMenuOptionAvailability,
} from './services/crm.service'
import type { AppModal, OperationalSnapshot, PrintPreviewResult, RouteKey, SnapshotSource } from './types/crm'

function App() {
  const { logout, status: authStatus, user } = useAuth()
  const [activeRoute, setActiveRoute] = useState<RouteKey>('dashboard')
  const [snapshot, setSnapshot] = useState<OperationalSnapshot | null>(null)
  const [snapshotSource, setSnapshotSource] = useState<SnapshotSource>('api')
  const [fallbackReason, setFallbackReason] = useState<string | null>(null)
  const [isLoadingSnapshot, setIsLoadingSnapshot] = useState(false)
  const [snapshotError, setSnapshotError] = useState<string | null>(null)
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

  const loadSnapshot = useCallback(async () => {
    setIsLoadingSnapshot(true)
    setSnapshotError(null)

    try {
      const response = await getOperationalSnapshot()
      setSnapshot(response.snapshot)
      setSnapshotSource(response.source)
      setFallbackReason(response.fallbackReason ?? null)
      setSelectedOrderId((current) => current ?? response.snapshot.orders[0]?.id ?? null)
      setSelectedConversationId((current) => current ?? response.snapshot.conversations[0]?.id ?? null)
      setSelectedProductId((current) => current || response.snapshot.products[0]?.id || '')
    } catch (error) {
      setSnapshotError(error instanceof Error ? error.message : 'Nao foi possivel carregar dados operacionais.')
    } finally {
      setIsLoadingSnapshot(false)
    }
  }, [])

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

  async function handleToggleOptionAvailability(optionId: string, availableToday: boolean) {
    setIsActionBusy(true)
    setActionError(null)

    try {
      await updateMenuOptionAvailability(optionId, {
        status: availableToday ? 'unavailable' : 'available',
        reason: availableToday ? 'Marcado como esgotado pela operacao.' : 'Disponibilidade restabelecida pela operacao.',
      })
      await loadSnapshot()
    } catch (error) {
      setActionError(error instanceof Error ? error.message : 'Nao foi possivel atualizar a disponibilidade.')
      setActiveModal('mark-unavailable')
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

  function openModal(modal: AppModal) {
    setActionError(null)
    if (modal === 'add-product') {
      setBeneficiaryName('')
      setSelectedOptionIds([])
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
            financialSummary={snapshot.financialSummary}
            onNavigate={setActiveRoute}
            orders={snapshot.orders}
            paymentMethods={snapshot.paymentMethods}
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
            isUpdating={isActionBusy}
            onOpenModal={openModal}
            onToggleOptionAvailability={(optionId, availableToday) => void handleToggleOptionAvailability(optionId, availableToday)}
            products={snapshot.products}
            source={snapshotSource}
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
        <LoadingState description="Verificando sessao Laravel..." title="Carregando CRM" />
      </main>
    )
  }

  if (authStatus === 'unauthenticated') {
    return <LoginPage />
  }

  if (!snapshot && isLoadingSnapshot) {
    return (
      <main className="center-screen">
        <LoadingState description="Buscando dados operacionais do backend..." title="Sincronizando operacao" />
      </main>
    )
  }

  if (!snapshot && snapshotError) {
    return (
      <main className="center-screen">
        <ErrorState
          actionLabel="Tentar novamente"
          description={snapshotError}
          onAction={() => void loadSnapshot()}
          title="Nao foi possivel abrir o CRM"
        />
      </main>
    )
  }

  if (!snapshot) {
    return null
  }

  return (
    <AppShell
      activeRoute={activeRoute}
      apiSource={snapshotSource}
      fallbackReason={fallbackReason}
      isSyncing={isLoadingSnapshot}
      onLogout={() => void logout()}
      onNavigate={setActiveRoute}
      onNewOrder={() => void handleNewOrder()}
      onRefresh={() => void loadSnapshot()}
      user={user}
    >
      {renderPage()}
      <Modal
        danger={activeModal === 'cancel-order' || activeModal === 'print-error' || activeModal === 'whatsapp-error'}
        description={modalDescription(activeModal)}
        onClose={() => setActiveModal(null)}
        onPrimary={() => void handleModalPrimary()}
        open={activeModal !== null}
        primaryDisabled={isActionBusy}
        primaryLabel={activeModal === 'cancel-order' ? 'Cancelar pedido' : activeModal === 'add-product' ? 'Adicionar item' : 'Confirmar'}
        title={modalTitle(activeModal)}
      >
        <OperationalModalContent
          actionError={actionError}
          beneficiaryName={beneficiaryName}
          isActionBusy={isActionBusy}
          itemNotes={itemNotes}
          itemQuantity={itemQuantity}
          modal={activeModal}
          onBeneficiaryNameChange={setBeneficiaryName}
          onItemNotesChange={setItemNotes}
          onItemQuantityChange={setItemQuantity}
          onProductChange={handleProductChange}
          onSelectedOptionsChange={setSelectedOptionIds}
          printPreview={printPreview}
          products={snapshot.products}
          selectedOrder={selectedOrder}
          selectedOptionIds={selectedOptionIds}
          selectedProductId={selectedProductId}
        />
      </Modal>
    </AppShell>
  )
}

export default App
