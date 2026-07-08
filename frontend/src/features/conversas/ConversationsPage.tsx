import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { EmptyState } from '../../components/ui/States'
import { Tabs } from '../../components/ui/Tabs'
import { conversationModeConfig } from '../../constants/status'
import type { AppModal, Conversation, Order } from '../../types/crm'
import { formatCurrency, initialsFromName } from '../../utils/formatters'

type ConversationsPageProps = {
  conversations: Conversation[]
  linkedOrder?: Order
  selectedConversation: Conversation
  onOpenModal: (modal: AppModal) => void
  onSelectConversation: (conversationId: string) => void
}

export function ConversationsPage({
  conversations,
  linkedOrder,
  onOpenModal,
  onSelectConversation,
  selectedConversation,
}: ConversationsPageProps) {
  const mode = conversationModeConfig[selectedConversation.mode]

  return (
    <PageContainer density="wide">
      <PageHeader
        actions={
          <Button icon="ai" onClick={() => onOpenModal('toggle-ai')} variant="secondary">
            Alternar IA/manual
          </Button>
        }
        description="Atendimento com pedido em montagem, sugestoes de IA e confirmacao humana."
        title="Conversas"
      />

      <div className="conversation-layout">
        <Card className="conversation-list-card">
          <Tabs
            active="todas"
            onChange={() => undefined}
            tabs={[
              { key: 'todas', label: 'Todas', count: conversations.length },
              { key: 'nao-lidas', label: 'Nao lidas', count: conversations.reduce((sum, item) => sum + item.unread, 0) },
              { key: 'manual', label: 'Manual', count: conversations.filter((item) => item.mode === 'manual').length },
            ]}
          />
          <div className="conversation-list">
            {conversations.map((conversation) => (
              <button
                className={selectedConversation.id === conversation.id ? 'conversation-item is-active' : 'conversation-item'}
                key={conversation.id}
                onClick={() => onSelectConversation(conversation.id)}
                type="button"
              >
                <span className="avatar">{initialsFromName(conversation.customer.name)}</span>
                <div>
                  <strong>{conversation.customer.name}</strong>
                  <p>{conversation.lastMessage}</p>
                </div>
                {conversation.unread > 0 ? <Badge tone="brand" size="sm">{`${conversation.unread}`}</Badge> : null}
              </button>
            ))}
          </div>
        </Card>

        <Card className="chat-panel">
          <div className="chat-panel__header">
            <div>
              <strong>{selectedConversation.customer.name}</strong>
              <span>{selectedConversation.statusLabel}</span>
            </div>
            <Badge tone={mode.tone}>{mode.label}</Badge>
          </div>
          <div className="message-list">
            {selectedConversation.messages.map((message) => (
              <div className={`message-bubble message-bubble--${message.sender}`} key={message.id}>
                <p>{message.body}</p>
                <span>{message.timeLabel}</span>
              </div>
            ))}
          </div>
          <div className="ai-assist-card">
            <Badge tone="manual">Revisao humana</Badge>
            <p>
              A IA pode sugerir resposta e apontar duvidas, mas nao confirma pedido ambiguo, credito,
              pagamento ou entrega sem o atendente.
            </p>
          </div>
          <div className="composer">
            <input aria-label="Mensagem para cliente" placeholder="Digite uma resposta revisada pelo atendente..." />
            <Button icon="arrow" variant="primary">
              Enviar
            </Button>
          </div>
        </Card>

        <Card className="current-order-panel">
          <SectionTitle title="Pedido atual" />
          {linkedOrder ? (
            <div className="current-order">
              <div className="current-order__header">
                <strong>{linkedOrder.code}</strong>
                <span>{formatCurrency(linkedOrder.total)}</span>
              </div>
              <div className="current-order__items">
                {linkedOrder.items.map((item) => (
                  <div key={item.id}>
                    <span>
                      {item.quantity}x {item.name}
                    </span>
                    <small>{item.notes}</small>
                  </div>
                ))}
              </div>
              <div className="current-order__checks">
                <span>Pagamento: {linkedOrder.paymentStatus}</span>
                <span>Comanda: {linkedOrder.printStatus}</span>
                <span>Retirada: {linkedOrder.pickupPerson ?? 'A confirmar'}</span>
              </div>
              <Button icon="printer" onClick={() => onOpenModal('print-error')} variant="primary">
                Imprimir comanda
              </Button>
            </div>
          ) : (
            <EmptyState description="Conversa sem pedido vinculado." title="Nenhum pedido em montagem" />
          )}
        </Card>
      </div>
    </PageContainer>
  )
}
