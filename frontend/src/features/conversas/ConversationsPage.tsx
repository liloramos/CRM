import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import type { FormEvent, RefObject } from 'react'
import { createPortal } from 'react-dom'
import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Button, IconButton } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { Icon } from '../../components/ui/Icon'
import { Modal } from '../../components/ui/Modal'
import { EmptyState } from '../../components/ui/States'
import { CustomerEditor } from '../clientes/CustomerEditor'
import { getConversationQuickReplies, type UpdateCustomerPayload } from '../../services/crm.service'
import type {
  BadgeTone,
  Conversation,
  ConversationAlert,
  ConversationMessage,
  ConversationQuickReply,
  CustomerSummary,
  Order,
} from '../../types/crm'
import { formatCurrency, initialsFromName } from '../../utils/formatters'
import { ConversationConfigurationModal } from './ConversationConfigurationModal'
import { quickReplyCategoryLabel } from './conversationLabels'
import './ConversationsPage.css'

type ConversationFilter = 'all' | 'unread' | 'alerts' | 'manual' | 'automatic'
type ConversationPanel = 'list' | 'chat' | 'details'
type ConversationToastTone = 'info' | 'success' | 'warning' | 'error'
type AlertSeverityFilter = 'all' | ConversationAlert['severity']

type ConversationToast = {
  id: string
  deduplicationKey: string
  message: string
  tone: ConversationToastTone
}

const CONVERSATION_CUSTOMER_FORM_ID = 'conversation-customer-editor'

type ConversationsPageProps = {
  alerts: ConversationAlert[]
  conversations: Conversation[]
  error: string | null
  isActionBusy: boolean
  isLoading: boolean
  linkedOrder?: Order | null
  selectedConversation?: Conversation
  onAcknowledgeAlert: (conversationId: string, alertId: string) => void
  onApprovePayment: (conversationId: string, proofId: string, confirmedAmountCents: number, notes?: string) => Promise<void>
  onChangeMode: (conversationId: string, mode: 'assisted' | 'automatic' | 'manual') => Promise<void> | void
  onOpenOrders: () => void
  onPreviewTicket: (orderId: string) => void
  onRejectPayment: (conversationId: string, proofId: string, reason: string) => Promise<void>
  onResolveAlert: (conversationId: string, alertId: string) => void
  onSelectConversation: (conversationId: string) => void
  onRetryMessage: (conversationId: string, messageId: string) => Promise<void>
  onSendMessage: (conversationId: string, body: string, clientReference: string, replyToMessageId?: string) => Promise<void>
  onSendMedia: (conversationId: string, file: File, mediaType: 'image' | 'document', caption: string) => Promise<void>
  onToggleMessagePin: (conversationId: string, messageId: string) => Promise<void>
  onUpdateCustomer: (customerId: string, payload: UpdateCustomerPayload) => Promise<void>
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
  onRejectPayment,
  onResolveAlert,
  onSelectConversation,
  onRetryMessage,
  onSendMessage,
  onSendMedia,
  onToggleMessagePin,
  onUpdateCustomer,
  selectedConversation,
}: ConversationsPageProps) {
  const [activeFilter, setActiveFilter] = useState<ConversationFilter>('all')
  const [activePanel, setActivePanel] = useState<ConversationPanel>('list')
  const [isContextOpen, setIsContextOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [composerBody, setComposerBody] = useState('')
  const [attachment, setAttachment] = useState<{ file: File; type: 'image' | 'document' } | null>(null)
  const [isAttachmentMenuOpen, setIsAttachmentMenuOpen] = useState(false)
  const attachmentInputRef = useRef<HTMLInputElement>(null)
  const documentInputRef = useRef<HTMLInputElement>(null)
  const [replyingTo, setReplyingTo] = useState<ConversationMessage | null>(null)
  const [paymentAmount, setPaymentAmount] = useState('')
  const [paymentNotes, setPaymentNotes] = useState('')
  const [paymentRejectReason, setPaymentRejectReason] = useState('')
  const [localError, setLocalError] = useState<string | null>(null)
  const [editingCustomer, setEditingCustomer] = useState<CustomerSummary | null>(null)
  const [customerError, setCustomerError] = useState<string | null>(null)
  const [isSavingCustomer, setIsSavingCustomer] = useState(false)
  const [quickReplies, setQuickReplies] = useState<ConversationQuickReply[]>([])
  const [quickReplySearch, setQuickReplySearch] = useState('')
  const [isQuickReplyOpen, setIsQuickReplyOpen] = useState(false)
  const [isConfigurationOpen, setIsConfigurationOpen] = useState(false)
  const [alertSeverityFilter, setAlertSeverityFilter] = useState<AlertSeverityFilter>('all')
  const [toasts, setToasts] = useState<ConversationToast[]>([])
  const [quickReplyPosition, setQuickReplyPosition] = useState<{ top: number; left: number; width: number } | null>(null)
  const [soundEnabled, setSoundEnabled] = useState(() => readBooleanPreference('conversation-sound-enabled'))
  const [browserNotificationsEnabled, setBrowserNotificationsEnabled] = useState(() => (
    readBooleanPreference('conversation-browser-notifications')
      && typeof Notification !== 'undefined'
      && Notification.permission === 'granted'
  ))
  const composerRef = useRef<HTMLTextAreaElement>(null)
  const clientReferenceRef = useRef<string | null>(null)
  const knownNotificationEventsRef = useRef<Set<string>>(new Set())
  const notificationsInitializedRef = useRef(false)
  const contextPanelRef = useRef<HTMLElement>(null)
  const contextTriggerRef = useRef<HTMLElement | null>(null)
  const quickReplyTriggerRef = useRef<HTMLDivElement>(null)
  const quickReplyPickerRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!isAttachmentMenuOpen) return undefined
    const close = (event: MouseEvent) => {
      if (!(event.target instanceof Element) || !event.target.closest('.attachment-picker')) setIsAttachmentMenuOpen(false)
    }
    const escape = (event: KeyboardEvent) => { if (event.key === 'Escape') setIsAttachmentMenuOpen(false) }
    document.addEventListener('mousedown', close)
    document.addEventListener('keydown', escape)
    return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', escape) }
  }, [isAttachmentMenuOpen])

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
        return hasOpenAlerts(conversation)
      }

      if (activeFilter === 'manual') {
        return isManualConversation(conversation)
      }

      if (activeFilter === 'automatic') {
        return isAutomaticConversation(conversation)
      }

      return true
    })
  }, [activeFilter, conversations, search])

  const activeAlerts = (selectedConversation?.alerts ?? []).filter((alert) => isOperationalAlert(alert) && alert.status !== 'resolved')
  const bannerAlert = selectBannerAlert(activeAlerts)
  const filteredActiveAlerts = activeAlerts.filter((alert) => (
    alertSeverityFilter === 'all' || alert.severity === alertSeverityFilter
  ))
  const review = selectedConversation?.paymentReview ?? null
  const defaultPaymentAmount = review?.amountCents ? review.amountCents / 100 : review?.expectedTotal
  const selectedMode = selectedConversation ? modePresentation(selectedConversation) : null
  const selectedIsManual = selectedConversation ? isManualConversation(selectedConversation) : false

  const filterItems: Array<{ key: ConversationFilter; label: string; count: number }> = [
    { key: 'all', label: 'Todas', count: conversations.length },
    { key: 'unread', label: 'Não lidas', count: conversations.reduce((sum, item) => sum + item.unread, 0) },
    { key: 'alerts', label: 'Alertas', count: conversations.filter(hasOpenAlerts).length },
    { key: 'manual', label: 'Manual', count: conversations.filter(isManualConversation).length },
    { key: 'automatic', label: 'Automático', count: conversations.filter(isAutomaticConversation).length },
  ]

  const addToast = useCallback((message: string, tone: ConversationToastTone, deduplicationKey: string) => {
    setToasts((current) => {
      if (current.some((toast) => toast.deduplicationKey === deduplicationKey)) {
        return current
      }

      return [...current, {
        id: createClientReference(),
        deduplicationKey,
        message,
        tone,
      }].slice(-4)
    })
  }, [])

  const dismissToast = useCallback((toastId: string) => {
    setToasts((current) => current.filter((toast) => toast.id !== toastId))
  }, [])

  useEffect(() => {
    let active = true

    void getConversationQuickReplies()
      .then((replies) => {
        if (active) {
          setQuickReplies(replies.filter((reply) => reply.isActive))
        }
      })
      .catch(() => {
        if (active) {
          setQuickReplies([])
        }
      })

    return () => {
      active = false
    }
  }, [])

  useEffect(() => {
    if (toasts.length === 0) {
      return undefined
    }

    const timeout = window.setTimeout(() => {
      setToasts((current) => current.slice(1))
    }, 5200)

    return () => window.clearTimeout(timeout)
  }, [toasts])

  useEffect(() => {
    const nextEvents = collectNotificationEvents(conversations)
    const knownEvents = knownNotificationEventsRef.current

    if (!notificationsInitializedRef.current) {
      nextEvents.forEach((event) => knownEvents.add(event.key))
      notificationsInitializedRef.current = true
      return
    }

    nextEvents.forEach((event) => {
      if (knownEvents.has(event.key)) {
        return
      }

      knownEvents.add(event.key)
      addToast(event.message, event.tone, event.key)

      const conversationIsOpen = document.visibilityState === 'visible'
        && selectedConversation?.id === event.conversationId

      if (browserNotificationsEnabled && !conversationIsOpen && typeof Notification !== 'undefined') {
        new Notification('Conversas WhatsApp', { body: event.message, tag: event.key })
      }

      if (soundEnabled && !conversationIsOpen) {
        playNotificationSound()
      }
    })
  }, [addToast, browserNotificationsEnabled, conversations, selectedConversation?.id, soundEnabled])

  useEffect(() => {
    if (!isContextOpen) {
      return undefined
    }

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setIsContextOpen(false)
        setActivePanel((current) => (current === 'details' ? 'chat' : current))
      }
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [isContextOpen])

  useLayoutEffect(() => {
    if (!isQuickReplyOpen) {
      return undefined
    }

    function positionQuickReplyPicker() {
      const trigger = quickReplyTriggerRef.current
      const picker = quickReplyPickerRef.current

      if (!trigger || !picker) {
        return
      }

      const triggerRect = trigger.getBoundingClientRect()
      const pickerRect = picker.getBoundingClientRect()
      const viewportPadding = 12
      const preferredLeft = triggerRect.left
      const left = Math.min(
        Math.max(viewportPadding, preferredLeft),
        Math.max(viewportPadding, window.innerWidth - pickerRect.width - viewportPadding),
      )
      const spaceAbove = triggerRect.top - viewportPadding
      const spaceBelow = window.innerHeight - triggerRect.bottom - viewportPadding
      const opensAbove = spaceAbove >= pickerRect.height || spaceAbove >= spaceBelow
      const preferredTop = opensAbove
        ? triggerRect.top - pickerRect.height - 8
        : triggerRect.bottom + 8
      const top = Math.min(
        Math.max(viewportPadding, preferredTop),
        Math.max(viewportPadding, window.innerHeight - pickerRect.height - viewportPadding),
      )

      setQuickReplyPosition({ top, left, width: Math.min(430, window.innerWidth - viewportPadding * 2) })
    }

    const frame = window.requestAnimationFrame(positionQuickReplyPicker)
    window.addEventListener('resize', positionQuickReplyPicker)
    window.addEventListener('scroll', positionQuickReplyPicker, true)

    return () => {
      window.cancelAnimationFrame(frame)
      window.removeEventListener('resize', positionQuickReplyPicker)
      window.removeEventListener('scroll', positionQuickReplyPicker, true)
    }
  }, [isQuickReplyOpen, quickReplies, quickReplySearch])

  useEffect(() => {
    if (!isQuickReplyOpen) {
      return undefined
    }

    function handleQuickReplyDismiss(event: MouseEvent) {
      const target = event.target
      if (!(target instanceof Node)) {
        return
      }

      if (!quickReplyTriggerRef.current?.contains(target) && !quickReplyPickerRef.current?.contains(target)) {
        setIsQuickReplyOpen(false)
      }
    }

    function handleQuickReplyKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        event.stopPropagation()
        setIsQuickReplyOpen(false)
        quickReplyTriggerRef.current?.querySelector('button')?.focus()
      }
    }

    document.addEventListener('mousedown', handleQuickReplyDismiss)
    document.addEventListener('keydown', handleQuickReplyKeyDown)

    return () => {
      document.removeEventListener('mousedown', handleQuickReplyDismiss)
      document.removeEventListener('keydown', handleQuickReplyKeyDown)
    }
  }, [isQuickReplyOpen])

  useLayoutEffect(() => {
    const textarea = composerRef.current
    if (!textarea) {
      return
    }

    const minHeight = 42
    const maxHeight = 96
    textarea.style.height = 'auto'
    const nextHeight = Math.min(Math.max(textarea.scrollHeight, minHeight), maxHeight)
    textarea.style.height = `${nextHeight}px`
    textarea.style.overflowY = textarea.scrollHeight > maxHeight ? 'auto' : 'hidden'
  }, [composerBody])

  useEffect(() => {
    if (!isContextOpen) {
      return
    }

    window.requestAnimationFrame(() => contextPanelRef.current?.focus())
  }, [isContextOpen])

  function handleSelectConversation(conversationId: string) {
    onSelectConversation(conversationId)
    setActivePanel('chat')
    setLocalError(null)
    setReplyingTo(null)
    setAttachment(null)
    setIsAttachmentMenuOpen(false)
  }

  function handleOpenContext(event?: { currentTarget?: EventTarget | null }) {
    if (event?.currentTarget instanceof HTMLElement) {
      contextTriggerRef.current = event.currentTarget
    }
    setActivePanel('details')
    setIsContextOpen(true)
  }

  function handleCloseContext() {
    setIsContextOpen(false)
    setActivePanel((current) => (current === 'details' ? 'chat' : current))
    window.requestAnimationFrame(() => contextTriggerRef.current?.focus())
  }

  async function handleSubmitMessage(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setLocalError(null)

    if (!selectedConversation) {
      setLocalError('Selecione uma conversa antes de responder.')
      return
    }

    const body = composerBody.trim()
    if (!body && !attachment) {
      setLocalError('Digite uma mensagem para enviar.')
      return
    }

    try {
      const clientReference = clientReferenceRef.current ?? createClientReference()
      clientReferenceRef.current = clientReference
      if (attachment) {
        await onSendMedia(selectedConversation.id, attachment.file, attachment.type, body)
        setAttachment(null)
      } else {
        await onSendMessage(selectedConversation.id, body, clientReference, replyingTo?.id)
      }
      clientReferenceRef.current = null
      setComposerBody('')
      setReplyingTo(null)
      setIsQuickReplyOpen(false)
    } catch (sendError) {
      setLocalError(sendError instanceof Error
        ? sendError.message
        : 'A mensagem não foi enviada. O texto foi mantido para nova tentativa.')
    }
  }

  async function handleRetryMessage(messageId: string) {
    if (!selectedConversation) {
      return
    }

    setLocalError(null)

    try {
      await onRetryMessage(selectedConversation.id, messageId)
    } catch {
      setLocalError('Não foi possível reenviar a mensagem agora.')
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

  function handleReturnToAutomatic() {
    if (!selectedConversation) {
      return
    }

    const confirmed = window.confirm('Devolver esta conversa para o atendimento automático?')
    if (confirmed) {
      void onChangeMode(selectedConversation.id, 'assisted')
    }
  }

  async function handleSaveCustomer(payload: UpdateCustomerPayload) {
    if (!editingCustomer) {
      return
    }

    setCustomerError(null)
    setIsSavingCustomer(true)

    try {
      await onUpdateCustomer(editingCustomer.id, payload)
      setEditingCustomer(null)
    } catch (error) {
      setCustomerError(error instanceof Error ? error.message : 'Não foi possível atualizar o cliente.')
    } finally {
      setIsSavingCustomer(false)
    }
  }

  function handleComposerChange(value: string) {
    if (value !== composerBody) {
      clientReferenceRef.current = null
    }

    setComposerBody(value)
    setLocalError(null)
  }

  function handleInsertQuickReply(reply: ConversationQuickReply) {
    const separator = composerBody.trim() === '' ? '' : '\n'
    handleComposerChange(`${composerBody}${separator}${reply.body}`)
    setIsQuickReplyOpen(false)
    setQuickReplySearch('')
    window.requestAnimationFrame(() => composerRef.current?.focus())
  }

  function handleReplyToMessage(message: ConversationMessage) {
    setReplyingTo(message)
    setLocalError(null)
    window.requestAnimationFrame(() => composerRef.current?.focus())
  }

  async function handleToggleBrowserNotifications() {
    if (typeof Notification === 'undefined') {
      addToast('Este navegador não oferece notificações do sistema.', 'warning', 'notification-api-unavailable')
      return
    }

    if (browserNotificationsEnabled) {
      setBrowserNotificationsEnabled(false)
      writeBooleanPreference('conversation-browser-notifications', false)
      addToast('Notificações do navegador desativadas.', 'info', `browser-notifications-off:${Date.now()}`)
      return
    }

    const permission = await Notification.requestPermission()
    const enabled = permission === 'granted'
    setBrowserNotificationsEnabled(enabled)
    writeBooleanPreference('conversation-browser-notifications', enabled)
    addToast(
      enabled ? 'Notificações do navegador ativadas.' : 'Permissão de notificação não concedida.',
      enabled ? 'success' : 'warning',
      `browser-notifications:${permission}`,
    )
  }

  function handleToggleSound() {
    const enabled = !soundEnabled
    setSoundEnabled(enabled)
    writeBooleanPreference('conversation-sound-enabled', enabled)
    if (enabled) {
      playNotificationSound()
    }
    addToast(
      enabled ? 'Som de novas conversas ativado.' : 'Som de novas conversas desativado.',
      'info',
      `conversation-sound:${enabled}:${Date.now()}`,
    )
  }

  const visibleQuickReplies = quickReplies.filter((reply) => {
    const needle = normalize(quickReplySearch)
    return needle === ''
      || normalize(reply.title).includes(needle)
      || normalize(reply.shortcut).includes(needle)
      || normalize(reply.body).includes(needle)
  })

  return (
    <PageContainer density="wide">
      <div className="conversation-page">
        <PageHeader
          actions={
            <div className="conversation-header-actions">
              <Button icon="orders" onClick={onOpenOrders} variant="secondary">
                Abrir pedidos
              </Button>
              <IconButton
                className={browserNotificationsEnabled ? 'is-active' : ''}
                icon="bell"
                label={browserNotificationsEnabled ? 'Desativar notificações' : 'Ativar notificações'}
                onClick={() => void handleToggleBrowserNotifications()}
                variant="ghost"
              />
              <IconButton
                className={soundEnabled ? 'is-active' : ''}
                icon={soundEnabled ? 'sound' : 'sound-off'}
                label={soundEnabled ? 'Desativar som' : 'Ativar som'}
                onClick={handleToggleSound}
                variant="ghost"
              />
              <IconButton
                icon="settings"
                label="Configurar atendimento"
                onClick={() => setIsConfigurationOpen(true)}
                variant="ghost"
              />
            </div>
          }
          description="Atendimento automático e manual em um só lugar."
          title="Conversas WhatsApp"
        />

      {error ? (
        <div className="conversation-error" role="alert">
          {error}
        </div>
      ) : null}

      <div className="conversation-mobile-nav" aria-label="Áreas de conversas">
        <button className={activePanel === 'list' ? 'is-active' : ''} onClick={() => setActivePanel('list')} type="button">
          Conversas
        </button>
        <button className={activePanel === 'chat' ? 'is-active' : ''} disabled={!selectedConversation} onClick={() => setActivePanel('chat')} type="button">
          Chat
        </button>
        <button className={activePanel === 'details' ? 'is-active' : ''} disabled={!selectedConversation} onClick={handleOpenContext} type="button">
          Detalhes
        </button>
      </div>

      <div
        aria-busy={isLoading}
        className={`conversation-workspace conversation-workspace--${activePanel} ${isContextOpen ? 'has-context-open' : ''}`}
      >
          <Card className="conversation-list-panel">
            <div className="conversation-list-panel__header">
              <div>
              <h2>Conversas</h2>
              </div>
            {alerts.length > 0 ? <Badge tone="danger" size="sm">{`${alerts.length} alertas`}</Badge> : null}
          </div>

          <div className="conversation-search">
            <label htmlFor="conversation-search">Buscar conversa</label>
            <div className="conversation-search__field">
              <Icon name="search" size={16} />
              <input
                id="conversation-search"
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Buscar por nome ou telefone"
                type="search"
                value={search}
              />
            </div>
          </div>

          <div className="conversation-filter-grid" role="tablist" aria-label="Filtros de conversa">
            {filterItems.map((filter) => (
              <button
                aria-selected={activeFilter === filter.key}
                className={activeFilter === filter.key ? 'conversation-filter is-active' : 'conversation-filter'}
                key={filter.key}
                onClick={() => setActiveFilter(filter.key)}
                role="tab"
                type="button"
              >
                <span>{filter.label}</span>
                <strong>{filter.count}</strong>
              </button>
            ))}
          </div>

          <div className="conversation-list" role="list">
            {filteredConversations.map((conversation) => {
              const presentation = modePresentation(conversation)
              const isActive = selectedConversation?.id === conversation.id
              const openAlerts = openAlertCount(conversation)
              const hasAttention = openAlerts > 0 || presentation.label === 'Atenção'

              return (
                <button
                  className={isActive ? 'conversation-item is-active' : 'conversation-item'}
                  key={conversation.id}
                  onClick={() => handleSelectConversation(conversation.id)}
                  role="listitem"
                  title={`${conversation.customer.name} - ${conversation.customer.phoneLabel}`}
                  type="button"
                >
                  <span className="avatar">{initialsFromName(conversation.customer.name)}</span>
                    <span className="conversation-item__content">
                      <span className="conversation-item__top">
                      <span className="conversation-item__identity">
                        <strong>{conversation.customer.name}</strong>
                        {hasAttention ? <Badge tone="danger" size="sm">Atenção</Badge> : null}
                      </span>
                      <time>{formatConversationTime(conversation.lastMessageAt)}</time>
                    </span>
                    <small>{conversation.customer.phoneLabel || 'Sem telefone cadastrado'}</small>
                    <span className="conversation-item__preview">{conversation.lastMessage}</span>
                    <span className="conversation-item__badges">
                      {conversation.unread > 0 ? <Badge tone="brand" size="sm">{`${conversation.unread} não lida${conversation.unread > 1 ? 's' : ''}`}</Badge> : null}
                      {presentation.label !== 'Atenção' ? <Badge tone={presentation.tone} size="sm">{presentation.label}</Badge> : null}
                    </span>
                  </span>
                </button>
              )
            })}
          </div>
          {filteredConversations.length === 0 ? (
            <EmptyState description="Nenhuma conversa encontrada para este filtro." title="Sem conversas" />
          ) : null}
        </Card>

        <Card className="chat-panel">
          {selectedConversation ? (
            <>
              <div className="chat-panel__header">
                <div className="chat-heading">
                  <span className="avatar">{initialsFromName(selectedConversation.customer.name)}</span>
                  <div className="chat-heading__identity">
                    <div className="chat-heading__title-row">
                      <h2>{selectedConversation.customer.name}</h2>
                      {selectedMode ? <Badge tone={selectedMode.tone}>{selectedMode.label}</Badge> : null}
                    </div>
                    <div className="chat-heading__meta">
                      <span>{selectedConversation.customer.phoneLabel || 'Sem telefone cadastrado'}</span>
                      <span>{selectedConversation.statusLabel}</span>
                      {selectedConversation.assignedUser ? <span>Responsável: {selectedConversation.assignedUser.name}</span> : null}
                    </div>
                  </div>
                </div>

                <div className="conversation-mode-actions">
                  <div className="conversation-mode-segment" aria-label="Modo de atendimento">
                    <button
                      aria-pressed={!selectedIsManual}
                      className={!selectedIsManual ? 'is-active' : ''}
                      disabled={isActionBusy || !selectedIsManual}
                      onClick={handleReturnToAutomatic}
                      type="button"
                    >
                      Automático
                    </button>
                    <button
                      aria-pressed={selectedIsManual}
                      className={selectedIsManual ? 'is-active' : ''}
                      disabled={isActionBusy || selectedIsManual}
                      onClick={() => void onChangeMode(selectedConversation.id, 'manual')}
                      type="button"
                    >
                      Manual
                    </button>
                  </div>
                  {selectedIsManual ? (
                    <Button disabled={isActionBusy} onClick={handleReturnToAutomatic} size="sm" variant="secondary">
                      Devolver para o automático
                    </Button>
                  ) : (
                    <Button
                      disabled={isActionBusy}
                      onClick={() => void onChangeMode(selectedConversation.id, 'manual')}
                      size="sm"
                      variant="secondary"
                    >
                      Assumir atendimento
                    </Button>
                  )}
                  <Button className="conversation-context-toggle" onClick={handleOpenContext} size="sm" variant="secondary">
                    Ver detalhes
                  </Button>
                </div>
              </div>

              {bannerAlert ? (
                <ConversationAlertBanner
                  alert={bannerAlert}
                  onOpenDetails={handleOpenContext}
                />
              ) : null}

              <MessageTimeline
                conversationId={selectedConversation.id}
                messages={selectedConversation.messages}
                onReply={handleReplyToMessage}
                onRetryMessage={handleRetryMessage}
                onTogglePin={(messageId) => onToggleMessagePin(selectedConversation.id, messageId)}
                onCopyMessage={(body) => addToast('Mensagem copiada', 'success', `message-copied:${body}`)}
              />

              <form className="composer" onSubmit={handleSubmitMessage}>
                {replyingTo ? (
                  <div className="composer-reply-preview">
                    <div>
                      <strong>Respondendo a {quotedSenderLabel(replyingTo.sender)}</strong>
                      <span>{quotedMessageLabel(replyingTo)}</span>
                    </div>
                    <IconButton icon="close" label="Cancelar resposta" onClick={() => setReplyingTo(null)} />
                  </div>
                ) : null}
                {!selectedIsManual ? (
                  <p className="conversation-automation-note">
                    A conversa está no automático. Ao enviar uma resposta manual, o atendimento passa para a equipe.
                  </p>
                ) : null}
                <textarea
                  aria-label="Mensagem para cliente"
                  disabled={!selectedConversation || isActionBusy}
                  onChange={(event) => handleComposerChange(event.target.value)}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter' && !event.shiftKey) {
                      event.preventDefault()
                      event.currentTarget.form?.requestSubmit()
                    }
                  }}
                  placeholder="Digite uma mensagem..."
                  rows={1}
                  ref={composerRef}
                  value={composerBody}
                />
                <input
                  accept="image/jpeg,image/png,image/webp"
                  hidden
                  onChange={(event) => {
                    const file = event.target.files?.[0]
                    if (file) {
                      setAttachment({ file, type: 'image' })
                    }
                    event.currentTarget.value = ''
                  }}
                  ref={attachmentInputRef}
                  type="file"
                />
                <input
                  accept=".pdf,.txt,.doc,.docx,application/pdf,text/plain,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                  hidden
                  onChange={(event) => {
                    const file = event.target.files?.[0]
                    if (file) setAttachment({ file, type: 'document' })
                    event.currentTarget.value = ''
                  }}
                  ref={documentInputRef}
                  type="file"
                />
                {attachment ? (
                  <div className="composer-attachment-preview">
                    <span>{attachment.type === 'image' ? 'Imagem' : 'Documento'}: {attachment.file.name}</span>
                    <IconButton icon="close" label="Remover anexo" onClick={() => setAttachment(null)} />
                  </div>
                ) : null}
                <div className="composer__footer">
                  <div className="composer__tools">
                    <div className="attachment-picker">
                      <Button aria-expanded={isAttachmentMenuOpen} aria-haspopup="menu" onClick={() => setIsAttachmentMenuOpen((open) => !open)} size="sm" type="button" variant="ghost">
                        Anexar
                      </Button>
                      {isAttachmentMenuOpen ? (
                        <div className="attachment-picker__menu" role="menu">
                          <button onClick={() => { setIsAttachmentMenuOpen(false); attachmentInputRef.current?.click() }} role="menuitem" type="button">Imagem</button>
                          <button onClick={() => { setIsAttachmentMenuOpen(false); documentInputRef.current?.click() }} role="menuitem" type="button">Documento</button>
                        </div>
                      ) : null}
                    </div>
                    <div className="quick-reply-trigger" ref={quickReplyTriggerRef}>
                    <Button
                      aria-expanded={isQuickReplyOpen}
                      aria-haspopup="dialog"
                      icon="spark"
                      onClick={() => setIsQuickReplyOpen((current) => !current)}
                      size="sm"
                      variant="ghost"
                    >
                      Respostas rápidas
                    </Button>
                    </div>
                    <span>Enter envia · Shift+Enter quebra linha</span>
                  </div>
                  <Button disabled={isActionBusy || (composerBody.trim() === '' && !attachment)} icon="arrow" type="submit" variant="primary">
                    {isActionBusy ? 'Enviando' : 'Enviar'}
                  </Button>
                </div>
                {isQuickReplyOpen && typeof document !== 'undefined' ? createPortal(
                  <div
                    className="quick-reply-picker quick-reply-picker--portal"
                    ref={quickReplyPickerRef}
                    role="dialog"
                    aria-label="Respostas rápidas"
                    style={quickReplyPosition ? {
                      left: quickReplyPosition.left,
                      top: quickReplyPosition.top,
                      width: quickReplyPosition.width,
                      visibility: 'visible',
                    } : { visibility: 'hidden' }}
                  >
                    <div className="quick-reply-picker__header">
                      <strong>Respostas rápidas</strong>
                      <IconButton icon="close" label="Fechar respostas rápidas" onClick={() => setIsQuickReplyOpen(false)} />
                    </div>
                    <div className="quick-reply-picker__search">
                      <Icon name="search" size={16} />
                      <input
                        autoFocus
                        onChange={(event) => setQuickReplySearch(event.target.value)}
                        placeholder="Buscar resposta"
                        type="search"
                        value={quickReplySearch}
                      />
                    </div>
                    <div className="quick-reply-picker__list">
                      {visibleQuickReplies.map((reply) => (
                        <button key={reply.id} onClick={() => handleInsertQuickReply(reply)} type="button">
                          <span>
                            <strong>{reply.title}</strong>
                            <small>/{reply.shortcut} · {quickReplyCategoryLabel(reply.category)}</small>
                          </span>
                          <p>{reply.body}</p>
                        </button>
                      ))}
                      {visibleQuickReplies.length === 0 ? (
                        <p className="muted-text">Nenhuma resposta rápida encontrada.</p>
                      ) : null}
                    </div>
                    <Button
                      onClick={() => {
                        setIsQuickReplyOpen(false)
                        setIsConfigurationOpen(true)
                      }}
                      size="sm"
                      variant="secondary"
                    >
                      Gerenciar respostas
                    </Button>
                  </div>, document.body) : null}
                {localError ? (
                  <div className="composer__error" role="alert">
                    {localError}
                  </div>
                ) : null}
              </form>
            </>
          ) : (
            <EmptyState description="Selecione uma conversa para iniciar o atendimento." title="Nenhuma conversa selecionada" />
          )}
        </Card>

        {isContextOpen ? (
          <button
            aria-label="Fechar detalhes da conversa"
            className="conversation-context-backdrop"
            onClick={handleCloseContext}
            type="button"
          />
        ) : null}

        <aside
          aria-label="Detalhes da conversa"
          aria-modal={isContextOpen ? true : undefined}
          className="card card--default conversation-context-panel"
          ref={contextPanelRef}
          role={isContextOpen ? 'dialog' : undefined}
          tabIndex={-1}
        >
          <div className="conversation-context-panel__header">
            <SectionTitle title="Detalhes" />
            <IconButton className="conversation-context-close" icon="close" label="Fechar detalhes" onClick={handleCloseContext} />
          </div>
          <div className="conversation-context-panel__body">
            {selectedConversation ? (
              <>
              <div className="conversation-context-block conversation-customer-card">
                <span className="avatar">{initialsFromName(selectedConversation.customer.name)}</span>
                <div>
                  <strong>{selectedConversation.customer.name}</strong>
                  <span>{selectedConversation.customer.phoneLabel || 'Sem telefone cadastrado'}</span>
                  <small>Origem: {sourceLabel(selectedConversation.customer.sourceChannel)}</small>
                </div>
                <Button
                  onClick={() => {
                    setCustomerError(null)
                    setEditingCustomer(selectedConversation.customer)
                  }}
                  size="sm"
                  variant="secondary"
                >
                  Editar cliente
                </Button>
              </div>

              <div className="conversation-context-block">
                <h3>Pedido ativo</h3>
                {linkedOrder ? (
                  <div className="current-order">
                    <div className="current-order__header">
                      <div>
                        <strong>{linkedOrder.code}</strong>
                        <span>{linkedOrder.status}</span>
                      </div>
                      <strong>{formatCurrency(linkedOrder.total)}</strong>
                    </div>
                    <div className="current-order__items">
                      {linkedOrder.items.map((item) => (
                        <div key={item.id}>
                          <span>
                            {item.quantity}x {item.name}
                          </span>
                          <small>{item.additions.join(' - ') || item.notes}</small>
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
                      <Button icon="printer" onClick={() => onPreviewTicket(linkedOrder.id)} variant="secondary">
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
                  <h3>Pagamento</h3>
                  <p>Comprovante recebido. A IA não confirma pagamento; Larissa ou Beatriz precisam aprovar.</p>
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
                    Observação da aprovação
                    <textarea onChange={(event) => setPaymentNotes(event.target.value)} rows={2} value={paymentNotes} />
                  </label>
                  <div className="conversation-context-actions">
                    <Button disabled={isActionBusy} onClick={() => void handleApprovePayment()} variant="primary">
                      Aprovar pagamento
                    </Button>
                  </div>
                  <label>
                    Motivo da rejeição
                    <textarea onChange={(event) => setPaymentRejectReason(event.target.value)} rows={2} value={paymentRejectReason} />
                  </label>
                  <Button disabled={isActionBusy} onClick={() => void handleRejectPayment()} variant="secondary">
                    Rejeitar comprovante
                  </Button>
                </div>
              ) : null}

              <div className="conversation-context-block">
                <h3>Alertas</h3>
                <div className="conversation-alert-filters" aria-label="Filtrar alertas">
                  {(['all', 'critical', 'warning', 'info'] as AlertSeverityFilter[]).map((severity) => (
                    <button
                      aria-pressed={alertSeverityFilter === severity}
                      className={alertSeverityFilter === severity ? 'is-active' : ''}
                      key={severity}
                      onClick={() => setAlertSeverityFilter(severity)}
                      type="button"
                    >
                      {alertSeverityLabel(severity)}
                    </button>
                  ))}
                </div>
                {filteredActiveAlerts.length > 0 ? (
                  <div className="conversation-global-alerts">
                    {filteredActiveAlerts.map((alert) => (
                      <ConversationAlertCard
                        alert={alert}
                        key={alert.id}
                        onAcknowledge={() => onAcknowledgeAlert(selectedConversation.id, alert.id)}
                        onResolve={() => onResolveAlert(selectedConversation.id, alert.id)}
                      />
                    ))}
                  </div>
                ) : (
                  <p className="muted-text">Nenhum alerta aberto nesta conversa.</p>
                )}
              </div>
              </>
            ) : (
              <EmptyState description="Selecione uma conversa para ver cliente, pedido e alertas." title="Sem contexto" />
            )}
          </div>
        </aside>
        </div>
        <Modal
          closeDisabled={isSavingCustomer}
          description="Atualize o cadastro sem sobrescrever o perfil recebido do WhatsApp."
          onClose={() => setEditingCustomer(null)}
          onPrimary={() => document.getElementById(CONVERSATION_CUSTOMER_FORM_ID)?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))}
          open={editingCustomer !== null}
          primaryDisabled={isSavingCustomer}
          primaryLabel={isSavingCustomer ? 'Salvando' : 'Salvar alterações'}
          size="lg"
          title="Editar cliente"
        >
          <CustomerEditor
            customer={editingCustomer}
            disabled={isSavingCustomer}
            error={customerError}
            formId={CONVERSATION_CUSTOMER_FORM_ID}
            key={editingCustomer?.id ?? 'conversation-customer'}
            mode="edit"
            onSubmit={handleSaveCustomer}
          />
        </Modal>
        <ConversationConfigurationModal
          onClose={() => setIsConfigurationOpen(false)}
          onQuickRepliesChanged={(replies) => setQuickReplies(replies.filter((reply) => reply.isActive))}
          open={isConfigurationOpen}
        />
        <div className="conversation-toast-viewport" aria-live="polite" aria-relevant="additions">
          {toasts.map((toast) => (
            <div className={`conversation-toast conversation-toast--${toast.tone}`} key={toast.id} role="status">
              <span>{toast.message}</span>
              <IconButton icon="close" label="Fechar aviso" onClick={() => dismissToast(toast.id)} />
            </div>
          ))}
        </div>
      </div>
    </PageContainer>
  )
}

function ConversationAlertBanner({
  alert,
  onOpenDetails,
}: {
  alert: ConversationAlert
  onOpenDetails: () => void
}) {
  return (
    <div className={`conversation-alert-banner conversation-alert-banner--${alert.severity}`} role="status">
      <Icon name="alert" size={18} />
      <div>
        <strong>{alert.title}</strong>
        <span>{alert.message}</span>
      </div>
      <Button onClick={onOpenDetails} size="sm" variant="ghost">
        Ver detalhes
      </Button>
    </div>
  )
}

function ConversationAlertCard({
  alert,
  onAcknowledge,
  onResolve,
}: {
  alert: ConversationAlert
  onAcknowledge: () => void
  onResolve: () => void
}) {
  return (
    <div className={`conversation-alert conversation-alert--${alert.severity}`}>
      <div className="conversation-alert__content">
        <strong>{alert.title}</strong>
        <span>{alert.message}</span>
      </div>
      <div className="conversation-alert__actions">
        {alert.status === 'open' ? (
          <Button onClick={onAcknowledge} size="sm" variant="secondary">
            Reconhecer
          </Button>
        ) : null}
        <Button onClick={onResolve} size="sm" variant="secondary">
          Resolver
        </Button>
      </div>
    </div>
  )
}

function MessageTimeline({
  conversationId,
  messages,
  onReply,
  onRetryMessage,
  onTogglePin,
  onCopyMessage,
}: {
  conversationId: string
  messages: ConversationMessage[]
  onReply: (message: ConversationMessage) => void
  onRetryMessage: (messageId: string) => void
  onTogglePin: (messageId: string) => Promise<void>
  onCopyMessage: (body: string) => void
}) {
  const listRef = useRef<HTMLDivElement>(null)
  const lastConversationRef = useRef<string | null>(null)
  const [isNearBottom, setIsNearBottom] = useState(true)

  useEffect(() => {
    const node = listRef.current
    if (!node) {
      return
    }

    const conversationChanged = lastConversationRef.current !== conversationId
    if (conversationChanged || isNearBottom) {
      node.scrollTop = node.scrollHeight
    }
    lastConversationRef.current = conversationId
  }, [conversationId, isNearBottom, messages.length])

  let lastDateKey = ''

  return (
    <div
      className="message-list"
      onScroll={(event) => {
        const node = event.currentTarget
        setIsNearBottom(node.scrollHeight - node.scrollTop - node.clientHeight < 96)
      }}
      ref={listRef}
    >
      {messages.length > 0 ? (
        messages.map((message) => {
          const messageTimestamp = message.occurredAt ?? message.createdAt
          const dateKey = messageTimestamp ? new Date(messageTimestamp).toDateString() : message.id
          const shouldShowDate = dateKey !== lastDateKey
          lastDateKey = dateKey

          return (
            <div className="message-list__group" key={message.id}>
              {shouldShowDate ? <div className="message-date-separator">{formatMessageDate(messageTimestamp)}</div> : null}
              <MessageBubble
                boundaryRef={listRef}
                conversationId={conversationId}
                message={message}
                onReply={() => onReply(message)}
                onRetry={() => onRetryMessage(message.id)}
                onTogglePin={() => onTogglePin(message.id)}
                onCopyMessage={onCopyMessage}
              />
            </div>
          )
        })
      ) : (
        <EmptyState description="Histórico ainda vazio para esta conversa." title="Sem mensagens" />
      )}
    </div>
  )
}

function MessageBubble({ boundaryRef, conversationId, message, onReply, onRetry, onTogglePin, onCopyMessage }: {
  boundaryRef: RefObject<HTMLDivElement | null>
  conversationId: string
  message: ConversationMessage
  onReply: () => void
  onRetry: () => void
  onTogglePin: () => Promise<void>
  onCopyMessage: (body: string) => void
}) {
  const failed = message.status === 'failed'
  const [isMenuOpen, setIsMenuOpen] = useState(false)
  const [isInfoOpen, setIsInfoOpen] = useState(false)
  const [menuPosition, setMenuPosition] = useState<{ top: number; left: number } | null>(null)
  const [selectedReaction, setSelectedReaction] = useState<string | null>(null)
  const [showMoreReactions, setShowMoreReactions] = useState(false)
  const bubbleRef = useRef<HTMLDivElement>(null)
  const menuTriggerRef = useRef<HTMLButtonElement>(null)
  const overlayRef = useRef<HTMLDivElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const hasText = message.body.trim() !== ''
  const downloadableMedia = message.media?.filter((media) => Boolean(media.url)) ?? []

  const positionContextMenu = useCallback(() => {
    if (!isMenuOpen || !menuTriggerRef.current || !bubbleRef.current) {
      return undefined
    }

    const boundary = boundaryRef.current?.getBoundingClientRect()
    const bubble = bubbleRef.current.getBoundingClientRect()
    const viewport = {
      top: boundary?.top ?? 0,
      right: boundary?.right ?? window.innerWidth,
      bottom: boundary?.bottom ?? window.innerHeight,
      left: boundary?.left ?? 0,
    }
    const padding = 10
    const menuWidth = 190
    const menuHeight = downloadableMedia.length > 0 ? 230 : 190
    const reactionHeight = showMoreReactions ? 82 : 42
    const totalHeight = reactionHeight + 6 + menuHeight
    const spaceRight = viewport.right - bubble.right
    const spaceLeft = bubble.left - viewport.left
    const left = spaceRight >= menuWidth + padding
      ? bubble.right + 8
      : spaceLeft >= menuWidth + padding
        ? bubble.left - menuWidth - 8
        : Math.min(
          Math.max(viewport.left + padding, bubble.right - menuWidth),
          viewport.right - menuWidth - padding,
        )
    const top = viewport.bottom - bubble.bottom >= totalHeight + padding
      ? bubble.bottom + 8
      : viewport.top + bubble.top >= totalHeight + padding
        ? bubble.top - totalHeight - 8
        : Math.min(
          Math.max(viewport.top + padding, bubble.bottom - totalHeight),
          viewport.bottom - totalHeight - padding,
        )

    setMenuPosition({ top, left })
  }, [boundaryRef, downloadableMedia.length, isMenuOpen, showMoreReactions])

  useLayoutEffect(() => {
    if (!isMenuOpen) {
      return undefined
    }

    positionContextMenu()
    const boundaryElement = boundaryRef.current
    window.addEventListener('resize', positionContextMenu)
    boundaryElement?.addEventListener('scroll', positionContextMenu)

    return () => {
      window.removeEventListener('resize', positionContextMenu)
      boundaryElement?.removeEventListener('scroll', positionContextMenu)
    }
  }, [boundaryRef, isMenuOpen, positionContextMenu])

  useEffect(() => {
    if (!isMenuOpen) {
      return undefined
    }

    window.requestAnimationFrame(() => menuRef.current?.querySelector<HTMLButtonElement | HTMLAnchorElement>('[role="menuitem"]')?.focus())

    function dismiss(event: MouseEvent) {
      if (event.target instanceof Node && !overlayRef.current?.contains(event.target) && !menuTriggerRef.current?.contains(event.target)) {
        setIsMenuOpen(false)
      }
    }

    function escape(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        event.stopPropagation()
        setIsMenuOpen(false)
        menuTriggerRef.current?.focus()
      }
    }

    document.addEventListener('mousedown', dismiss)
    document.addEventListener('keydown', escape)
    return () => {
      document.removeEventListener('mousedown', dismiss)
      document.removeEventListener('keydown', escape)
    }
  }, [isMenuOpen])

  async function copyMessage() {
    if (!hasText) {
      return
    }

    try {
      await navigator.clipboard.writeText(message.body)
    } catch {
      const textarea = document.createElement('textarea')
      textarea.value = message.body
      textarea.style.position = 'fixed'
      textarea.style.opacity = '0'
      document.body.appendChild(textarea)
      textarea.select()
      document.execCommand('copy')
      textarea.remove()
    }

    onCopyMessage(message.body)
    setIsMenuOpen(false)
  }

  function downloadUrl(mediaId: string): string {
    return `/api/app/conversations/${conversationId}/media/${mediaId}/download`
  }

  return (
    <>
    <div
      className={`message-bubble message-bubble--${message.sender} ${failed ? 'is-failed' : ''}`}
      ref={bubbleRef}
      onContextMenu={(event) => {
        event.preventDefault()
        setIsMenuOpen(true)
      }}
    >
      <button
        aria-expanded={isMenuOpen}
        aria-label={`Ações da mensagem de ${senderLabel(message.sender)}`}
        className="message-bubble__menu-trigger"
        onClick={() => setIsMenuOpen((current) => !current)}
        ref={menuTriggerRef}
        type="button"
      >
        ⌄
      </button>
      <div className="message-bubble__meta">
        <strong>{senderLabel(message.sender)}</strong>
        <time>{message.timeLabel}</time>
      </div>
      {message.replyTo ? (
        <div className="message-bubble__quote">
          <strong>{quotedSenderLabel(message.replyTo.sender)}</strong>
          <span>{quotedMessageLabel(message.replyTo)}</span>
        </div>
      ) : null}
      {message.body ? <p>{message.body}</p> : <p className="muted-text">{messageTypeLabel(message.type)}</p>}
      {message.media && message.media.length > 0 ? (
        <div className="message-media-list">
          {message.media.map((media) => (
            <MediaPreview key={media.id} media={media} />
          ))}
        </div>
      ) : null}
      {message.direction === 'outbound' && message.status ? <small className="message-bubble__status">{messageStatusLabel(message.status)}</small> : null}
      {failed ? (
        <div className="message-bubble__error">
          <strong>Não enviada</strong>
          <span>{messageFailureLabel(message.errorCode, message.errorMessage)}</span>
          <Button onClick={onRetry} size="sm" variant="secondary">
            Tentar novamente
          </Button>
        </div>
      ) : null}
      {message.isPinned ? <span className="message-bubble__pin" title="Mensagem fixada">📌</span> : null}
    </div>
    {isMenuOpen && menuPosition ? createPortal(
      <div
        aria-label="Ações da mensagem"
        className="message-context-overlay"
        ref={overlayRef}
        style={{ top: menuPosition.top, left: menuPosition.left }}
      >
        <div className="message-reaction-bar" aria-label="Reações rápidas">
          {['👍', '❤️', '😂', '😮', '😢', '🙏'].map((reaction) => (
            <button
              aria-label={`Reagir com ${reaction}`}
              className={selectedReaction === reaction ? 'is-selected' : ''}
              key={reaction}
              onClick={() => setSelectedReaction(reaction)}
              type="button"
            >
              {reaction}
            </button>
          ))}
          <button
            aria-expanded={showMoreReactions}
            aria-label="Mais reações"
            className={showMoreReactions ? 'is-selected' : ''}
            onClick={() => setShowMoreReactions((current) => !current)}
            type="button"
          >
            +
          </button>
          {showMoreReactions ? (
            <div className="message-reaction-bar__more">
              {['🎉', '👏', '🔥', '✅', '💯'].map((reaction) => (
                <button
                  aria-label={`Reagir com ${reaction}`}
                  className={selectedReaction === reaction ? 'is-selected' : ''}
                  key={reaction}
                  onClick={() => setSelectedReaction(reaction)}
                  type="button"
                >
                  {reaction}
                </button>
              ))}
            </div>
          ) : null}
        </div>
        <div className="message-actions-menu" ref={menuRef} role="menu">
          <button onClick={() => { setIsMenuOpen(false); onReply() }} role="menuitem" type="button">↩ Responder</button>
          {hasText ? <button onClick={() => void copyMessage()} role="menuitem" type="button">▣ Copiar texto</button> : null}
          <button onClick={() => { void onTogglePin(); setIsMenuOpen(false) }} role="menuitem" type="button">📌 {message.isPinned ? 'Desafixar' : 'Fixar no CRM'}</button>
          <button onClick={() => { setIsInfoOpen(true); setIsMenuOpen(false) }} role="menuitem" type="button">ⓘ Dados da mensagem</button>
          {downloadableMedia.map((media) => (
            <a download href={downloadUrl(media.id)} key={media.id} role="menuitem">↓ Baixar {media.name}</a>
          ))}
        </div>
      </div>,
      document.body,
    ) : null}
    <Modal open={isInfoOpen} onClose={() => setIsInfoOpen(false)} title="Informações da mensagem" size="md">
      <dl className="message-info-list">
        <dt>Enviada por</dt><dd>{senderLabel(message.sender)}</dd>
        <dt>Direção</dt><dd>{message.direction === 'outbound' ? 'Enviada' : 'Recebida'}</dd>
        {message.status ? <><dt>Status</dt><dd>{messageStatusLabel(message.status)}</dd></> : null}
        {message.receivedAt ? <><dt>Recebida</dt><dd>{formatMessageTimestamp(message.receivedAt)}</dd></> : null}
        {message.sentAt ? <><dt>Enviada</dt><dd>{formatMessageTimestamp(message.sentAt)}</dd></> : null}
        {message.deliveredAt ? <><dt>Entregue</dt><dd>{formatMessageTimestamp(message.deliveredAt)}</dd></> : null}
        {message.readAt ? <><dt>{message.direction === 'inbound' ? 'Lida pela equipe' : 'Lida'}</dt><dd>{formatMessageTimestamp(message.readAt)}</dd></> : null}
        {message.failedAt ? <><dt>Falhou</dt><dd>{formatMessageTimestamp(message.failedAt)}</dd></> : null}
      </dl>
    </Modal>
    </>
  )
}

function MediaPreview({ media }: { media: NonNullable<ConversationMessage['media']>[number] }) {
  const isImage = media.mimeType?.startsWith('image/') ?? false
  const isAudio = media.mimeType?.startsWith('audio/') ?? false

  if (isImage && media.url) {
    return (
      <a className="message-media message-media--image" href={media.url} rel="noreferrer" target="_blank">
        <img alt={media.name} src={media.url} />
        <span>{media.name}</span>
      </a>
    )
  }

  if (isAudio && media.url) {
    return (
      <div className="message-media message-media--audio">
        <span>{media.name}</span>
        <audio controls src={media.url}>
          Seu navegador não suporta áudio.
        </audio>
      </div>
    )
  }

  if (media.url) {
    return (
      <a className="message-media message-media--file" href={media.url} rel="noreferrer" target="_blank">
        <Icon name="mail" size={16} />
        <span>{mediaLabel(media)}</span>
      </a>
    )
  }

  return (
    <div className="message-media message-media--file">
      <Icon name="lock" size={16} />
      <span>{mediaLabel(media)}</span>
    </div>
  )
}

function isManualConversation(conversation: Conversation): boolean {
  return conversation.automationMode === 'manual' || conversation.mode === 'manual' || conversation.mode === 'atencao'
}

function isAutomaticConversation(conversation: Conversation): boolean {
  return !isManualConversation(conversation)
}

function hasOpenAlerts(conversation: Conversation): boolean {
  return openAlertCount(conversation) > 0
}

function openAlertCount(conversation: Conversation): number {
  return (conversation.alerts ?? []).filter((alert) => isOperationalAlert(alert) && alert.status !== 'resolved').length
}

function isOperationalAlert(alert: ConversationAlert): boolean {
  return alert.type !== 'unread_message'
}

function modePresentation(conversation: Conversation): { label: string; tone: BadgeTone } {
  if (conversation.mode === 'atencao') {
    return { label: 'Atenção', tone: 'danger' }
  }

  if (isManualConversation(conversation)) {
    return { label: 'Manual', tone: 'manual' }
  }

  return { label: 'Automático', tone: 'success' }
}

function senderLabel(sender: ConversationMessage['sender']): string {
  if (sender === 'ai') {
    return 'Automático'
  }

  if (sender === 'attendant') {
    return 'Atendente'
  }

  return 'Cliente'
}

function messageStatusLabel(status: string): string {
  const labels: Record<string, string> = {
    delivered: 'Entregue',
    failed: 'Não enviada',
    pending: 'Enviando',
    processing: 'Processando',
    queued: 'Enviando',
    read: 'Lida',
    received: 'Recebida',
    sent: 'Enviada',
  }

  return labels[status] ?? 'Atualizando'
}

function messageFailureLabel(errorCode?: string | null, fallback?: string | null): string {
  const labels: Record<string, string> = {
    whatsapp_configuration_missing: 'A integração do WhatsApp precisa ser configurada antes do envio.',
    whatsapp_customer_window_closed: 'A janela de atendimento está fechada. Use um modelo aprovado pela Meta.',
    whatsapp_network_failure: 'Não foi possível alcançar a Meta. Verifique a conexão e tente novamente.',
    whatsapp_phone_number_mismatch: 'O número de envio configurado não corresponde à conta da Meta.',
    whatsapp_provider_rejected: 'A Meta recusou esta mensagem. Confira o destinatário e tente novamente.',
    whatsapp_recipient_not_allowed: 'O número destinatário não está autorizado no ambiente de teste.',
    whatsapp_template_required: 'Esta conversa exige um modelo de mensagem aprovado pela Meta.',
    whatsapp_token_expired: 'A credencial da Meta expirou e precisa ser renovada.',
    whatsapp_token_invalid: 'A credencial da Meta não foi aceita.',
  }

  return (errorCode && labels[errorCode]) || fallback || 'Não foi possível enviar esta mensagem.'
}

function messageTypeLabel(type: ConversationMessage['type']): string {
  const labels: Record<string, string> = {
    audio: 'Áudio recebido',
    document: 'Documento recebido',
    image: 'Imagem recebida',
    interactive: 'Resposta interativa recebida',
    location: 'Localização recebida',
    unsupported: 'Mensagem recebida em formato não suportado',
  }

  return labels[type ?? 'text'] ?? 'Mensagem recebida'
}

function mediaLabel(media: NonNullable<ConversationMessage['media']>[number]): string {
  const size = media.sizeBytes ? ` - ${formatFileSize(media.sizeBytes)}` : ''
  return `${media.name}${size}`
}

function formatFileSize(sizeBytes: number): string {
  if (sizeBytes < 1024) {
    return `${sizeBytes} B`
  }

  if (sizeBytes < 1024 * 1024) {
    return `${Math.round(sizeBytes / 1024)} KB`
  }

  return `${(sizeBytes / (1024 * 1024)).toFixed(1)} MB`
}

function formatConversationTime(value?: string | null): string {
  if (!value) {
    return ''
  }

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) {
    return ''
  }

  return date.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
}

function formatMessageDate(value?: string | null): string {
  if (!value) {
    return 'Data não informada'
  }

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) {
    return 'Data não informada'
  }

  const today = new Date()
  const yesterday = new Date()
  yesterday.setDate(today.getDate() - 1)

  if (date.toDateString() === today.toDateString()) {
    return 'Hoje'
  }

  if (date.toDateString() === yesterday.toDateString()) {
    return 'Ontem'
  }

  return date.toLocaleDateString('pt-BR', {
    day: '2-digit',
    month: 'short',
    weekday: 'long',
  })
}

function quotedSenderLabel(sender: ConversationMessage['sender']): string {
  return sender === 'customer' ? 'Cliente' : sender === 'ai' ? 'Assistente' : 'Você'
}

function quotedMessageLabel(message: Pick<ConversationMessage, 'body' | 'type'>): string {
  if (message.body.trim() !== '') {
    return message.body
  }

  return messageTypeLabel(message.type)
}

function formatMessageTimestamp(value: string): string {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) {
    return '—'
  }

  return date.toLocaleString('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
  })
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

function sourceLabel(source?: string | null): string {
  if (source === 'whatsapp') {
    return 'WhatsApp'
  }

  if (source === 'manual') {
    return 'Manual'
  }

  if (source === 'demo') {
    return 'Demonstração'
  }

  return 'Cadastro'
}

function selectBannerAlert(alerts: ConversationAlert[]): ConversationAlert | null {
  const priorities: Record<ConversationAlert['severity'], number> = {
    critical: 3,
    warning: 2,
    info: 1,
  }

  return alerts
    .filter((alert) => alert.type !== 'message_send_failed')
    .sort((left, right) => priorities[right.severity] - priorities[left.severity])[0] ?? null
}

function alertSeverityLabel(severity: AlertSeverityFilter): string {
  const labels: Record<AlertSeverityFilter, string> = {
    all: 'Todos',
    critical: 'Críticos',
    warning: 'Atenção',
    info: 'Informativos',
  }

  return labels[severity]
}

type NotificationEvent = {
  key: string
  conversationId: string
  message: string
  tone: ConversationToastTone
}

function collectNotificationEvents(conversations: Conversation[]): NotificationEvent[] {
  return conversations.flatMap((conversation) => {
    const messageEvents = conversation.messages
      .filter((message) => message.direction === 'inbound' || message.sender === 'customer')
      .map((message) => ({
        key: `conversation-message:${message.id}`,
        conversationId: conversation.id,
        message: `Nova mensagem de ${conversation.customer.name}.`,
        tone: 'info' as const,
      }))
    const alertEvents = (conversation.alerts ?? [])
      .filter((alert) => isOperationalAlert(alert) && alert.status === 'open')
      .map((alert) => ({
        key: `conversation-alert:${alert.id}`,
        conversationId: conversation.id,
        message: `${alert.title}: ${alert.message}`,
        tone: alert.severity === 'critical' ? 'error' as const : alert.severity === 'warning' ? 'warning' as const : 'info' as const,
      }))

    return [...messageEvents, ...alertEvents]
  })
}

function readBooleanPreference(key: string): boolean {
  try {
    return window.localStorage.getItem(key) === 'true'
  } catch {
    return false
  }
}

function writeBooleanPreference(key: string, value: boolean): void {
  try {
    window.localStorage.setItem(key, String(value))
  } catch {
    // Preferences remain valid for the current session when storage is unavailable.
  }
}

function createClientReference(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }

  return `conversation-${Date.now()}-${Math.random().toString(16).slice(2)}`
}

function playNotificationSound(): void {
  if (typeof window === 'undefined' || typeof window.AudioContext === 'undefined') {
    return
  }

  try {
    const context = new window.AudioContext()
    const oscillator = context.createOscillator()
    const gain = context.createGain()
    oscillator.frequency.value = 640
    gain.gain.setValueAtTime(0.0001, context.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.08, context.currentTime + 0.015)
    gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.16)
    oscillator.connect(gain)
    gain.connect(context.destination)
    oscillator.start()
    oscillator.stop(context.currentTime + 0.18)
    oscillator.addEventListener('ended', () => void context.close(), { once: true })
  } catch {
    // Browser audio policies can block playback; the visual notification still works.
  }
}
