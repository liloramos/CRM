import { useState } from 'react'
import './App.css'
import { AppShell } from './components/layout/AppShell'
import { Modal } from './components/ui/Modal'
import { modalDescription, modalTitle } from './constants/modals'
import { AuthPreviewPage } from './features/auth/AuthPreviewPage'
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
import { getOperationalSnapshot } from './services/crm.service'
import type { AppModal, RouteKey } from './types/crm'

const snapshot = getOperationalSnapshot()

function App() {
  const [activeRoute, setActiveRoute] = useState<RouteKey>('dashboard')
  const [selectedOrderId, setSelectedOrderId] = useState(snapshot.orders[0].id)
  const [selectedConversationId, setSelectedConversationId] = useState(snapshot.conversations[0].id)
  const [activeModal, setActiveModal] = useState<AppModal>(null)

  const selectedOrder = snapshot.orders.find((order) => order.id === selectedOrderId) ?? snapshot.orders[0]
  const selectedConversation =
    snapshot.conversations.find((conversation) => conversation.id === selectedConversationId) ?? snapshot.conversations[0]
  const linkedOrder = snapshot.orders.find((order) => order.id === selectedConversation.linkedOrderId)

  function renderPage() {
    switch (activeRoute) {
      case 'login':
      case 'cadastro':
        return <AuthPreviewPage mode={activeRoute} />
      case 'dashboard':
        return <DashboardPage conversations={snapshot.conversations} onNavigate={setActiveRoute} orders={snapshot.orders} />
      case 'conversas':
        return (
          <ConversationsPage
            conversations={snapshot.conversations}
            linkedOrder={linkedOrder}
            onOpenModal={setActiveModal}
            onSelectConversation={setSelectedConversationId}
            selectedConversation={selectedConversation}
          />
        )
      case 'pedidos':
        return (
          <OrdersPage
            onOpenModal={setActiveModal}
            onSelectOrder={setSelectedOrderId}
            orders={snapshot.orders}
            selectedOrder={selectedOrder}
          />
        )
      case 'cardapio':
        return <MenuPage onOpenModal={setActiveModal} products={snapshot.products} />
      case 'entregas':
        return <DeliveryPage deliveries={snapshot.deliveries} />
      case 'pagamentos':
        return <FinancePage entries={snapshot.financeEntries} mode="pagamentos" onOpenModal={setActiveModal} />
      case 'financeiro':
        return <FinancePage entries={snapshot.financeEntries} mode="financeiro" onOpenModal={setActiveModal} />
      case 'clientes':
        return <CustomersPage customers={snapshot.customers} />
      case 'relatorios':
        return <ReportsPage />
      case 'whatsapp':
        return (
          <SettingsPage
            integrations={snapshot.integrations}
            onNavigate={setActiveRoute}
            onOpenModal={setActiveModal}
            variant="whatsapp"
          />
        )
      case 'ia':
        return (
          <SettingsPage
            integrations={snapshot.integrations}
            onNavigate={setActiveRoute}
            onOpenModal={setActiveModal}
            variant="ia"
          />
        )
      case 'perfil':
        return (
          <SettingsPage
            integrations={snapshot.integrations}
            onNavigate={setActiveRoute}
            onOpenModal={setActiveModal}
            variant="perfil"
          />
        )
      case 'configuracoes':
      default:
        return (
          <SettingsPage
            integrations={snapshot.integrations}
            onNavigate={setActiveRoute}
            onOpenModal={setActiveModal}
            variant="configuracoes"
          />
        )
    }
  }

  return (
    <AppShell activeRoute={activeRoute} onNavigate={setActiveRoute} onNewOrder={() => setActiveModal('add-product')}>
      {renderPage()}
      <Modal
        danger={activeModal === 'cancel-order' || activeModal === 'print-error' || activeModal === 'whatsapp-error'}
        description={modalDescription(activeModal)}
        onClose={() => setActiveModal(null)}
        open={activeModal !== null}
        primaryLabel={activeModal === 'cancel-order' ? 'Cancelar pedido' : 'Confirmar'}
        title={modalTitle(activeModal)}
      >
        <OperationalModalContent modal={activeModal} />
      </Modal>
    </AppShell>
  )
}

export default App
