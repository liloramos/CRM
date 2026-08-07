import { useEffect, useState, type FormEvent } from 'react'
import { Button } from '../../components/ui/Button'
import { Modal } from '../../components/ui/Modal'
import { SelectField } from '../../components/ui/SelectField'
import {
  createConversationQuickReply,
  getConversationAiStyle,
  getConversationQuickReplies,
  updateConversationAiStyle,
  updateConversationQuickReply,
  type ConversationQuickReplyPayload,
} from '../../services/crm.service'
import type {
  ConversationAiStyle,
  ConversationQuickReply,
  ConversationQuickReplyCategory,
} from '../../types/crm'
import { quickReplyCategoryLabel } from './conversationLabels'

type ConfigurationTab = 'quick-replies' | 'ai-style'

type ConversationConfigurationModalProps = {
  open: boolean
  onClose: () => void
  onQuickRepliesChanged: (quickReplies: ConversationQuickReply[]) => void
}

const categoryOptions: Array<{ value: ConversationQuickReplyCategory; label: string }> = [
  { value: 'greeting', label: 'Saudação' },
  { value: 'menu', label: 'Cardápio' },
  { value: 'order', label: 'Pedido' },
  { value: 'address', label: 'Endereço' },
  { value: 'payment', label: 'Pagamento' },
  { value: 'payment_proof', label: 'Comprovante' },
  { value: 'unavailable_product', label: 'Produto indisponível' },
  { value: 'human_support', label: 'Atendimento humano' },
  { value: 'closing', label: 'Encerramento' },
]

const emptyQuickReply: ConversationQuickReplyPayload = {
  title: '',
  shortcut: '',
  body: '',
  category: 'greeting',
  is_active: true,
  display_order: 0,
}

const defaultAiStyle: ConversationAiStyle = {
  establishment_name: '',
  preferred_greeting: '',
  tone: 'warm',
  formality: 'balanced',
  emoji_usage: 'light',
  preferred_words: [],
  forbidden_words: [],
  human_transfer_message: '',
  payment_proof_received_message: '',
  closing_message: '',
}

export function ConversationConfigurationModal({
  onClose,
  onQuickRepliesChanged,
  open,
}: ConversationConfigurationModalProps) {
  const [activeTab, setActiveTab] = useState<ConfigurationTab>('quick-replies')
  const [quickReplies, setQuickReplies] = useState<ConversationQuickReply[]>([])
  const [editingId, setEditingId] = useState<string | null>(null)
  const [quickReplyDraft, setQuickReplyDraft] = useState<ConversationQuickReplyPayload>(emptyQuickReply)
  const [aiStyle, setAiStyle] = useState<ConversationAiStyle>(defaultAiStyle)
  const [preferredWords, setPreferredWords] = useState('')
  const [forbiddenWords, setForbiddenWords] = useState('')
  const [isLoading, setIsLoading] = useState(false)
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  useEffect(() => {
    if (!open) {
      return
    }

    let active = true

    void Promise.resolve()
      .then(() => {
        if (active) {
          setIsLoading(true)
          setError(null)
        }

        return Promise.all([getConversationQuickReplies(true), getConversationAiStyle()])
      })
      .then(([replies, style]) => {
        if (!active) {
          return
        }

        setQuickReplies(replies)
        setAiStyle(style)
        setPreferredWords(style.preferred_words.join(', '))
        setForbiddenWords(style.forbidden_words.join(', '))
      })
      .catch((loadError) => {
        if (active) {
          setError(loadError instanceof Error ? loadError.message : 'Não foi possível carregar as configurações.')
        }
      })
      .finally(() => {
        if (active) {
          setIsLoading(false)
        }
      })

    return () => {
      active = false
    }
  }, [open])

  async function handleSaveQuickReply(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)
    setNotice(null)
    setIsSaving(true)

    try {
      const saved = editingId
        ? await updateConversationQuickReply(editingId, quickReplyDraft)
        : await createConversationQuickReply(quickReplyDraft)
      const next = sortQuickReplies([
        ...quickReplies.filter((reply) => reply.id !== saved.id),
        saved,
      ])
      setQuickReplies(next)
      onQuickRepliesChanged(next.filter((reply) => reply.isActive))
      resetQuickReplyForm()
      setNotice(editingId ? 'Resposta rápida atualizada.' : 'Resposta rápida cadastrada.')
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : 'Não foi possível salvar a resposta rápida.')
    } finally {
      setIsSaving(false)
    }
  }

  async function handleToggleQuickReply(reply: ConversationQuickReply) {
    setError(null)
    setNotice(null)
    setIsSaving(true)

    try {
      const saved = await updateConversationQuickReply(reply.id, quickReplyPayload(reply, !reply.isActive))
      const next = sortQuickReplies(quickReplies.map((candidate) => (candidate.id === saved.id ? saved : candidate)))
      setQuickReplies(next)
      onQuickRepliesChanged(next.filter((candidate) => candidate.isActive))
      setNotice(saved.isActive ? 'Resposta rápida ativada.' : 'Resposta rápida inativada.')
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : 'Não foi possível alterar a resposta rápida.')
    } finally {
      setIsSaving(false)
    }
  }

  async function handleSaveAiStyle(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)
    setNotice(null)
    setIsSaving(true)

    try {
      const saved = await updateConversationAiStyle({
        ...aiStyle,
        preferred_words: parseWordList(preferredWords),
        forbidden_words: parseWordList(forbiddenWords),
      })
      setAiStyle(saved)
      setPreferredWords(saved.preferred_words.join(', '))
      setForbiddenWords(saved.forbidden_words.join(', '))
      setNotice('Estilo de atendimento atualizado.')
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : 'Não foi possível salvar o estilo de atendimento.')
    } finally {
      setIsSaving(false)
    }
  }

  function startEditing(reply: ConversationQuickReply) {
    setEditingId(reply.id)
    setQuickReplyDraft(quickReplyPayload(reply, reply.isActive))
    setNotice(null)
    setError(null)
  }

  function resetQuickReplyForm() {
    setEditingId(null)
    setQuickReplyDraft(emptyQuickReply)
  }

  return (
    <Modal
      closeDisabled={isSaving}
      description="Cadastre textos aprovados e defina como o atendimento automático deve se comunicar."
      onClose={onClose}
      onPrimary={onClose}
      open={open}
      primaryLabel="Concluir"
      size="lg"
      title="Configuração do atendimento"
    >
      <div className="conversation-config">
        <div className="conversation-config__tabs" role="tablist" aria-label="Configurações de conversas">
          <button
            aria-selected={activeTab === 'quick-replies'}
            className={activeTab === 'quick-replies' ? 'is-active' : ''}
            onClick={() => setActiveTab('quick-replies')}
            role="tab"
            type="button"
          >
            Respostas rápidas
          </button>
          <button
            aria-selected={activeTab === 'ai-style'}
            className={activeTab === 'ai-style' ? 'is-active' : ''}
            onClick={() => setActiveTab('ai-style')}
            role="tab"
            type="button"
          >
            Estilo do automático
          </button>
        </div>

        {error ? <p className="form-error" role="alert">{error}</p> : null}
        {notice ? <p className="form-notice" role="status">{notice}</p> : null}
        {isLoading ? <p className="muted-text">Carregando configurações...</p> : null}

        {!isLoading && activeTab === 'quick-replies' ? (
          <div className="conversation-config__layout">
            <section className="quick-reply-admin-list" aria-label="Respostas rápidas cadastradas">
              <div className="conversation-config__section-heading">
                <div>
                  <h3>Biblioteca da equipe</h3>
                  <p>O texto é inserido no composer e pode ser editado antes do envio.</p>
                </div>
                <Button onClick={resetQuickReplyForm} size="sm" variant="secondary">Nova resposta</Button>
              </div>
              {quickReplies.length === 0 ? <p className="muted-text">Nenhuma resposta rápida cadastrada.</p> : null}
              {quickReplies.map((reply) => (
                <article className={reply.isActive ? 'quick-reply-admin-item' : 'quick-reply-admin-item is-inactive'} key={reply.id}>
                  <div>
                    <strong>{reply.title}</strong>
                    <span>/{reply.shortcut} · {quickReplyCategoryLabel(reply.category)}</span>
                    <p>{reply.body}</p>
                  </div>
                  <div className="inline-actions">
                    <Button disabled={isSaving} onClick={() => startEditing(reply)} size="sm" variant="secondary">Editar</Button>
                    <Button disabled={isSaving} onClick={() => void handleToggleQuickReply(reply)} size="sm" variant="ghost">
                      {reply.isActive ? 'Inativar' : 'Ativar'}
                    </Button>
                  </div>
                </article>
              ))}
            </section>

            <form className="quick-reply-form" onSubmit={handleSaveQuickReply}>
              <h3>{editingId ? 'Editar resposta' : 'Cadastrar resposta'}</h3>
              <label>
                <span>Título</span>
                <input
                  disabled={isSaving}
                  maxLength={120}
                  onChange={(event) => setQuickReplyDraft((current) => ({ ...current, title: event.target.value }))}
                  required
                  value={quickReplyDraft.title}
                />
              </label>
              <label>
                <span>Atalho</span>
                <div className="quick-reply-shortcut-field">
                  <span>/</span>
                  <input
                    disabled={isSaving}
                    maxLength={60}
                    onChange={(event) => setQuickReplyDraft((current) => ({ ...current, shortcut: event.target.value }))}
                    pattern="[A-Za-z0-9_-]+"
                    required
                    value={quickReplyDraft.shortcut}
                  />
                </div>
              </label>
              <SelectField
                disabled={isSaving}
                label="Categoria"
                onChange={(value) => setQuickReplyDraft((current) => ({
                  ...current,
                  category: value as ConversationQuickReplyCategory,
                }))}
                options={categoryOptions}
                value={quickReplyDraft.category}
              />
              <label>
                <span>Mensagem</span>
                <textarea
                  disabled={isSaving}
                  maxLength={4000}
                  onChange={(event) => setQuickReplyDraft((current) => ({ ...current, body: event.target.value }))}
                  required
                  rows={5}
                  value={quickReplyDraft.body}
                />
              </label>
              <label>
                <span>Ordem</span>
                <input
                  disabled={isSaving}
                  min={0}
                  onChange={(event) => setQuickReplyDraft((current) => ({
                    ...current,
                    display_order: Number(event.target.value),
                  }))}
                  type="number"
                  value={quickReplyDraft.display_order}
                />
              </label>
              <label className="checkbox-row">
                <input
                  checked={quickReplyDraft.is_active}
                  disabled={isSaving}
                  onChange={(event) => setQuickReplyDraft((current) => ({ ...current, is_active: event.target.checked }))}
                  type="checkbox"
                />
                <span>Disponível para a equipe</span>
              </label>
              <div className="inline-actions">
                <Button disabled={isSaving} type="submit" variant="primary">{isSaving ? 'Salvando' : 'Salvar resposta'}</Button>
                {editingId ? <Button disabled={isSaving} onClick={resetQuickReplyForm} variant="ghost">Cancelar edição</Button> : null}
              </div>
            </form>
          </div>
        ) : null}

        {!isLoading && activeTab === 'ai-style' ? (
          <form className="ai-style-form" onSubmit={handleSaveAiStyle}>
            <div className="conversation-config__section-heading">
              <div>
                <h3>Estilo aprovado pela equipe</h3>
                <p>Essas orientações não autorizam confirmar pagamento, entrega ou preço fora do sistema.</p>
              </div>
            </div>
            <div className="form-grid form-grid--two">
              <label>
                <span>Nome do estabelecimento</span>
                <input onChange={(event) => setAiStyle((current) => ({ ...current, establishment_name: event.target.value }))} value={aiStyle.establishment_name} />
              </label>
              <label>
                <span>Saudação preferida</span>
                <input onChange={(event) => setAiStyle((current) => ({ ...current, preferred_greeting: event.target.value }))} value={aiStyle.preferred_greeting} />
              </label>
              <SelectField
                label="Tom"
                onChange={(value) => setAiStyle((current) => ({ ...current, tone: value as ConversationAiStyle['tone'] }))}
                options={[
                  { value: 'warm', label: 'Acolhedor' },
                  { value: 'direct', label: 'Direto' },
                  { value: 'casual', label: 'Casual' },
                  { value: 'professional', label: 'Profissional' },
                ]}
                value={aiStyle.tone}
              />
              <SelectField
                label="Formalidade"
                onChange={(value) => setAiStyle((current) => ({ ...current, formality: value as ConversationAiStyle['formality'] }))}
                options={[
                  { value: 'informal', label: 'Informal' },
                  { value: 'balanced', label: 'Equilibrada' },
                  { value: 'formal', label: 'Formal' },
                ]}
                value={aiStyle.formality}
              />
              <SelectField
                label="Uso de emojis"
                onChange={(value) => setAiStyle((current) => ({ ...current, emoji_usage: value as ConversationAiStyle['emoji_usage'] }))}
                options={[
                  { value: 'none', label: 'Não usar' },
                  { value: 'light', label: 'Uso leve' },
                  { value: 'moderate', label: 'Uso moderado' },
                ]}
                value={aiStyle.emoji_usage}
              />
              <label>
                <span>Palavras preferidas</span>
                <input onChange={(event) => setPreferredWords(event.target.value)} placeholder="Separe por vírgulas" value={preferredWords} />
              </label>
              <label>
                <span>Palavras proibidas</span>
                <input onChange={(event) => setForbiddenWords(event.target.value)} placeholder="Separe por vírgulas" value={forbiddenWords} />
              </label>
            </div>
            <label>
              <span>Mensagem de transferência humana</span>
              <textarea onChange={(event) => setAiStyle((current) => ({ ...current, human_transfer_message: event.target.value }))} rows={3} value={aiStyle.human_transfer_message} />
            </label>
            <label>
              <span>Mensagem de comprovante recebido</span>
              <textarea onChange={(event) => setAiStyle((current) => ({ ...current, payment_proof_received_message: event.target.value }))} rows={3} value={aiStyle.payment_proof_received_message} />
            </label>
            <label>
              <span>Mensagem de encerramento</span>
              <textarea onChange={(event) => setAiStyle((current) => ({ ...current, closing_message: event.target.value }))} rows={3} value={aiStyle.closing_message} />
            </label>
            <Button disabled={isSaving} type="submit" variant="primary">{isSaving ? 'Salvando' : 'Salvar estilo'}</Button>
          </form>
        ) : null}
      </div>
    </Modal>
  )
}

function quickReplyPayload(reply: ConversationQuickReply, isActive: boolean): ConversationQuickReplyPayload {
  return {
    title: reply.title,
    shortcut: reply.shortcut,
    body: reply.body,
    category: reply.category,
    is_active: isActive,
    display_order: reply.displayOrder,
  }
}

function sortQuickReplies(quickReplies: ConversationQuickReply[]): ConversationQuickReply[] {
  return [...quickReplies].sort((first, second) => first.displayOrder - second.displayOrder || first.title.localeCompare(second.title, 'pt-BR'))
}

function parseWordList(value: string): string[] {
  return value
    .split(',')
    .map((word) => word.trim())
    .filter(Boolean)
}
