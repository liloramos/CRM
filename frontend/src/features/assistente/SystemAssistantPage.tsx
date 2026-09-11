import { useEffect, useRef, useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { askSystemAssistant } from '../../services/crm.service'
import type { RouteKey, SystemAssistantAction } from '../../types/crm'
import {
  clearSystemAssistantHistory,
  loadSystemAssistantHistory,
  saveSystemAssistantHistory,
  type SystemAssistantHistoryEntry,
} from './systemAssistantHistory'

const suggestions = ['Como altero o cardápio de amanhã?', 'Onde confirmo um Pix?', 'Como vejo as entregas de hoje?', 'Como encontro um cliente?']
const safeFailure = 'A Labia não conseguiu concluir essa consulta. Diga qual tarefa você quer realizar ou qual tela está usando.'
const createEntryId = () => window.crypto.randomUUID()

type SystemAssistantPageProps = {
  companyId: string
  currentRoute: RouteKey
  onNavigate: (route: RouteKey) => void
  onOpenOrder: (id: string) => void
  onOpenCustomer: (id: string) => void
  userId: string
}

export function SystemAssistantPage({ companyId, currentRoute, onNavigate, onOpenOrder, onOpenCustomer, userId }: SystemAssistantPageProps) {
  const [message, setMessage] = useState('')
  const [entries, setEntries] = useState<SystemAssistantHistoryEntry[]>(() => loadSystemAssistantHistory(companyId, userId))
  const [busy, setBusy] = useState(false)
  const historyRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const nearBottomRef = useRef(true)

  useEffect(() => {
    if (!nearBottomRef.current) return
    const frame = window.requestAnimationFrame(() => historyRef.current?.scrollTo({ top: historyRef.current.scrollHeight, behavior: 'smooth' }))
    return () => window.cancelAnimationFrame(frame)
  }, [busy, entries])

  const append = (...nextEntries: SystemAssistantHistoryEntry[]) => {
    setEntries((current) => saveSystemAssistantHistory(companyId, userId, [...current, ...nextEntries]))
  }

  const send = async (value = message) => {
    const question = value.trim()
    if (!question || busy) return
    const recentHistory = entries.slice(-6).map(({ role, text }) => ({ role, text }))
    append({ id: createEntryId(), role: 'user', text: question, action: null })
    setMessage('')
    setBusy(true)

    try {
      const response = await askSystemAssistant(question, currentRoute, recentHistory)
      append({ id: createEntryId(), role: 'assistant', text: response.answer, action: response.action })
    } catch {
      append({ id: createEntryId(), role: 'assistant', text: safeFailure, action: null, state: 'error' })
    } finally {
      setBusy(false)
    }
  }

  const execute = (action: SystemAssistantAction) => {
    if (action.type === 'open_order' && action.parameters.order_id) return onOpenOrder(action.parameters.order_id)
    if (action.type === 'open_customer' && action.parameters.customer_id) return onOpenCustomer(action.parameters.customer_id)
    onNavigate(action.target)
  }

  const actionLabel = (action: SystemAssistantAction) => action.type === 'open_order' || action.type === 'open_customer'
    ? action.label
    : `Ir para ${action.label}`

  const clear = () => {
    if (!window.confirm('Limpar esta conversa da Labia?')) return
    clearSystemAssistantHistory(companyId, userId)
    setEntries([])
    nearBottomRef.current = true
    window.requestAnimationFrame(() => inputRef.current?.focus())
  }

  return (
    <main className="general-settings-page system-assistant-page">
      <header><h1>Labia</h1><p>Pergunte como usar o sistema ou encontre rapidamente uma funcionalidade.</p></header>
      <Card className="system-assistant-card">
        <div className="system-assistant-card__header">
          <div className="system-assistant-intro"><span aria-hidden="true">✦</span><div><strong>Sou a Labia</strong><p>Assistente do CRM do Restaurante Sol. Posso orientar sobre áreas disponíveis para o seu perfil e abrir páginas permitidas.</p></div></div>
          {entries.length ? <Button className="system-assistant-clear" onClick={clear} size="sm" variant="ghost">Limpar conversa</Button> : null}
        </div>
        <div className="system-assistant-suggestions">{suggestions.map((suggestion) => <Button key={suggestion} onClick={() => void send(suggestion)} size="sm" variant="ghost">{suggestion}</Button>)}</div>
        <div
          className="system-assistant-history"
          onScroll={(event) => {
            const element = event.currentTarget
            nearBottomRef.current = element.scrollHeight - element.scrollTop - element.clientHeight <= 80
          }}
          ref={historyRef}
          role="log"
        >
          {entries.length === 0 ? <p className="system-assistant-empty">Escolha uma sugestão ou escreva sua pergunta.</p> : null}
          {entries.map((entry) => <div className={`system-assistant-message is-${entry.role}${entry.state === 'error' ? ' is-error' : ''}`} key={entry.id}><span className="system-assistant-message__role">{entry.role === 'user' ? 'Você' : 'Labia'}</span><p>{entry.text}</p>{entry.action ? <Button onClick={() => execute(entry.action!)} size="sm" variant="secondary">{actionLabel(entry.action)}</Button> : null}</div>)}
          {busy ? <div className="system-assistant-message is-assistant"><span className="system-assistant-message__role">Labia</span><p>A Labia está consultando...</p></div> : null}
        </div>
        <form className="system-assistant-form" onSubmit={(event) => { event.preventDefault(); void send() }}><input aria-label="Pergunte como fazer alguma coisa" maxLength={1200} onChange={(event) => setMessage(event.target.value)} placeholder="Pergunte como fazer alguma coisa..." ref={inputRef} value={message} /><Button disabled={busy || !message.trim()} type="submit" variant="primary">Enviar</Button></form>
      </Card>
    </main>
  )
}
