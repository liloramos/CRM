import { useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { EmptyState } from '../../components/ui/States'
import { Tabs } from '../../components/ui/Tabs'
import { conversationModeConfig } from '../../constants/status'
import type { Conversation, ConversationAlert, ConversationMessage, Order } from '../../types/crm'
import { formatCurrency, initialsFromName } from '../../utils/formatters'

type ConversationFilter = 'all' | 'unread' | 'alerts' | 'manual' | 'automatic'

type ConversationsPageProps = {
  alerts: ConversationAlert[]
  conversations: Conversation[]
  error: string | null
  isActionBusy: boolean
  isLoading: boolean
  linkedOrder?: Order | null
  selectedConversation?: Conversation
  syncLabel: string | null
  onAcknowledgeAlert: (conversationId: string, alertId: string) => void
  onApprovePayment: (conversationId: string, proofId: string, confirmedAmountCents: number, notes?: string) => Promise<void>
  onChangeMode: (conversationId: string, mode: 'assisted' | 'automatic' | 'manual') => Promise<void> | void
  onOpenOrders: () => void
  onPreviewTicket: (orderId: string) => void
  onRefresh: () => void
  onRejectPayment: (conversationId: string, proofId: string, reason: string) => Promise<void>
  onResolveAlert: (conversationId: string, alertId: string) => void
  onSelectConversation: (conversationId: string) => void
  onSendMessage: (conversationId: string, body: string) => Promise<void>
}

export function ConversationsPage({
  alerts,
  conversations,
  error,
  isActionBusy,
  isLoading,
  linkedOrder,
  onAcknowledgeAlert,
  onApprovePayment,
  onChangeMode,
  onOpenOrders,
  onPreviewTicket,
  onRefresh,
  onRejectPayment,
  onResolveAlert,
  onSelectConversation,
  onSendMessage,
  selectedConversation,
  syncLabel,
}: ConversationsPageProps) {
  const [activeFilter, setActiveFilter] = useState<ConversationFilter>('all')
  const [search, setSearch] = useState('')
  const [composerBody, setComposerBody] = useState('')
  const [paymentAmount, setPaymentAmount] = useState('')
  const [paymentNotes, setPaymentNotes] = useState('')
  const [paymentRejectReason, setPaymentRejectReason] = useState('')
  const [localError, setLocalError] = useState<string | null>(null)

  const filteredConversations = useMemo(() => {
    const needle = normalize(search)

    return conversations.filter((conversation) => {
      const matchesSearch = needle === ''
        || normalize(conversation.customer.name).includes(needle)
        || normalize(conversation.customer.phoneLabel).includes(needle)
        || normalize(conversation.lastMessage).includes(needle)

      if (!matchesSearch) {
        return false
      }

      if (activeFilter === 'unread') {
        return conversation.unread > 0
      }

      if (activeFilter === 'alerts') {
        return (conversation.alerts ?? []).some((alert) => alert.status !== 'resolved')
      }

      if (activeFilter === 'manual') {
        return conversation.automationMode === 'manual' || conversation.mode === 'manual' || conversation.mode === 'atencao'
      }

      if (activeFilter === 'automatic') {
        return conversation.automationMode !== 'manual' && conversation.mode === 'ia'
      }

      return true
    })
  }, [activeFilter, conversations, search])

  const selectedMode = selectedConversation ? conversationModeConfig[selectedConversation.mode] : conversationModeConfig.manual
  const activeAlerts = (selectedConversation?.alerts ?? []).filter((alert) => alert.status !== 'resolved')
  const review = selectedConversation?.paymentReview ?? null
  const defaultPaymentAmount = review?.amountCents ? review.amountCents / 100 : review?.expectedTotal

  async function handleSubmitMessage(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setLocalError(null)

    if (!selectedConversation) {
      setLocalError('Selecione uma conversa antes de responder.')
      return
    }

    const body = composerBody.trim()
    if (!body) {
      setLocalError('Digite uma mensagem para enviar.')
      return
    }

    try {
      await onSendMessage(selectedConversation.id, body)
      setComposerBody('')
    } catch {
      setLocalError('A mensagem nao foi enviada. O texto foi mantido para nova tentativa.')
    }
  }

  async function handleApprovePayment() {
    if (!selectedConversation || !review?.proofId) {
      return
    }

    const amount = parseCurrencyToCents(paymentAmount || String(defaultPaymentAmount ?? ''))
    if (amount <= 0) {
      setLocalError('Informe o valor confirmado para aprovar o pagamento.')
      return
    }

    setLocalError(null)
    await onApprovePayment(selectedConversation.id, review.proofId, amount, paymentNotes.trim() || undefined)
    setPaymentAmount('')
    setPaymentNotes('')
  }

  async function handleRejectPayment() {
    if (!selectedConversation || !review?.proofId) {
      return
    }

    const reason = paymentRejectReason.trim()
    if (!reason) {
      setLocalError('Informe o motivo para rejeitar o comprovante.')
      return
    }

    setLocalError(null)
    await onRejectPayment(selectedConversation.id, review.proofId, reason)
    setPaymentRejectReason('')
  }

  return (
    <PageContainer density="wide">
      <PageHeader
        actions={
          <div className="conversation-header-actions">
            <Button icon="refresh" onClick={onRefresh} variant="secondary">
              Atualizar
            </Button>
            <Button icon="orders" onClick={onOpenOrders} variant="secondary">
              Abrir Pedidos
            </Button>
          </div>
        }
        description={syncLabel ? `Atualizado as ${syncLabel}. Atendimento com IA assistida, controle manual e revisao humana de pagamentos.` : 'Atendimento com IA assistida, controle manual e revisao humana de pagamentos.'}
        title="Conversas WhatsApp"
      />

      {error || localError ? (
        <div className="conversation-error" role="alert">
          {error ?? localError}
        </div>
      ) : null}

      <div className="conversation-workspace" aria-busy={isLoading}>
        <Card className="conversation-list-card">
          <div className="conversation-search">
            <label htmlFor="conversation-search">Buscar conversa</label>
            <input
              id="conversation-search"
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Nome ou telefone"
              type="search"
              value={search}
            />
          </div>
          <Tabs
            active={activeFilter}
            onChange={(key) => setActiveFilter(key as ConversationFilter)}
            tabs={[
              { key: 'all', label: 'Todas', count: conversations.length },
              { key: 'unread', label: 'Nao lidas', count: conversations.reduce((sum, item) => sum + item.unread, 0) },
              { key: 'alerts', label: 'Alertas', count: conversations.filter((item) => (item.alerts ?? []).some((alert) => alert.status !== 'resolved')).length },
              { key: 'manual', label: 'Manual', count: conversations.filter((item) => item.automationMode === 'manual' || item.mode === 'manual' || item.mode === 'atencao').length },
              { key: 'automatic', label: 'IA', count: conversations.filter((item) => item.automationMode !== 'manual' && item.mode === 'ia').length },
            ]}
          />
          <div className="conversation-list">
            {filteredConversations.map((conversation) => (
              <button
                className={selectedConversation?.id === conversation.id ? 'conversation-item is-active' : 'conversation-item'}
                key={conversation.id}
                onClick={() => onSelectConversation(conversation.id)}
                type="button"
              >
                <span className="avatar">{initialsFromName(conversation.customer.name)}</span>
                <span className="conversation-item__content">
                  <strong>{conversation.customer.name}</strong>
                  <small>{conversation.customer.phoneLabel}</small>
                  <span>{conversation.lastMessage}</span>
                </span>
                <span className="conversation-item__badges">
                  {conversation.unread > 0 ? <Badge tone="brand" size="sm">{`${conversation.unread}`}</Badge> : null}
                  {(conversation.alerts ?? []).some((alert) => alert.status !== 'resolved') ? <Badge tone="danger" size="sm">Alerta</Badge> : null}
                  <Badge tone={conversationModeConfig[conversation.mode]?.tone ?? 'neutral'} size="sm">
                    {conversationModeConfig[conversation.mode]?.label ?? conversation.statusLabel}
                  </Badge>
                </span>
              </button>
            ))}
          </div>
          {filteredConversations.length === 0 ? (
            <EmptyState description="Nenhuma conversa encontrada para este filtro." title="Sem conversas" />
          ) : null}
        </Card>

        <Card className="chat-panel">
          {selectedConversation ? (
            <>
              <div className="chat-panel__header">
                <div>
                  <strong>{selectedConversation.customer.name}</strong>
                  <span>{selectedConversation.statusLabel}</span>
                </div>
                <div className="conversation-mode-actions">
                  <Badge tone={selectedMode.tone}>{selectedMode.label}</Badge>
                  {selectedConversation.automationMode === 'manual' ? (
                    <Button
                      disabled={isActionBusy}
                      onClick={() => void onChangeMode(selectedConversation.id, 'assisted')}
                      variant="secondary"
                    >
                      Devolver para IA
                    </Button>
                  ) : (
                    <Button
                      disabled={isActionBusy}
                      onClick={() => void onChangeMode(selectedConversation.id, 'manual')}
                      variant="secondary"
                    >
                      Assumir atendimento
                    </Button>
                  )}
                </div>
              </div>

              {activeAlerts.length > 0 ? (
                <div className="conversation-alert-strip">
                  {activeAlerts.map((alert) => (
                    <div className={`conversation-alert conversation-alert--${alert.severity}`} key={alert.id}>
                      <strong>{alert.title}</strong>
                      <span>{alert.message}</span>
                      <div>
                        {alert.status === 'open' ? (
                          <Button onClick={() => onAcknowledgeAlert(selectedConversation.id, alert.id)} size="sm" variant="secondary">
                            Reconhecer
                          </Button>
                        ) : null}
                        <Button onClick={() => onResolveAlert(selectedConversation.id, alert.id)} size="sm" variant="secondary">
                          Resolver
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>
              ) : null}

              <div className="message-list">
                {selectedConversation.messages.map((message) => (
                  <MessageBubble key={message.id} message={message} />
                ))}
                {selectedConversation.messages.length === 0 ? (
                  <EmptyState description="Historico ainda vazio para esta conversa." title="Sem mensagens" />
                ) : null}
              </div>

              <form className="composer" onSubmit={handleSubmitMessage}>
                <textarea
                  aria-label="Mensagem para cliente"
                  disabled={!selectedConversation || isActionBusy}
                  onChange={(event) => setComposerBody(event.target.value)}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter' && !event.shiftKey) {
                      event.preventDefault()
                      event.currentTarget.form?.requestSubmit()
                    }
                  }}
                  placeholder="Digite uma resposta para o cliente..."
                  rows={2}
                  value={composerBody}
                />
                <Button disabled={isActionBusy || composerBody.trim() === ''} icon="arrow" type="submit" variant="primary">
                  Enviar
                </Button>
              </form>
            </>
          ) : (
            <EmptyState description="Selecione uma conversa para iniciar o atendimento." title="Nenhuma conversa selecionada" />
          )}
        </Card>

        <Card className="conversation-context-panel">
          <SectionTitle title="Resumo" />
          {selectedConversation ? (
            <>
              <div className="conversation-customer-card">
                <span className="avatar">{initialsFromName(selectedConversation.customer.name)}</span>
                <div>
                  <strong>{selectedConversation.customer.name}</strong>
                  <span>{selectedConversation.customer.phoneLabel}</span>
                </div>
              </div>

              <div className="conversation-context-block">
                <h3>Pedido ativo</h3>
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
                          <small>{item.additions.join(' · ') || item.notes}</small>
                        </div>
                      ))}
                    </div>
                    <div className="current-order__checks">
                      <span>Pagamento: {linkedOrder.paymentStatus}</span>
                      <span>Status: {linkedOrder.backendStatus ?? linkedOrder.status}</span>
                      <span>Comanda: {linkedOrder.printStatus}</span>
                    </div>
                    <div className="conversation-context-actions">
                      <Button onClick={onOpenOrders} variant="secondary">
                        Abrir pedido
                      </Button>
                      <Button icon="printer" onClick={() => onPreviewTicket(linkedOrder.id)} variant="primary">
                        Visualizar comanda
                      </Button>
                    </div>
                  </div>
                ) : (
                  <EmptyState description="Conversa sem pedido vinculado." title="Nenhum pedido ativo" />
                )}
              </div>

              {review?.proofId ? (
                <div className="conversation-context-block payment-review-card">
                  <h3>Revisar pagamento</h3>
                  <p>Comprovante recebido. A IA nao confirma pagamento; Larissa ou Beatriz precisam aprovar.</p>
                  <dl>
                    <div>
                      <dt>Pedido</dt>
                      <dd>{review.orderCode}</dd>
                    </div>
                    <div>
                      <dt>Total esperado</dt>
                      <dd>{formatCurrency(review.expectedTotal)}</dd>
                    </div>
                    <div>
                      <dt>Arquivo</dt>
                      <dd>
                        {review.mediaUrl ? (
                          <a href={review.mediaUrl} rel="noreferrer" target="_blank">
                            {review.fileName ?? 'Comprovante'}
                          </a>
                        ) : review.fileName ?? 'Arquivo protegido'}
                      </dd>
                    </div>
                  </dl>
                  <label>
                    Valor confirmado
                    <input
                      onChange={(event) => setPaymentAmount(event.target.value)}
                      placeholder={formatCurrency(defaultPaymentAmount ?? review.expectedTotal)}
                      value={paymentAmount}
                    />
                  </label>
                  <label>
                    Observacao da aprovacao
                    <textarea onChange={(event) => setPaymentNotes(event.target.value)} rows={2} value={paymentNotes} />
                  </label>
                  <div className="conversation-context-actions">
                    <Button disabled={isActionBusy} onClick={() => void handleApprovePayment()} variant="primary">
                      Aprovar pagamento
                    </Button>
                  </div>
                  <label>
                    Motivo da rejeicao
                    <textarea onChange={(event) => setPaymentRejectReason(event.target.value)} rows={2} value={paymentRejectReason} />
                  </label>
                  <Button disabled={isActionBusy} onClick={() => void handleRejectPayment()} variant="secondary">
                    Rejeitar comprovante
                  </Button>
                </div>
              ) : null}

              <div className="conversation-context-block">
                <h3>Alertas gerais</h3>
                {alerts.length > 0 ? (
                  <div className="conversation-global-alerts">
                    {alerts.slice(0, 6).map((alert) => (
                      <div className={`conversation-alert conversation-alert--${alert.severity}`} key={alert.id}>
                        <strong>{alert.title}</strong>
                        <span>{alert.message}</span>
                      </div>
                    ))}
                  </div>
                ) : (
                  <p className="muted-text">Nenhum alerta aberto no momento.</p>
                )}
              </div>
            </>
          ) : (
            <EmptyState description="Selecione uma conversa para ver cliente, pedido e alertas." title="Sem contexto" />
          )}
        </Card>
      </div>
    </PageContainer>
  )
}

function MessageBubble({ message }: { message: ConversationMessage }) {
  return (
    <div className={`message-bubble message-bubble--${message.sender}`}>
      <div className="message-bubble__meta">
        <strong>{senderLabel(message.sender)}</strong>
        <span>{message.timeLabel}</span>
      </div>
      <p>{message.body}</p>
      {message.media && message.media.length > 0 ? (
        <div className="message-media-list">
          {message.media.map((media) => (
            <a className="message-media" href={media.url ?? '#'} key={media.id} rel="noreferrer" target="_blank">
              {media.mimeType?.startsWith('image/') && media.url ? (
                <img alt={media.name} src={media.url} />
              ) : null}
              <span>{media.name}</span>
            </a>
          ))}
        </div>
      ) : null}
      {message.status ? <small>{message.status}</small> : null}
    </div>
  )
}

function senderLabel(sender: ConversationMessage['sender']): string {
  if (sender === 'ai') {
    return 'IA'
  }

  if (sender === 'attendant') {
    return 'Atendente'
  }

  return 'Cliente'
}

function normalize(value: string): string {
  return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
}

function parseCurrencyToCents(value: string): number {
  const normalized = value.replace(/\./g, '').replace(',', '.').replace(/[^\d.]/g, '')
  const amount = Number.parseFloat(normalized)

  if (!Number.isFinite(amount)) {
    return 0
  }

  return Math.round(amount * 100)
}
