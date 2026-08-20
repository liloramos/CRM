import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import type { DragEvent, FormEvent, RefObject } from 'react'
import { createPortal } from 'react-dom'
import EmojiPicker, { Theme, type EmojiClickData } from 'emoji-picker-react'
import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Button, IconButton } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { Icon } from '../../components/ui/Icon'
import { Modal } from '../../components/ui/Modal'
import { EmptyState } from '../../components/ui/States'
import { CustomerEditor } from '../clientes/CustomerEditor'
import { getConversationQuickReplies, getConversationStickerFavorites, toggleConversationStickerFavorite, type ConversationMediaSendOptions, type CopilotAnalysis, type CopilotOrderProposal, type FulfillmentAction, type UpdateCustomerPayload } from '../../services/crm.service'
import type {
  Conversation,
  ConversationAlert,
  ConversationMessage,
  ConversationOperationalStatus,
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

const OPERATIONAL_STATUS_LEGEND: Array<Pick<ConversationOperationalStatus, 'code' | 'label' | 'tone'> & { description: string }> = [
  { code: 'IDLE', label: 'Sem pedido em andamento', tone: 'gray', description: 'Nenhum pedido ou processo em andamento.' },
  { code: 'IN_SERVICE', label: 'Atendimento em andamento', tone: 'blue', description: 'Atendimento ativo antes de uma etapa de pagamento.' },
  { code: 'AWAITING_PAYMENT', label: 'Aguardando pagamento', tone: 'yellow', description: 'Pedido aguardando confirmação do pagamento.' },
  { code: 'PAYMENT_REVIEW', label: 'Pagamento em revisão', tone: 'orange', description: 'Comprovante aguardando conferência.' },
  { code: 'PREPARING', label: 'Em preparo', tone: 'cyan', description: 'Pedido liberado e sendo preparado.' },
  { code: 'AWAITING_DELIVERY', label: 'Aguardando entrega', tone: 'purple', description: 'Pedido pronto aguardando saída ou retirada.' },
  { code: 'OUT_FOR_DELIVERY', label: 'Saiu para entrega', tone: 'teal', description: 'Pedido já saiu para entrega.' },
  { code: 'COMPLETED', label: 'Concluído', tone: 'green', description: 'Fluxo finalizado normalmente.' },
  { code: 'ATTENTION', label: 'Atenção necessária', tone: 'red', description: 'Existe um problema que exige ação humana.' },
]

type ConversationsPageProps = {
  alerts: ConversationAlert[]
  conversations: Conversation[]
  error: string | null
  isActionBusy: boolean
  isLoading: boolean
  linkedOrder?: Order | null
  selectedConversation?: Conversation
  onAcknowledgeAlert: (conversationId: string, alertId: string) => void
  onAnalyzeCopilot: (conversationId: string) => Promise<CopilotAnalysis>
  onApplyCopilotProposal: (conversation: Conversation, proposal: CopilotOrderProposal) => boolean
  onApprovePayment: (conversationId: string, proofId: string, confirmedAmountCents: number, notes?: string) => Promise<void>
  onChangeMode: (conversationId: string, mode: 'assisted' | 'automatic' | 'manual') => Promise<void> | void
  onCreateOrder: (conversationId: string) => Promise<void>
  onAdvanceOrder: (orderId: string, action: FulfillmentAction) => Promise<void>
  onOpenOrders: () => void
  onOpenOrder: (orderId: string) => void
  onPreviewTicket: (orderId: string) => void
  onRejectPayment: (conversationId: string, proofId: string, reason: string) => Promise<void>
  onResolveAlert: (conversationId: string, alertId: string) => void
  onSelectConversation: (conversationId: string) => void
  onRetryMessage: (conversationId: string, messageId: string) => Promise<void>
  onReactMessage: (conversationId: string, messageId: string, emoji: string) => Promise<void>
  onSendMessage: (conversationId: string, body: string, clientReference: string, replyToMessageId?: string) => Promise<void>
  onSendMedia: (conversationId: string, file: File, mediaType: 'image' | 'video' | 'document' | 'audio' | 'sticker', caption: string, options?: ConversationMediaSendOptions) => Promise<void>
  onToggleMessagePin: (conversationId: string, messageId: string) => Promise<void>
  onToggleConversationPin: (conversationId: string) => Promise<void>
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
  onAnalyzeCopilot,
  onApplyCopilotProposal,
  onApprovePayment,
  onChangeMode,
  onCreateOrder,
  onAdvanceOrder,
  onOpenOrders,
  onOpenOrder,
  onPreviewTicket,
  onRejectPayment,
  onResolveAlert,
  onSelectConversation,
  onRetryMessage,
  onReactMessage,
  onSendMessage,
  onSendMedia,
  onToggleMessagePin,
  onToggleConversationPin,
  onUpdateCustomer,
  selectedConversation,
}: ConversationsPageProps) {
  const [activeFilter, setActiveFilter] = useState<ConversationFilter>('all')
  const [activePanel, setActivePanel] = useState<ConversationPanel>('list')
  const [isContextOpen, setIsContextOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [composerBody, setComposerBody] = useState('')
  const [attachment, setAttachment] = useState<{ file: File; type: 'image' | 'video' | 'document' | 'audio' | 'sticker' } | null>(null)
  const [isAttachmentMenuOpen, setIsAttachmentMenuOpen] = useState(false)
  const attachmentInputRef = useRef<HTMLInputElement>(null)
  const videoInputRef = useRef<HTMLInputElement>(null)
  const documentInputRef = useRef<HTMLInputElement>(null)
  const audioInputRef = useRef<HTMLInputElement>(null)
  const stickerInputRef = useRef<HTMLInputElement>(null)
  const mediaRecorderRef = useRef<MediaRecorder | null>(null)
  const recordingStreamRef = useRef<MediaStream | null>(null)
  const recordingChunksRef = useRef<Blob[]>([])
  const recordingTimerRef = useRef<number | null>(null)
  const recordingUrlRef = useRef<string | null>(null)
  const [recordingState, setRecordingState] = useState<'idle' | 'requesting' | 'recording' | 'preview' | 'failed'>('idle')
  const [recordingSeconds, setRecordingSeconds] = useState(0)
  const [recordedAudio, setRecordedAudio] = useState<{ file: File; url: string; mimeType: string; requestedMimeType: string | null; sizeBytes: number } | null>(null)
  const [replyingTo, setReplyingTo] = useState<ConversationMessage | null>(null)
  const [paymentAmount, setPaymentAmount] = useState('')
  const [paymentNotes, setPaymentNotes] = useState('')
  const [paymentRejectReason, setPaymentRejectReason] = useState('')
  const [localError, setLocalError] = useState<string | null>(null)
  const [copilotAnalysis, setCopilotAnalysis] = useState<CopilotAnalysis | null>(null)
  const [copilotAnalysisConversationId, setCopilotAnalysisConversationId] = useState<string | null>(null)
  const [copilotProposalApplied, setCopilotProposalApplied] = useState(false)
  const [isAnalyzingCopilot, setIsAnalyzingCopilot] = useState(false)
  const [editingCustomer, setEditingCustomer] = useState<CustomerSummary | null>(null)
  const [customerError, setCustomerError] = useState<string | null>(null)
  const [isSavingCustomer, setIsSavingCustomer] = useState(false)
  const [quickReplies, setQuickReplies] = useState<ConversationQuickReply[]>([])
  const [quickReplySearch, setQuickReplySearch] = useState('')
  const [isQuickReplyOpen, setIsQuickReplyOpen] = useState(false)
  const [isEmojiPickerOpen, setIsEmojiPickerOpen] = useState(false)
  const [emojiPosition, setEmojiPosition] = useState<{ top: number; left: number } | null>(null)
  const [isStickerPickerOpen, setIsStickerPickerOpen] = useState(false)
  const [stickerPosition, setStickerPosition] = useState<{ top: number; left: number } | null>(null)
  const [stickerTab, setStickerTab] = useState<'recent' | 'favorites'>('recent')
  const [favoriteStickerIds, setFavoriteStickerIds] = useState<Set<string>>(new Set())
  const [isDragActive, setIsDragActive] = useState(false)
  const [cameraState, setCameraState] = useState<'closed' | 'requesting' | 'ready' | 'preview' | 'failed'>('closed')
  const [cameraVideoReady, setCameraVideoReady] = useState(false)
  const [cameraStreamActive, setCameraStreamActive] = useState(false)
  const [cameraPreview, setCameraPreview] = useState<string | null>(null)
  const [isConfigurationOpen, setIsConfigurationOpen] = useState(false)
  const [isPinnedMessagesOpen, setIsPinnedMessagesOpen] = useState(false)
  const [conversationMenuOpenId, setConversationMenuOpenId] = useState<string | null>(null)
  const [isStatusLegendOpen, setIsStatusLegendOpen] = useState(false)
  const [statusLegendPosition, setStatusLegendPosition] = useState<{ top: number; left: number } | null>(null)
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
  const statusLegendRef = useRef<HTMLDivElement>(null)
  const statusLegendTriggerRef = useRef<HTMLButtonElement>(null)
  const statusLegendPopoverRef = useRef<HTMLDivElement>(null)
  const quickReplyTriggerRef = useRef<HTMLDivElement>(null)
  const quickReplyPickerRef = useRef<HTMLDivElement>(null)
  const emojiTriggerRef = useRef<HTMLButtonElement>(null)
  const emojiPickerRef = useRef<HTMLDivElement>(null)
  const stickerPickerRef = useRef<HTMLDivElement>(null)
  const stickerTriggerRef = useRef<HTMLButtonElement>(null)
  const dragDepthRef = useRef(0)
  const cameraVideoRef = useRef<HTMLVideoElement>(null)
  const cameraStreamRef = useRef<MediaStream | null>(null)
  const emojiSelectionRef = useRef({ start: 0, end: 0 })

  const stickerOptions = useMemo(() => {
    const recent: Array<{ id: string; url: string; contentHash: string }> = []
    conversations.forEach((conversation) => conversation.messages.forEach((message) => (message.media ?? [])
      .filter((media) => media.type === 'sticker' && Boolean(media.url))
      .forEach((media) => {
        const contentHash = media.contentHash || media.id
        const sticker = { id: media.id, url: media.url as string, contentHash }
        const existingIndex = recent.findIndex((candidate) => candidate.contentHash === contentHash)
        if (existingIndex >= 0) recent.splice(existingIndex, 1)
        recent.unshift(sticker)
      })))
    return recent.slice(0, 24)
  }, [conversations])
  const visibleStickers = stickerOptions.filter((sticker) => stickerTab === 'recent' || favoriteStickerIds.has(sticker.contentHash))

  useEffect(() => { void getConversationStickerFavorites().then((hashes) => setFavoriteStickerIds(new Set(hashes))).catch(() => undefined) }, [])

  useEffect(() => {
    if (cameraState !== 'ready' || !cameraStreamRef.current || !cameraVideoRef.current) return undefined
    const video = cameraVideoRef.current
    const stream = cameraStreamRef.current
    setCameraStreamActive(stream.active)
    setCameraVideoReady(false)
    video.srcObject = stream
    const startPreview = () => { setCameraVideoReady(video.videoWidth > 0 && video.videoHeight > 0); void video.play().catch(() => undefined) }
    video.addEventListener('loadedmetadata', startPreview)
    if (video.readyState >= HTMLMediaElement.HAVE_METADATA) startPreview()
    return () => {
      video.removeEventListener('loadedmetadata', startPreview)
      video.pause()
      video.srcObject = null
      setCameraVideoReady(false)
      setCameraStreamActive(false)
    }
  }, [cameraState])

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

  useEffect(() => () => {
    if (recordingTimerRef.current !== null) window.clearInterval(recordingTimerRef.current)
    recordingStreamRef.current?.getTracks().forEach((track) => track.stop())
    if (recordingUrlRef.current) URL.revokeObjectURL(recordingUrlRef.current)
    cameraStreamRef.current?.getTracks().forEach((track) => track.stop())
    if (cameraPreview) URL.revokeObjectURL(cameraPreview)
  }, [cameraPreview])

  function clearRecordedAudio() {
    if (recordingUrlRef.current) URL.revokeObjectURL(recordingUrlRef.current)
    recordingUrlRef.current = null
    setRecordedAudio(null)
    setRecordingSeconds(0)
    setRecordingState('idle')
  }

  async function startVoiceRecording() {
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
      setRecordingState('failed')
      setLocalError('Seu navegador não oferece gravação de áudio.')
      return
    }

    setLocalError(null)
    setAttachment(null)
    setRecordingState('requesting')
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
      const supportedMimeType = [
        'audio/ogg;codecs=opus',
        'audio/mp4',
        'audio/webm;codecs=opus',
        'audio/webm',
      ].find((mimeType) => MediaRecorder.isTypeSupported(mimeType))
      if (!supportedMimeType) {
        stream.getTracks().forEach((track) => track.stop())
        setRecordingState('failed')
        setLocalError('Este navegador não grava áudio em um formato aceito pelo WhatsApp.')
        return
      }
      const recorder = new MediaRecorder(stream, { mimeType: supportedMimeType })

      recordingStreamRef.current = stream
      mediaRecorderRef.current = recorder
      recordingChunksRef.current = []
      setRecordingSeconds(0)
      recorder.ondataavailable = (event) => {
        if (event.data.size > 0) recordingChunksRef.current.push(event.data)
      }
      recorder.onerror = () => {
        stream.getTracks().forEach((track) => track.stop())
        setRecordingState('failed')
        setLocalError('Não foi possível gravar o áudio. Tente novamente.')
      }
      recorder.onstop = () => {
        const type = recorder.mimeType || supportedMimeType
        const extension = type.includes('ogg') ? 'ogg' : type.includes('mp4') ? 'm4a' : 'webm'
        const blob = new Blob(recordingChunksRef.current, { type })
        const url = URL.createObjectURL(blob)
        recordingUrlRef.current = url
        setRecordedAudio({
          file: new File([blob], `mensagem-de-voz.${extension}`, { type }),
          url,
          mimeType: type,
          requestedMimeType: supportedMimeType ?? null,
          sizeBytes: blob.size,
        })
        setRecordingState('preview')
        stream.getTracks().forEach((track) => track.stop())
      }
      recorder.start()
      setRecordingState('recording')
      recordingTimerRef.current = window.setInterval(() => setRecordingSeconds((seconds) => seconds + 1), 1000)
    } catch {
      recordingStreamRef.current?.getTracks().forEach((track) => track.stop())
      setRecordingState('failed')
      setLocalError('Permita o uso do microfone para gravar uma mensagem de voz.')
    }
  }

  function stopVoiceRecording() {
    if (recordingTimerRef.current !== null) window.clearInterval(recordingTimerRef.current)
    recordingTimerRef.current = null
    if (mediaRecorderRef.current?.state === 'recording') mediaRecorderRef.current.stop()
  }

  function cancelVoiceRecording() {
    if (recordingTimerRef.current !== null) window.clearInterval(recordingTimerRef.current)
    recordingTimerRef.current = null
    if (mediaRecorderRef.current?.state === 'recording') {
      mediaRecorderRef.current.onstop = null
      mediaRecorderRef.current.stop()
    }
    recordingStreamRef.current?.getTracks().forEach((track) => track.stop())
    recordingStreamRef.current = null
    recordingChunksRef.current = []
    clearRecordedAudio()
  }

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

  useEffect(() => {
    if (!isStatusLegendOpen) {
      return undefined
    }

    function dismiss(event: MouseEvent) {
      if (event.target instanceof Node
        && !statusLegendRef.current?.contains(event.target)
        && !statusLegendPopoverRef.current?.contains(event.target)) {
        setIsStatusLegendOpen(false)
      }
    }

    function escape(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setIsStatusLegendOpen(false)
      }
    }

    document.addEventListener('mousedown', dismiss)
    document.addEventListener('keydown', escape)
    return () => {
      document.removeEventListener('mousedown', dismiss)
      document.removeEventListener('keydown', escape)
    }
  }, [isStatusLegendOpen])

  useLayoutEffect(() => {
    if (!isStatusLegendOpen) {
      return undefined
    }

    function positionStatusLegend() {
      const trigger = statusLegendTriggerRef.current
      const popover = statusLegendPopoverRef.current

      if (!trigger || !popover) {
        return
      }

      const triggerRect = trigger.getBoundingClientRect()
      const popoverRect = popover.getBoundingClientRect()
      const padding = 12
      const spaceAbove = triggerRect.top - padding
      const spaceBelow = window.innerHeight - triggerRect.bottom - padding

      const opensAbove = spaceBelow < popoverRect.height && spaceAbove > spaceBelow
      const gap = 10
      const preferredTop = opensAbove
        ? triggerRect.top - popoverRect.height - gap
        : triggerRect.bottom + gap
      const top = Math.min(
        Math.max(padding, preferredTop),
        Math.max(padding, window.innerHeight - popoverRect.height - padding),
      )
      const left = Math.min(
        Math.max(padding, triggerRect.left),
        Math.max(padding, window.innerWidth - popoverRect.width - padding),
      )

      setStatusLegendPosition({ top, left })
    }

    const frame = window.requestAnimationFrame(positionStatusLegend)
    window.addEventListener('resize', positionStatusLegend)
    window.addEventListener('scroll', positionStatusLegend, true)

    return () => {
      window.cancelAnimationFrame(frame)
      window.removeEventListener('resize', positionStatusLegend)
      window.removeEventListener('scroll', positionStatusLegend, true)
    }
  }, [isStatusLegendOpen])

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

  useLayoutEffect(() => {
    if (!isEmojiPickerOpen) return undefined

    const position = () => {
      const trigger = emojiTriggerRef.current
      const picker = emojiPickerRef.current
      if (!trigger || !picker) return
      const triggerRect = trigger.getBoundingClientRect()
      const pickerRect = picker.getBoundingClientRect()
      const gap = 8
      const left = Math.min(Math.max(12, triggerRect.left), Math.max(12, window.innerWidth - pickerRect.width - 12))
      const preferredTop = triggerRect.top - pickerRect.height - gap
      const top = preferredTop >= 12 ? preferredTop : Math.min(triggerRect.bottom + gap, window.innerHeight - pickerRect.height - 12)
      setEmojiPosition({ top: Math.max(12, top), left })
    }
    const frame = window.requestAnimationFrame(position)
    window.addEventListener('resize', position)
    window.addEventListener('scroll', position, true)
    return () => {
      window.cancelAnimationFrame(frame)
      window.removeEventListener('resize', position)
      window.removeEventListener('scroll', position, true)
    }
  }, [isEmojiPickerOpen])

  useLayoutEffect(() => {
    if (!isStickerPickerOpen) return undefined
    const position = () => {
      const trigger = stickerTriggerRef.current
      const picker = stickerPickerRef.current
      if (!trigger || !picker) return
      const triggerRect = trigger.getBoundingClientRect()
      const pickerRect = picker.getBoundingClientRect()
      const padding = 12
      const left = Math.min(Math.max(padding, triggerRect.left), Math.max(padding, window.innerWidth - pickerRect.width - padding))
      const above = triggerRect.top - pickerRect.height - 8
      const top = above >= padding ? above : Math.min(triggerRect.bottom + 8, window.innerHeight - pickerRect.height - padding)
      setStickerPosition({ top: Math.max(padding, top), left })
    }
    const frame = window.requestAnimationFrame(position)
    window.addEventListener('resize', position)
    window.addEventListener('scroll', position, true)
    return () => { window.cancelAnimationFrame(frame); window.removeEventListener('resize', position); window.removeEventListener('scroll', position, true) }
  }, [isStickerPickerOpen, visibleStickers.length])

  useEffect(() => {
    if (!isStickerPickerOpen) return undefined
    const dismiss = (event: MouseEvent) => {
      if (!(event.target instanceof Node) || (!stickerPickerRef.current?.contains(event.target) && !(event.target as Element).closest('.sticker-trigger'))) {
        setIsStickerPickerOpen(false)
      }
    }
    const escape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setIsStickerPickerOpen(false)
    }
    document.addEventListener('mousedown', dismiss)
    document.addEventListener('keydown', escape)
    return () => {
      document.removeEventListener('mousedown', dismiss)
      document.removeEventListener('keydown', escape)
    }
  }, [isStickerPickerOpen])

  useEffect(() => {
    if (!isEmojiPickerOpen) return undefined
    const dismiss = (event: MouseEvent) => {
      if (!(event.target instanceof Node)) return
      if (!emojiTriggerRef.current?.contains(event.target) && !emojiPickerRef.current?.contains(event.target)) setIsEmojiPickerOpen(false)
    }
    const escape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setIsEmojiPickerOpen(false)
        emojiTriggerRef.current?.focus()
      }
    }
    document.addEventListener('mousedown', dismiss)
    document.addEventListener('keydown', escape)
    return () => {
      document.removeEventListener('mousedown', dismiss)
      document.removeEventListener('keydown', escape)
    }
  }, [isEmojiPickerOpen])

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
    setConversationMenuOpenId(null)
  }

  function scrollToPinnedMessage(messageId: string) {
    const element = document.getElementById(`message-${messageId}`)
    if (!element) return
    element.scrollIntoView({ behavior: 'smooth', block: 'center' })
    element.classList.remove('is-pinned-highlight')
    void element.offsetWidth
    element.classList.add('is-pinned-highlight')
    window.setTimeout(() => element.classList.remove('is-pinned-highlight'), 1600)
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
    if (!body && !attachment && !recordedAudio) {
      setLocalError('Digite uma mensagem para enviar.')
      return
    }

    try {
      const clientReference = clientReferenceRef.current ?? createClientReference()
      clientReferenceRef.current = clientReference
      if (recordedAudio) {
        await onSendMedia(selectedConversation.id, recordedAudio.file, 'audio', '', {
          recordingSource: 'browser',
          recordingMimeType: recordedAudio.mimeType,
          recordingRequestedMimeType: recordedAudio.requestedMimeType ?? undefined,
        })
        clearRecordedAudio()
      } else if (attachment) {
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

  function openEmojiPicker() {
    const textarea = composerRef.current
    emojiSelectionRef.current = {
      start: textarea?.selectionStart ?? composerBody.length,
      end: textarea?.selectionEnd ?? composerBody.length,
    }
    setIsEmojiPickerOpen((open) => !open)
  }

  function insertEmoji(emoji: string) {
    const { start, end } = emojiSelectionRef.current
    const nextValue = `${composerBody.slice(0, start)}${emoji}${composerBody.slice(end)}`
    const nextCursor = start + emoji.length
    handleComposerChange(nextValue)
    setIsEmojiPickerOpen(false)
    window.requestAnimationFrame(() => {
      composerRef.current?.focus()
      composerRef.current?.setSelectionRange(nextCursor, nextCursor)
    })
  }

  async function sendSticker(sticker: { id: string; url: string }) {
    try {
      const response = await fetch(sticker.url)
      if (!response.ok) throw new Error('Não foi possível carregar a figurinha.')
      const blob = await response.blob()
      await onSendMedia(selectedConversation?.id ?? '', new File([blob], 'figurinha.webp', { type: blob.type || 'image/webp' }), 'sticker', '')
      setIsStickerPickerOpen(false)
    } catch (error) {
      setLocalError(error instanceof Error ? error.message : 'Não foi possível enviar a figurinha.')
    }
  }

  async function toggleStickerFavorite(sticker: { id: string; contentHash: string }) {
    try {
      const favorited = await toggleConversationStickerFavorite(sticker.contentHash, sticker.id)
      setFavoriteStickerIds((current) => {
        const next = new Set(current)
        if (favorited) next.add(sticker.contentHash)
        else next.delete(sticker.contentHash)
        return next
      })
    } catch {
      setLocalError('Não foi possível atualizar as favoritas agora.')
    }
  }

  function queueAttachment(file: File, type?: 'image' | 'video' | 'document' | 'audio' | 'sticker') {
    const inferred = type ?? inferAttachmentType(file)
    if (!inferred) {
      setLocalError('Esse tipo de arquivo ainda não é aceito como anexo.')
      return
    }
    clearRecordedAudio()
    setAttachment({ file, type: inferred })
    setLocalError(null)
  }

  function handleDragEnter(event: DragEvent<HTMLElement>) {
    event.preventDefault()
    if (!Array.from(event.dataTransfer.types).includes('Files')) return
    dragDepthRef.current += 1
    setIsDragActive(true)
  }

  function handleDragLeave(event: DragEvent<HTMLElement>) {
    event.preventDefault()
    dragDepthRef.current = Math.max(0, dragDepthRef.current - 1)
    if (dragDepthRef.current === 0) setIsDragActive(false)
  }

  function handleDrop(event: DragEvent<HTMLElement>) {
    event.preventDefault()
    dragDepthRef.current = 0
    setIsDragActive(false)
    const file = event.dataTransfer.files?.[0]
    if (file) queueAttachment(file)
  }

  async function openCamera() {
    if (!navigator.mediaDevices?.getUserMedia) {
      setLocalError('Este navegador não oferece acesso à câmera.')
      return
    }
    setCameraState('requesting')
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false })
      cameraStreamRef.current = stream
      setCameraStreamActive(stream.active)
      setCameraState('ready')
    } catch (error) {
      setCameraState('failed')
      const name = error instanceof DOMException ? error.name : ''
      setLocalError(name === 'NotAllowedError' ? 'A permissão da câmera foi negada.'
        : name === 'NotFoundError' ? 'Nenhuma câmera foi encontrada.'
          : name === 'NotReadableError' ? 'A câmera está ocupada ou indisponível.'
            : 'Não foi possível acessar a câmera neste contexto.')
    }
  }

  function closeCamera() {
    cameraStreamRef.current?.getTracks().forEach((track) => track.stop())
    cameraStreamRef.current = null
    setCameraStreamActive(false)
    if (cameraPreview) URL.revokeObjectURL(cameraPreview)
    setCameraPreview(null)
    setCameraVideoReady(false)
    setCameraState('closed')
  }

  function captureCameraPhoto() {
    const video = cameraVideoRef.current
    if (!video || video.videoWidth === 0 || video.videoHeight === 0 || !cameraStreamRef.current?.active) {
      setLocalError('A câmera ainda não está pronta para capturar.')
      return
    }
    const canvas = document.createElement('canvas')
    canvas.width = video.videoWidth
    canvas.height = video.videoHeight
    canvas.getContext('2d')?.drawImage(video, 0, 0, canvas.width, canvas.height)
    canvas.toBlob((blob) => {
      if (!blob) return
      cameraStreamRef.current?.getTracks().forEach((track) => track.stop())
      cameraStreamRef.current = null
      setCameraStreamActive(false)
      const file = new File([blob], `foto-${new Date().toISOString().replace(/[:.]/g, '').slice(0, 15)}.jpg`, { type: 'image/jpeg' })
      setCameraPreview(URL.createObjectURL(file))
      setAttachment({ file, type: 'image' })
      setCameraState('preview')
    }, 'image/jpeg', .9)
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
  const visibleCopilotAnalysis = copilotAnalysisConversationId === selectedConversation?.id ? copilotAnalysis : null

  async function handleAnalyzeCopilot() {
    if (!selectedConversation || isAnalyzingCopilot) return
    setIsAnalyzingCopilot(true)
    setLocalError(null)
    try {
      setCopilotAnalysis(await onAnalyzeCopilot(selectedConversation.id))
      setCopilotAnalysisConversationId(selectedConversation.id)
      setCopilotProposalApplied(false)
    } catch {
      setLocalError('Nao foi possivel analisar a conversa agora.')
    } finally {
      setIsAnalyzingCopilot(false)
    }
  }

  function handleUseCopilotReply() {
    if (!visibleCopilotAnalysis?.suggested_reply) return
    if (composerBody.trim() && !window.confirm('Substituir o texto atual pela resposta sugerida?')) return
    setComposerBody(visibleCopilotAnalysis.suggested_reply)
  }

  function handleApplyCopilotProposal() {
    if (!selectedConversation || !visibleCopilotAnalysis?.proposal?.can_apply) return

    setCopilotProposalApplied(onApplyCopilotProposal(selectedConversation, visibleCopilotAnalysis.proposal))
  }

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
            <div className="conversation-status-legend" ref={statusLegendRef}>
            <button
              aria-expanded={isStatusLegendOpen}
                aria-haspopup="dialog"
                className="conversation-filter conversation-status-legend__trigger"
                onClick={() => setIsStatusLegendOpen((open) => !open)}
                ref={statusLegendTriggerRef}
                type="button"
            >
              <span>Status</span>
              <Icon className={isStatusLegendOpen ? 'is-open' : ''} name="chevron-down" size={14} />
            </button>
            </div>
          </div>

          {isStatusLegendOpen && typeof document !== 'undefined' ? createPortal(
            <div
              aria-label="Legenda de status operacionais"
              className="conversation-status-legend__popover"
              ref={statusLegendPopoverRef}
              role="dialog"
              style={{
                left: statusLegendPosition?.left ?? 0,
                top: statusLegendPosition?.top ?? 0,
                visibility: statusLegendPosition ? 'visible' : 'hidden',
              }}
            >
              {OPERATIONAL_STATUS_LEGEND.map((status) => (
                <div className="conversation-status-legend__item" key={status.code}>
                  <StatusDot status={{ ...status, priority: 0 }} />
                  <span>
                    <strong>{status.label}</strong>
                    <small>{status.description}</small>
                  </span>
                </div>
              ))}
            </div>,
            document.body,
          ) : null}

          <div className="conversation-list" role="list">
            {filteredConversations.map((conversation) => {
              const operationalStatus = operationalStatusFor(conversation)
              const isActive = selectedConversation?.id === conversation.id

              return (
                <div className="conversation-item-wrapper" key={conversation.id} role="listitem">
                <button
                  className={isActive ? 'conversation-item is-active' : 'conversation-item'}
                  onClick={() => handleSelectConversation(conversation.id)}
                  title={`${conversation.customer.name} - ${conversation.customer.phoneLabel}`}
                  type="button"
                >
                  <span className="avatar">{initialsFromName(conversation.customer.name)}</span>
                    <span className="conversation-item__content">
                      <span className="conversation-item__top">
                      <span className="conversation-item__identity">
                        <strong>{conversation.customer.name}</strong>
                        <StatusDot status={operationalStatus} />
                      </span>
                      <time>{conversation.isPinned ? <Icon name="pin" size={12} /> : null}{formatConversationTime(conversation.lastMessageAt)}</time>
                    </span>
                    <small>{conversation.customer.phoneLabel || 'Sem telefone cadastrado'}</small>
                    <span className="conversation-item__preview">{conversation.lastMessage}</span>
                    <span className="conversation-item__badges">
                      {conversation.unread > 0 ? <Badge tone="brand" size="sm">{`${conversation.unread} não lida${conversation.unread > 1 ? 's' : ''}`}</Badge> : null}
                    </span>
                  </span>
                </button>
                <button
                  aria-expanded={conversationMenuOpenId === conversation.id}
                  aria-label={`Ações de ${conversation.customer.name}`}
                  className="conversation-item__menu-trigger"
                  onClick={(event) => { event.stopPropagation(); setConversationMenuOpenId((current) => current === conversation.id ? null : conversation.id) }}
                  type="button"
                >
                  <Icon name="chevron-down" size={14} />
                </button>
                {conversationMenuOpenId === conversation.id ? (
                  <div className="conversation-item__menu" role="menu">
                    <button onClick={() => { void onToggleConversationPin(conversation.id); setConversationMenuOpenId(null) }} role="menuitem" type="button">
                      <Icon name="pin" size={14} /> {conversation.isPinned ? 'Desafixar conversa' : 'Fixar conversa'}
                    </button>
                    <button onClick={() => { handleSelectConversation(conversation.id); handleOpenContext() }} role="menuitem" type="button">Ver detalhes</button>
                  </div>
                ) : null}
                </div>
              )
            })}
          </div>
          {filteredConversations.length === 0 ? (
            <EmptyState description="Nenhuma conversa encontrada para este filtro." title="Sem conversas" />
          ) : null}
        </Card>

        <Card className={`chat-panel ${isDragActive ? 'is-dragging' : ''}`}>
          {selectedConversation ? (
            <>
              <div className="chat-panel__header">
                <div className="chat-heading">
                  <span className="avatar">{initialsFromName(selectedConversation.customer.name)}</span>
                  <div className="chat-heading__identity">
                    <div className="chat-heading__title-row">
                      <h2>{selectedConversation.customer.name}</h2>
                      <StatusDot status={operationalStatusFor(selectedConversation)} />
                    </div>
                    <div className="chat-heading__meta">
                      <span>{selectedConversation.customer.phoneLabel || 'Sem telefone cadastrado'}</span>
                      <span>{operationalStatusFor(selectedConversation).label}</span>
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
                  <Button className="conversation-context-toggle" onClick={handleOpenContext} size="sm" variant="secondary">
                    Ver detalhes
                  </Button>
                  {selectedConversation.messages.some((message) => message.isPinned) ? (
                    <Button onClick={() => setIsPinnedMessagesOpen((open) => !open)} size="sm" variant="ghost">
                      <Icon name="pin" size={14} /> {selectedConversation.messages.filter((message) => message.isPinned).length} fixadas
                    </Button>
                  ) : null}
                </div>
              </div>

              {isPinnedMessagesOpen ? (
                <div className="chat-pinned-messages" role="dialog" aria-label="Mensagens fixadas">
                  {selectedConversation.messages.filter((message) => message.isPinned).map((message) => (
                    <button key={message.id} onClick={() => { scrollToPinnedMessage(message.id); setIsPinnedMessagesOpen(false) }} type="button">
                      <span><strong><Icon name="pin" size={12} /> {pinnedMessageLabel(message)}</strong><small>{senderLabel(message.sender)} · {message.timeLabel}</small></span>
                    </button>
                  ))}
                </div>
              ) : null}

              {bannerAlert ? (
                <ConversationAlertBanner
                  alert={bannerAlert}
                  onOpenDetails={handleOpenContext}
                />
              ) : null}

              {isDragActive ? <div className="chat-drop-overlay" role="status">Solte para anexar</div> : null}
              <MessageTimeline
                conversationId={selectedConversation.id}
                onDragEnter={handleDragEnter}
                onDragLeave={handleDragLeave}
                onDragOver={(event) => event.preventDefault()}
                onDrop={handleDrop}
                messages={selectedConversation.messages}
                onReply={handleReplyToMessage}
                onRetryMessage={handleRetryMessage}
                onReactMessage={(messageId, emoji) => void onReactMessage(selectedConversation.id, messageId, emoji)}
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
                      clearRecordedAudio()
                      setAttachment({ file, type: 'image' })
                    }
                    event.currentTarget.value = ''
                  }}
                  ref={attachmentInputRef}
                  type="file"
                />
                <input
                  accept="video/mp4,video/3gpp"
                  hidden
                  onChange={(event) => {
                    const file = event.target.files?.[0]
                    if (file) {
                      clearRecordedAudio()
                      setAttachment({ file, type: 'video' })
                    }
                    event.currentTarget.value = ''
                  }}
                  ref={videoInputRef}
                  type="file"
                />
                <input
                  accept=".pdf,.txt,.doc,.docx,application/pdf,text/plain,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                  hidden
                  onChange={(event) => {
                    const file = event.target.files?.[0]
                    if (file) {
                      clearRecordedAudio()
                      setAttachment({ file, type: 'document' })
                    }
                    event.currentTarget.value = ''
                  }}
                  ref={documentInputRef}
                  type="file"
                />
                <input
                  accept="audio/ogg,audio/mpeg,audio/mp4,audio/aac,audio/amr,audio/opus,audio/webm"
                  hidden
                  onChange={(event) => {
                    const file = event.target.files?.[0]
                    if (file) {
                      clearRecordedAudio()
                      setAttachment({ file, type: 'audio' })
                    }
                    event.currentTarget.value = ''
                  }}
                  ref={audioInputRef}
                  type="file"
                />
                <input
                  accept="image/webp"
                  hidden
                  onChange={(event) => {
                    const file = event.target.files?.[0]
                    if (file) {
                      clearRecordedAudio()
                      setAttachment({ file, type: 'sticker' })
                    }
                    event.currentTarget.value = ''
                  }}
                  ref={stickerInputRef}
                  type="file"
                />
                {attachment ? (
                  <div className="composer-attachment-preview">
                    <span>{mediaTypeLabel(attachment.type)}: {attachment.file.name}</span>
                    <IconButton icon="close" label="Remover anexo" onClick={() => setAttachment(null)} />
                  </div>
                ) : null}
                {recordedAudio ? (
                  <div className="composer-attachment-preview composer-audio-preview">
                    <VoiceMessagePlayer src={recordedAudio.url} />
                    <IconButton icon="close" label="Descartar gravação" onClick={clearRecordedAudio} />
                  </div>
                ) : null}
                {recordingState === 'recording' ? (
                  <div className="composer-recording-state" role="status">
                    <span className="composer-recording-state__dot" />
                    <strong>Gravando {formatRecordingDuration(recordingSeconds)}</strong>
                    <button onClick={cancelVoiceRecording} type="button">Cancelar</button>
                    <button onClick={stopVoiceRecording} type="button">Finalizar</button>
                  </div>
                ) : null}
                <div className="composer__footer">
                  <div className="composer__tools">
                    <button
                      aria-expanded={isEmojiPickerOpen}
                      aria-haspopup="dialog"
                      aria-label="Inserir emoji"
                      className="composer-emoji-trigger"
                      onClick={openEmojiPicker}
                      onMouseDown={(event) => event.preventDefault()}
                      ref={emojiTriggerRef}
                      type="button"
                    >
                      😊
                    </button>
                    <button aria-expanded={isStickerPickerOpen} aria-haspopup="dialog" aria-label="Escolher figurinha" className="composer-icon-trigger sticker-trigger" onClick={() => setIsStickerPickerOpen((open) => !open)} ref={stickerTriggerRef} type="button">
                      <Icon name="sticker" size={18} />
                    </button>
                    <div className="attachment-picker">
                      <button aria-expanded={isAttachmentMenuOpen} aria-haspopup="menu" aria-label="Anexar arquivo" className="composer-icon-trigger" onClick={() => setIsAttachmentMenuOpen((open) => !open)} type="button"><Icon name="paperclip" size={18} /></button>
                      {isAttachmentMenuOpen ? (
                        <div className="attachment-picker__menu" role="menu">
                          <button onClick={() => { setIsAttachmentMenuOpen(false); setCameraState('requesting'); void openCamera() }} role="menuitem" type="button">Câmera</button>
                          <button onClick={() => { setIsAttachmentMenuOpen(false); attachmentInputRef.current?.click() }} role="menuitem" type="button">Foto / imagem</button>
                          <button onClick={() => { setIsAttachmentMenuOpen(false); videoInputRef.current?.click() }} role="menuitem" type="button">Vídeo</button>
                          <button onClick={() => { setIsAttachmentMenuOpen(false); documentInputRef.current?.click() }} role="menuitem" type="button">Documento</button>
                          <button onClick={() => { setIsAttachmentMenuOpen(false); audioInputRef.current?.click() }} role="menuitem" type="button">Áudio</button>
                        </div>
                      ) : null}
                    </div>
                    {!attachment && !recordedAudio ? (
                      <button
                        aria-label="Gravar áudio"
                        className="composer-icon-trigger"
                        disabled={isActionBusy || recordingState === 'requesting'}
                        onClick={recordingState === 'recording' ? stopVoiceRecording : startVoiceRecording}
                        type="button"
                      >
                        {recordingState === 'requesting' ? 'Preparando microfone' : recordingState === 'recording' ? `Parar gravação (${formatRecordingDuration(recordingSeconds)})` : 'Gravar voz'}
                        <Icon name="mic" size={18} />
                      </button>
                    ) : null}
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
                  <button aria-label="Enviar mensagem" className="composer-send-button" disabled={isActionBusy || recordingState === 'recording' || (composerBody.trim() === '' && !attachment && !recordedAudio)} type="submit"><Icon name="arrow" size={18} /></button>
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
                {isEmojiPickerOpen && typeof document !== 'undefined' ? createPortal(
                  <div
                    aria-label="Escolher emoji"
                    className="conversation-emoji-picker"
                    ref={emojiPickerRef}
                    role="dialog"
                    style={emojiPosition ? { left: emojiPosition.left, top: emojiPosition.top } : { visibility: 'hidden' }}
                  >
                    <EmojiPicker
                      autoFocusSearch={false}
                      onEmojiClick={(emoji: EmojiClickData) => insertEmoji(emoji.emoji)}
                      previewConfig={{ showPreview: false }}
                      searchPlaceHolder="Buscar emoji"
                      theme={Theme.DARK}
                      width="100%"
                    />
                  </div>, document.body) : null}
                {isStickerPickerOpen && typeof document !== 'undefined' ? createPortal(
                  <div aria-label="Biblioteca de figurinhas do CRM" className="conversation-sticker-picker" ref={stickerPickerRef} role="dialog" style={stickerPosition ? { left: stickerPosition.left, top: stickerPosition.top } : { visibility: 'hidden' }}>
                    <div className="conversation-sticker-picker__tabs">
                      <button className={stickerTab === 'recent' ? 'is-active' : ''} onClick={() => setStickerTab('recent')} type="button">Recentes</button>
                      <button className={stickerTab === 'favorites' ? 'is-active' : ''} onClick={() => setStickerTab('favorites')} type="button">Favoritas</button>
                    </div>
                    <div className="conversation-sticker-picker__grid">
                      {visibleStickers.map((sticker) => (
                        <div className="conversation-sticker-picker__item" key={sticker.id}>
                          <button aria-label="Enviar figurinha" onClick={() => void sendSticker(sticker)} type="button"><img alt="Figurinha" src={sticker.url} /></button>
                          <button aria-label={favoriteStickerIds.has(sticker.contentHash) ? 'Remover dos favoritos' : 'Favoritar figurinha'} className="conversation-sticker-picker__favorite" onClick={() => void toggleStickerFavorite(sticker)} type="button">{favoriteStickerIds.has(sticker.contentHash) ? '★' : '☆'}</button>
                        </div>
                      ))}
                      {visibleStickers.length === 0 ? <p className="muted-text">Nenhuma figurinha disponível ainda.</p> : null}
                    </div>
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
                      <Button onClick={() => onOpenOrder(linkedOrder.id)} variant="secondary">
                        Abrir pedido
                      </Button>
                      <Button icon="printer" onClick={() => onPreviewTicket(linkedOrder.id)} variant="secondary">
                        Visualizar comanda
                      </Button>
                      {linkedOrder.backendStatus === 'in_preparation' ? (
                        <Button disabled={isActionBusy} onClick={() => void onAdvanceOrder(linkedOrder.id, 'ready')}>
                          Marcar como pronto
                        </Button>
                      ) : null}
                      {linkedOrder.backendStatus === 'ready_for_pickup' && linkedOrder.fulfillmentType === 'entrega' ? (
                        <Button disabled={isActionBusy} onClick={() => void onAdvanceOrder(linkedOrder.id, 'start-delivery')}>
                          Iniciar entrega
                        </Button>
                      ) : null}
                      {linkedOrder.backendStatus === 'ready_for_pickup' && linkedOrder.fulfillmentType === 'retirada' ? (
                        <Button disabled={isActionBusy} onClick={() => void onAdvanceOrder(linkedOrder.id, 'picked-up')}>
                          Marcar como retirado
                        </Button>
                      ) : null}
                      {linkedOrder.backendStatus === 'out_for_delivery' ? (
                        <Button disabled={isActionBusy} onClick={() => void onAdvanceOrder(linkedOrder.id, 'delivered')}>
                          Marcar como entregue
                        </Button>
                      ) : null}
                    </div>
                  </div>
                ) : (
                  <div className="conversation-context-empty-order">
                    <EmptyState description="Crie um pedido para este cliente sem cadastrá-lo novamente." title="Nenhum pedido ativo" />
                    <Button disabled={isActionBusy} onClick={() => void onCreateOrder(selectedConversation.id)}>
                      Criar pedido
                    </Button>
                  </div>
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
                <h3>Proposta do Copiloto</h3>
                {!visibleCopilotAnalysis ? (
                  <>
                    <p>Analise a conversa para identificar intencao, pedido e informacoes pendentes.</p>
                    <Button disabled={isAnalyzingCopilot} onClick={() => void handleAnalyzeCopilot()} variant="secondary">
                      {isAnalyzingCopilot ? 'Analisando...' : 'Analisar conversa'}
                    </Button>
                  </>
                ) : (
                  <div className="conversation-copilot-result">
                    <strong>{visibleCopilotAnalysis.proposal ? copilotApplyabilityLabel(visibleCopilotAnalysis.proposal.applyability) : visibleCopilotAnalysis.intent}</strong>
                    {(visibleCopilotAnalysis.proposal?.items ?? visibleCopilotAnalysis.draft_order.items).length ? <div className="conversation-copilot-result__section">
                      <span>Resumo</span>
                      {(visibleCopilotAnalysis.proposal?.items ?? visibleCopilotAnalysis.draft_order.items).map((item, index) => <p key={`${item.menu_item_slug}-${index}`}>{item.quantity}x {'product_name' in item ? item.product_name : item.menu_item_slug}{proposalSelectionsLabel(item.selections)}{item.removed_components.length ? ` - Retirar: ${item.removed_components.join(', ')}` : ''}{item.item_notes ? ` - Obs: ${item.item_notes}` : ''}</p>)}
                    </div> : null}
                    {(visibleCopilotAnalysis.proposal?.missing_information ?? visibleCopilotAnalysis.missing_information).length ? <div className="conversation-copilot-result__section conversation-copilot-result__section--missing"><span>Pendências</span>{(visibleCopilotAnalysis.proposal?.missing_information ?? visibleCopilotAnalysis.missing_information).map((missing) => <small key={missing.code}>{missing.message ?? `Falta: ${missing.label}`}</small>)}</div> : null}
                    {(visibleCopilotAnalysis.proposal?.warnings ?? visibleCopilotAnalysis.warnings).length ? <div className="conversation-copilot-result__section conversation-copilot-result__section--warning"><span>Atenção</span>{(visibleCopilotAnalysis.proposal?.warnings ?? visibleCopilotAnalysis.warnings).map((warning) => <small key={`${warning.code}-${warning.message}`}>{warning.message}</small>)}</div> : null}
                    {visibleCopilotAnalysis.proposal?.blocking_reasons.length ? <div className="conversation-copilot-result__section conversation-copilot-result__section--warning"><span>Revisão necessária</span>{visibleCopilotAnalysis.proposal.blocking_reasons.map((reason) => <small key={reason}>{reason}</small>)}</div> : null}
                    {visibleCopilotAnalysis.proposal?.can_apply ? <Button disabled={isActionBusy} onClick={handleApplyCopilotProposal} variant="primary">Aplicar ao rascunho</Button> : null}
                    {copilotProposalApplied ? <small>Proposta aplicada ao rascunho. Revise antes de salvar.</small> : null}
                    {visibleCopilotAnalysis.suggested_reply ? <div className="conversation-copilot-result__section conversation-copilot-result__section--reply"><span>Resposta sugerida</span><p>{visibleCopilotAnalysis.suggested_reply}</p><Button onClick={handleUseCopilotReply} variant="secondary">Usar resposta</Button></div> : null}
                    <Button disabled={isAnalyzingCopilot} onClick={() => void handleAnalyzeCopilot()} variant="ghost">Analisar novamente</Button>
                  </div>
                )}
              </div>

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
        <Modal onClose={closeCamera} open={cameraState !== 'closed'} size="md" title="Câmera">
          {cameraState === 'requesting' ? <p>Preparando câmera...</p> : null}
          {cameraState === 'ready' ? <video autoPlay className="conversation-camera-preview" muted playsInline ref={cameraVideoRef} /> : null}
          {cameraState === 'preview' && cameraPreview ? <img alt="Prévia da foto" className="conversation-camera-preview" src={cameraPreview} /> : null}
          {cameraState === 'failed' ? <p className="composer__error">Não foi possível abrir a câmera.</p> : null}
          <div className="conversation-camera-actions">
            {cameraState === 'ready' ? <Button disabled={!cameraVideoReady || !cameraStreamActive} onClick={captureCameraPhoto}>Capturar</Button> : null}
            {cameraState === 'preview' ? <Button onClick={closeCamera}>Usar foto</Button> : null}
            {cameraState === 'preview' ? <Button onClick={() => { closeCamera(); setCameraState('requesting'); void openCamera() }} variant="secondary">Tirar novamente</Button> : null}
            <Button onClick={closeCamera} variant="secondary">Cancelar</Button>
          </div>
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

function copilotApplyabilityLabel(applyability: CopilotOrderProposal['applyability']): string {
  return applyability === 'READY' ? 'Pronta para revisar' : applyability === 'PARTIAL' ? 'Parcial - revisar pendencias' : 'Revisao manual necessaria'
}

function proposalSelectionsLabel(selections: Record<string, unknown> | undefined): string {
  if (!selections) return ''

  const values = Object.entries(selections)
    .filter(([key, value]) => !['meat_mode', 'extra_beef'].includes(key) && value !== null && value !== '' && (!Array.isArray(value) || value.length > 0))
    .flatMap(([, value]) => Array.isArray(value) ? value : [value])
    .filter((value): value is string => typeof value === 'string')

  if ((selections.meat_mode ?? '') === 'beef_only') values.push('Somente bife')
  if ((selections.meat_mode ?? '') === 'none') values.push('Sem carne')
  if (Number(selections.extra_beef ?? 0) > 0) values.push('Bife adicional')

  return values.length ? ` - ${values.join(', ')}` : ''
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
  onDragEnter,
  onDragLeave,
  onDragOver,
  onDrop,
  onReply,
  onRetryMessage,
  onReactMessage,
  onTogglePin,
  onCopyMessage,
}: {
  conversationId: string
  messages: ConversationMessage[]
  onDragEnter: (event: DragEvent<HTMLDivElement>) => void
  onDragLeave: (event: DragEvent<HTMLDivElement>) => void
  onDragOver: (event: DragEvent<HTMLDivElement>) => void
  onDrop: (event: DragEvent<HTMLDivElement>) => void
  onReply: (message: ConversationMessage) => void
  onRetryMessage: (messageId: string) => void
  onReactMessage: (messageId: string, emoji: string) => void
  onTogglePin: (messageId: string) => Promise<void>
  onCopyMessage: (body: string) => void
}) {
  const listRef = useRef<HTMLDivElement>(null)
  const lastConversationRef = useRef<string | null>(null)
  const [isNearBottom, setIsNearBottom] = useState(true)
  const [newMessageCount, setNewMessageCount] = useState(0)
  const previousMessageCountRef = useRef(messages.length)

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

  useEffect(() => {
    const previousCount = previousMessageCountRef.current
    if (messages.length > previousCount && !isNearBottom) {
      setNewMessageCount((count) => count + messages.length - previousCount)
    } else if (isNearBottom) {
      setNewMessageCount(0)
    }
    previousMessageCountRef.current = messages.length
  }, [isNearBottom, messages.length])

  let lastDateKey = ''

  return (
    <div className="message-list-shell">
      <div
        className="message-list"
      onDragEnter={onDragEnter}
      onDragLeave={onDragLeave}
      onDragOver={onDragOver}
      onDrop={onDrop}
      onScroll={(event) => {
        const node = event.currentTarget
          const nearBottom = node.scrollHeight - node.scrollTop - node.clientHeight < 96
          setIsNearBottom(nearBottom)
          if (nearBottom) setNewMessageCount(0)
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
                onReact={(emoji) => onReactMessage(message.id, emoji)}
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
      {!isNearBottom && messages.length > 0 ? (
        <button
          aria-label="Voltar para a última mensagem"
          className="message-list__jump-bottom"
          onClick={() => {
            listRef.current?.scrollTo({ top: listRef.current.scrollHeight, behavior: 'smooth' })
            setNewMessageCount(0)
          }}
          type="button"
        >
          <Icon name="chevron-down" size={18} />
          {newMessageCount > 0 ? <span className="message-list__jump-count">{newMessageCount > 99 ? '99+' : newMessageCount}</span> : null}
        </button>
      ) : null}
    </div>
  )
}

function MessageBubble({ boundaryRef, conversationId, message, onReply, onRetry, onReact, onTogglePin, onCopyMessage }: {
  boundaryRef: RefObject<HTMLDivElement | null>
  conversationId: string
  message: ConversationMessage
  onReply: () => void
  onRetry: () => void
  onReact: (emoji: string) => void
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
  const body = message.body ?? ''
  const hasText = body.trim() !== ''
  const downloadableMedia = message.media?.filter((media) => Boolean(media.url)) ?? []

  const positionContextMenu = useCallback(() => {
    if (!isMenuOpen || !menuTriggerRef.current || !bubbleRef.current) {
      return undefined
    }

    const boundary = boundaryRef.current?.getBoundingClientRect()
    const trigger = menuTriggerRef.current.getBoundingClientRect()
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
    const spaceRight = viewport.right - trigger.right
    const spaceLeft = trigger.left - viewport.left
    const left = spaceRight >= menuWidth + padding
      ? trigger.right + 6
      : spaceLeft >= menuWidth + padding
        ? trigger.left - menuWidth - 6
        : Math.min(
          Math.max(viewport.left + padding, trigger.right - menuWidth),
          viewport.right - menuWidth - padding,
        )
    const top = viewport.bottom - trigger.bottom >= totalHeight + padding
      ? trigger.bottom + 6
      : trigger.top - viewport.top >= totalHeight + padding
        ? trigger.top - totalHeight - 6
        : Math.min(
          Math.max(viewport.top + padding, trigger.bottom - totalHeight),
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
      await navigator.clipboard.writeText(body)
    } catch {
      const textarea = document.createElement('textarea')
      textarea.value = body
      textarea.style.position = 'fixed'
      textarea.style.opacity = '0'
      document.body.appendChild(textarea)
      textarea.select()
      document.execCommand('copy')
      textarea.remove()
    }

    onCopyMessage(body)
    setIsMenuOpen(false)
  }

  function downloadUrl(mediaId: string): string {
    return `/api/app/conversations/${conversationId}/media/${mediaId}/download`
  }

  return (
    <>
    <div
      className={`message-bubble message-bubble--${message.sender} ${failed ? 'is-failed' : ''}`}
      id={`message-${message.id}`}
      ref={bubbleRef}
      onContextMenu={(event) => {
        event.preventDefault()
        setIsMenuOpen(true)
      }}
    >
      <button
        aria-expanded={isMenuOpen}
        aria-label="Ações da mensagem"
        className="message-bubble__menu-trigger"
        onClick={() => setIsMenuOpen((current) => !current)}
        ref={menuTriggerRef}
        type="button"
      >
        <Icon name="chevron-down" size={14} />
      </button>
      <div className="message-bubble__meta">
        <time>{message.timeLabel}</time>
      </div>
      {message.replyTo ? (
        <div className="message-bubble__quote">
          <strong>{quotedSenderLabel(message.replyTo.sender)}</strong>
          <span>{quotedMessageLabel(message.replyTo)}</span>
        </div>
      ) : null}
      <MessageContentRenderer body={body} message={message} />
      {message.reactions && message.reactions.length > 0 ? (
        <div className="message-bubble__reactions" aria-label="Reações da mensagem">
          {message.reactions.map((reaction) => <span key={`${reaction.source}-${reaction.emoji}`}>{reaction.emoji}</span>)}
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
              onClick={() => { setSelectedReaction(reaction); onReact(reaction) }}
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
                  onClick={() => { setSelectedReaction(reaction); onReact(reaction) }}
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

function pinnedMessageLabel(message: ConversationMessage): string {
  const media = message.media?.[0]
  if (media?.type === 'audio') return 'Mensagem de voz'
  if (media?.type === 'image') return 'Foto'
  if (media?.type === 'video') return 'Vídeo'
  if (media?.type === 'sticker') return 'Figurinha'
  if (media?.type === 'document') return media.name || 'Documento'
  return message.body?.trim() || messageTypeLabel(message.type)
}

function MessageContentRenderer({ body, message }: { body: string; message: ConversationMessage }) {
  const media = message.media ?? []

  if (message.isRevoked) {
    return <p className="message-revoked">Esta mensagem foi apagada</p>
  }

  return (
    <>
      {body ? <p>{body}</p> : media.length === 0 ? <p className="muted-text">{messageTypeLabel(message.type)}</p> : null}
      {media.length > 0 ? (
        <div className="message-media-list">
          {media.map((item) => <MessageMediaRenderer key={item.id} media={item} />)}
        </div>
      ) : null}
    </>
  )
}

function MessageMediaRenderer({ media }: { media: NonNullable<ConversationMessage['media']>[number] }) {
  const isImage = media.mimeType?.startsWith('image/') ?? false
  const isAudio = media.mimeType?.startsWith('audio/') ?? false
  const isVideo = media.mimeType?.startsWith('video/') ?? false
  const isSticker = media.type === 'sticker'

  if (isSticker && media.url) {
    return (
      <a className="message-media message-media--sticker" href={media.url} rel="noreferrer" target="_blank">
        <img alt="Figurinha" src={media.url} />
      </a>
    )
  }

  if (isImage && media.url) {
    return (
      <a className="message-media message-media--image" href={media.url} rel="noreferrer" target="_blank">
        <img alt="Imagem recebida" src={media.url} />
      </a>
    )
  }

  if (isAudio && media.url) {
    return (
      <div className="message-media message-media--audio">
        {media.isVoiceNote ? <VoiceMessagePlayer src={media.url} /> : <span>{media.name}</span>}
        {!media.isVoiceNote ? <audio controls src={media.url}>
          Seu navegador não suporta áudio.
        </audio> : null}
      </div>
    )
  }

  if (isVideo && media.url) {
    return (
      <div className="message-media message-media--video">
        <video controls preload="metadata" src={media.url}>
          Seu navegador não suporta vídeo.
        </video>
        <span>{media.name}</span>
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

function VoiceMessagePlayer({ src }: { src: string }) {
  const audioRef = useRef<HTMLAudioElement>(null)
  const [playing, setPlaying] = useState(false)
  const [currentTime, setCurrentTime] = useState(0)
  const [duration, setDuration] = useState<number | null>(null)

  function toggle() {
    const audio = audioRef.current
    if (!audio) return
    if (audio.paused) {
      void audio.play().then(() => setPlaying(true)).catch(() => setPlaying(false))
    } else {
      audio.pause()
      setPlaying(false)
    }
  }

  return (
    <div className="voice-message-player">
      <button aria-label={playing ? 'Pausar mensagem de voz' : 'Reproduzir mensagem de voz'} onClick={toggle} type="button">
        {playing ? '❚❚' : '▶'}
      </button>
      <div className="voice-message-player__track">
        <span style={{ width: `${mediaProgress(currentTime, duration) * 100}%` }} />
      </div>
      <small>{formatMediaTime(currentTime)} / {formatMediaTime(duration)}</small>
      <audio
        aria-hidden="true"
        onCanPlay={(event) => updateMediaDuration(event.currentTarget.duration, setDuration)}
        onDurationChange={(event) => updateMediaDuration(event.currentTarget.duration, setDuration)}
        onEnded={() => { setPlaying(false); setCurrentTime(0) }}
        onLoadedMetadata={(event) => updateMediaDuration(event.currentTarget.duration, setDuration)}
        onPause={() => setPlaying(false)}
        onPlay={() => setPlaying(true)}
        onTimeUpdate={(event) => setCurrentTime(safeMediaNumber(event.currentTarget.currentTime))}
        ref={audioRef}
        src={src}
      />
    </div>
  )
}

function safeMediaNumber(value: number | null | undefined): number {
  return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value : 0
}

function formatMediaTime(value: number | null | undefined): string {
  const seconds = safeMediaNumber(value)
  const minutes = Math.floor(seconds / 60)
  const remainder = Math.floor(seconds % 60)
  return `${minutes}:${remainder.toString().padStart(2, '0')}`
}

function mediaProgress(currentTime: number, duration: number | null): number {
  const safeDuration = safeMediaNumber(duration)
  if (safeDuration === 0) return 0
  return Math.min(1, safeMediaNumber(currentTime) / safeDuration)
}

function updateMediaDuration(value: number, setDuration: (value: number | null) => void): void {
  setDuration(Number.isFinite(value) && value > 0 ? value : null)
}

function mediaTypeLabel(type: 'image' | 'video' | 'document' | 'audio' | 'sticker'): string {
  const labels: Record<'image' | 'video' | 'document' | 'audio' | 'sticker', string> = {
    image: 'Imagem', video: 'Vídeo', document: 'Documento', audio: 'Áudio', sticker: 'Figurinha',
  }
  return labels[type]
}

function formatRecordingDuration(seconds: number): string {
  const minutes = Math.floor(seconds / 60)
  const remainingSeconds = seconds % 60
  return `${minutes}:${remainingSeconds.toString().padStart(2, '0')}`
}

function isManualConversation(conversation: Conversation): boolean {
  return conversation.automationMode === 'manual' || conversation.mode === 'manual'
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

function operationalStatusFor(conversation: Conversation): ConversationOperationalStatus {
  if (conversation.operationalStatus) {
    return conversation.operationalStatus
  }

  if (conversation.activeOrder) {
    return {
      code: 'IN_SERVICE',
      label: 'Atendimento em andamento',
      tone: 'blue',
      reason: null,
      priority: 20,
    }
  }

  return {
    code: 'IDLE',
    label: 'Sem pedido em andamento',
    tone: 'gray',
    reason: null,
    priority: 0,
  }
}

function StatusDot({ status }: { status: ConversationOperationalStatus }) {
  const accessibleLabel = status.reason ? `${status.label}: ${status.reason}` : status.label

  return (
    <span
      aria-label={`Status operacional: ${accessibleLabel}`}
      className={`conversation-status-dot conversation-status-dot--${status.tone}`}
      role="img"
      title={accessibleLabel}
    />
  )
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
  if ((message.body ?? '').trim() !== '') {
    return message.body ?? ''
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

function inferAttachmentType(file: File): 'image' | 'video' | 'document' | 'audio' | 'sticker' | null {
  if (file.type === 'image/webp') return 'sticker'
  if (file.type.startsWith('image/')) return 'image'
  if (file.type.startsWith('video/')) return 'video'
  if (file.type.startsWith('audio/')) return 'audio'
  if (['application/pdf', 'text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'].includes(file.type)) return 'document'
  return null
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
