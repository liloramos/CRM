import { useEffect, useMemo, useRef, useState } from 'react'
import { Icon } from '../ui/Icon'
import type { OperationalNotification, RouteKey } from '../../types/crm'
import './NotificationCenter.css'

const SOUND_PREFERENCE_KEY = 'chatbotcrm.notifications.sound-enabled'
const SEEN_NOTIFICATIONS_KEY = 'chatbotcrm.notifications.seen-ids'
const DISMISSED_NOTIFICATIONS_KEY = 'chatbotcrm.notifications.dismissed-ids'

type NotificationCenterProps = {
  activeRoute: RouteKey
  hydrated: boolean
  notifications: OperationalNotification[]
  storageScope: string
  onOpenConversation: (conversationId: string) => void
  onOpenOrder: (orderId: string) => void
  onOpenPayments: () => void
}

export function NotificationCenter({
  activeRoute,
  hydrated,
  notifications,
  storageScope,
  onOpenConversation,
  onOpenOrder,
  onOpenPayments,
}: NotificationCenterProps) {
  const [isOpen, setIsOpen] = useState(false)
  const [soundEnabled, setSoundEnabled] = useState(readSoundPreference)
  const [actionStatus, setActionStatus] = useState<string | null>(null)
  const [unseenIds, setUnseenIds] = useState<Set<string>>(new Set())
  const [dismissedIds, setDismissedIds] = useState(() => readDismissedIds(storageScope))
  const knownIdsRef = useRef<Set<string> | null>(null)
  const seenIdsRef = useRef(readSeenIds(storageScope))
  const audioContextRef = useRef<AudioContext | null>(null)
  const audioUnlockedRef = useRef(false)
  const containerRef = useRef<HTMLDivElement>(null)
  const visibleNotifications = useMemo(
    () => notifications.filter((notification) => !dismissedIds.has(notification.id)),
    [dismissedIds, notifications],
  )

  useEffect(() => {
    const unlock = () => {
      audioUnlockedRef.current = true
      getAudioContext(audioContextRef)?.resume().catch(() => undefined)
    }

    window.addEventListener('pointerdown', unlock, { once: true })
    window.addEventListener('keydown', unlock, { once: true })

    return () => {
      window.removeEventListener('pointerdown', unlock)
      window.removeEventListener('keydown', unlock)
    }
  }, [])

  useEffect(() => {
    if (!hydrated) return

    const currentIds = new Set(visibleNotifications.map((notification) => notification.id))

    if (knownIdsRef.current === null) {
      knownIdsRef.current = currentIds
      setUnseenIds(new Set([...currentIds].filter((id) => !seenIdsRef.current.has(id))))
      return
    }

    const newNotifications = visibleNotifications.filter((notification) => !knownIdsRef.current?.has(notification.id))
    visibleNotifications.forEach((notification) => knownIdsRef.current?.add(notification.id))
    setUnseenIds((current) => {
      const next = new Set([...current].filter((id) => currentIds.has(id)))
      newNotifications.forEach((notification) => {
        if (!seenIdsRef.current.has(notification.id)) next.add(notification.id)
      })
      return next
    })

    if (newNotifications.length > 0 && soundEnabled && audioUnlockedRef.current && document.visibilityState === 'visible') {
      playNotificationSound(getAudioContext(audioContextRef))
    }
  }, [hydrated, soundEnabled, visibleNotifications])

  useEffect(() => {
    if (!isOpen) return undefined

    const closeOutside = (event: MouseEvent) => {
      if (event.target instanceof Node && !containerRef.current?.contains(event.target)) setIsOpen(false)
    }
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setIsOpen(false)
    }

    document.addEventListener('mousedown', closeOutside)
    document.addEventListener('keydown', closeOnEscape)
    return () => {
      document.removeEventListener('mousedown', closeOutside)
      document.removeEventListener('keydown', closeOnEscape)
    }
  }, [isOpen])

  useEffect(() => {
    if (!actionStatus) return undefined
    const timeout = window.setTimeout(() => setActionStatus(null), 2400)
    return () => window.clearTimeout(timeout)
  }, [actionStatus])

  const recentNotifications = useMemo(() => visibleNotifications.slice(0, 20), [visibleNotifications])

  function markSeen(ids: string[]) {
    ids.forEach((id) => seenIdsRef.current.add(id))
    setUnseenIds((current) => {
      const next = new Set(current)
      ids.forEach((id) => next.delete(id))
      return next
    })
    writeSeenIds(storageScope, seenIdsRef.current)
  }

  function toggleCenter() {
    setIsOpen((current) => !current)
  }

  function openNotification(notification: OperationalNotification) {
    markSeen([notification.id])
    setIsOpen(false)
    if (notification.conversationId) onOpenConversation(notification.conversationId)
    else if (notification.orderId) onOpenOrder(notification.orderId)
    else if (notification.paymentId) onOpenPayments()
  }

  function toggleSound() {
    const next = !soundEnabled
    setSoundEnabled(next)
    writeSoundPreference(next)
    audioUnlockedRef.current = true
    if (next) playNotificationSound(getAudioContext(audioContextRef))
    setActionStatus(next ? 'Som das notificações ativado.' : 'Som das notificações desativado.')
  }

  function clearAll() {
    const next = new Set(dismissedIds)
    visibleNotifications.forEach((notification) => next.add(notification.id))
    markSeen(visibleNotifications.map((notification) => notification.id))
    setDismissedIds(next)
    writeDismissedIds(storageScope, next)
    setActionStatus('Notificações limpas neste dispositivo.')
  }

  return (
    <div className={`notification-center ${activeRoute === 'conversas' ? 'notification-center--conversation' : ''}`} ref={containerRef}>
      {isOpen ? (
        <section aria-label="Notificações recentes" className="notification-center__popover">
          <header>
            <div>
              <strong>Notificações</strong>
              <span>{unseenIds.size > 0 ? `${unseenIds.size} nova${unseenIds.size > 1 ? 's' : ''}` : 'Tudo visto'}</span>
            </div>
            <div className="notification-center__actions">
              {unseenIds.size > 0 ? <button className="notification-center__text-action" onClick={() => { markSeen(visibleNotifications.map((notification) => notification.id)); setActionStatus('Notificações marcadas como vistas.') }} type="button">Marcar vistas</button> : null}
              {visibleNotifications.length > 0 ? <button className="notification-center__text-action" onClick={clearAll} type="button">Limpar todas</button> : null}
              <button aria-label={soundEnabled ? 'Desativar som' : 'Ativar som'} className="notification-center__sound" onClick={toggleSound} title={soundEnabled ? 'Desativar som' : 'Ativar som'} type="button">
                <Icon name={soundEnabled ? 'sound' : 'sound-off'} size={17} />
              </button>
            </div>
          </header>
          {actionStatus ? <p className="notification-center__feedback" role="status">{actionStatus}</p> : null}
          <div className="notification-center__list">
            {recentNotifications.length > 0 ? recentNotifications.map((notification) => (
              <button className={`notification-center__item notification-center__item--${notification.severity}`} key={notification.id} onClick={() => openNotification(notification)} type="button">
                <span className="notification-center__item-icon"><Icon name={notification.kind === 'message' ? 'chat' : 'alert'} size={16} /></span>
                <span>
                  <strong>{notification.title}</strong>
                  <small>{notification.description}</small>
                  <time>{formatNotificationTime(notification.occurredAt)}</time>
                </span>
              </button>
            )) : <p>Nenhuma notificação nova.</p>}
          </div>
        </section>
      ) : null}
      <button aria-expanded={isOpen} aria-haspopup="dialog" aria-label="Abrir notificações" className="notification-center__trigger" onClick={toggleCenter} type="button">
        <Icon name="bell" size={20} />
        {unseenIds.size > 0 ? <span aria-label={`${unseenIds.size} notificações novas`}>{Math.min(unseenIds.size, 99)}</span> : null}
      </button>
    </div>
  )
}

function getAudioContext(ref: React.MutableRefObject<AudioContext | null>): AudioContext {
  ref.current ??= new AudioContext()
  return ref.current
}

function playNotificationSound(context: AudioContext) {
  void context.resume().then(() => {
    const oscillator = context.createOscillator()
    const gain = context.createGain()
    oscillator.type = 'sine'
    oscillator.frequency.setValueAtTime(720, context.currentTime)
    oscillator.frequency.exponentialRampToValueAtTime(920, context.currentTime + 0.12)
    gain.gain.setValueAtTime(0.0001, context.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.08, context.currentTime + 0.015)
    gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.18)
    oscillator.connect(gain)
    gain.connect(context.destination)
    oscillator.start()
    oscillator.stop(context.currentTime + 0.2)
  }).catch(() => undefined)
}

function readSoundPreference(): boolean {
  try {
    return window.localStorage.getItem(SOUND_PREFERENCE_KEY) !== 'false'
  } catch {
    return true
  }
}

function writeSoundPreference(enabled: boolean) {
  try {
    window.localStorage.setItem(SOUND_PREFERENCE_KEY, String(enabled))
  } catch {
    // Restricted storage must not block operational notifications.
  }
}

function readSeenIds(scope: string): Set<string> {
  try {
    const value = JSON.parse(window.localStorage.getItem(`${SEEN_NOTIFICATIONS_KEY}:${scope}`) ?? '[]')
    return new Set(Array.isArray(value) ? value.filter((id): id is string => typeof id === 'string') : [])
  } catch {
    return new Set()
  }
}

function writeSeenIds(scope: string, ids: Set<string>) {
  try {
    window.localStorage.setItem(`${SEEN_NOTIFICATIONS_KEY}:${scope}`, JSON.stringify([...ids].slice(-200)))
  } catch {
    // Seen state remains valid for the current session when storage is restricted.
  }
}

function readDismissedIds(scope: string): Set<string> {
  try {
    const value = JSON.parse(window.localStorage.getItem(`${DISMISSED_NOTIFICATIONS_KEY}:${scope}`) ?? '[]')
    return new Set(Array.isArray(value) ? value.filter((id): id is string => typeof id === 'string') : [])
  } catch {
    return new Set()
  }
}

function writeDismissedIds(scope: string, ids: Set<string>) {
  try {
    window.localStorage.setItem(`${DISMISSED_NOTIFICATIONS_KEY}:${scope}`, JSON.stringify([...ids].slice(-300)))
  } catch {
    // Dismissed state remains valid for the current session when storage is restricted.
  }
}

function formatNotificationTime(value: string | null): string {
  if (!value) return ''
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' }).format(date)
}
