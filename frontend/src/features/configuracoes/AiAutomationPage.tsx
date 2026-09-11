import { useEffect, useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { SelectField } from '../../components/ui/SelectField'
import { ErrorState, LoadingState } from '../../components/ui/States'
import {
  describeApiError,
  getAiAutomationSettings,
  getSession,
  runAiAutomationSandbox,
  saveAiAutomationGuidance,
  saveAiAutomationRollout,
} from '../../services/crm.service'
import type { AiAutomationSandboxResult, AiAutomationSettings } from '../../types/crm'

const MAX_GUIDANCE_ITEMS = 20
const MAX_GUIDANCE_LENGTH = 300
const formatDate = (value: string | null) => value ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : 'Sem registro'
const rolloutLabel = (value: AiAutomationSettings['rollout']) => ({ disabled: 'Desativado', shadow: 'Shadow', act_safe: 'Act Safe' }[value])

export function AiAutomationPage() {
  const [data, setData] = useState<AiAutomationSettings | null>(null)
  const [canManage, setCanManage] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [guidance, setGuidance] = useState<string[]>([])
  const [newGuidance, setNewGuidance] = useState('')
  const [sandboxMessage, setSandboxMessage] = useState('')
  const [sandbox, setSandbox] = useState<AiAutomationSandboxResult | null>(null)
  const [sandboxError, setSandboxError] = useState('')
  const [busy, setBusy] = useState(false)

  const applyData = (settings: AiAutomationSettings) => {
    setData(settings)
    setGuidance(settings.guidance.instructions)
  }

  const load = async () => {
    setError('')
    try {
      const [settings, session] = await Promise.all([getAiAutomationSettings(), getSession()])
      applyData(settings)
      setCanManage(session.user?.permissions.includes('settings.manage') ?? false)
    } catch {
      setError('Não foi possível carregar o estado da automação.')
    }
  }

  useEffect(() => {
    let active = true
    void Promise.all([getAiAutomationSettings(), getSession()]).then(([settings, session]) => {
      if (!active) return
      applyData(settings)
      setCanManage(session.user?.permissions.includes('settings.manage') ?? false)
    }).catch(() => {
      if (active) setError('Não foi possível carregar o estado da automação.')
    })
    return () => { active = false }
  }, [])

  if (!data) return error ? <ErrorState actionLabel="Tentar novamente" description={error} onAction={load} title="IA e Automação indisponível" /> : <LoadingState description="Carregando a configuração segura da automação." title="Aguarde" />

  const saveRollout = async (rollout: AiAutomationSettings['rollout']) => {
    if (!canManage || rollout === data.rollout) return
    if (!window.confirm(`Alterar o rollout para ${rolloutLabel(rollout)}?`)) return
    setBusy(true)
    setError('')
    try {
      applyData(await saveAiAutomationRollout(rollout))
      setMessage('Modo de operação atualizado.')
    } catch {
      setError('Não foi possível atualizar o modo de operação.')
    } finally {
      setBusy(false)
    }
  }

  const addGuidance = () => {
    const instruction = newGuidance.trim()
    if (!instruction || guidance.length >= MAX_GUIDANCE_ITEMS) return
    if (guidance.some((current) => current.toLocaleLowerCase('pt-BR') === instruction.toLocaleLowerCase('pt-BR'))) {
      setError('Essa orientação já está na lista.')
      return
    }
    setGuidance((current) => [...current, instruction])
    setNewGuidance('')
    setError('')
    setMessage('')
  }

  const saveGuidance = async () => {
    if (!canManage) return
    setBusy(true)
    setError('')
    try {
      applyData(await saveAiAutomationGuidance(guidance))
      setMessage('Orientações da IA salvas.')
    } catch (requestError) {
      setError(describeApiError(requestError, 'Não foi possível salvar as orientações da IA.'))
    } finally {
      setBusy(false)
    }
  }

  const discardGuidance = () => {
    setGuidance(data.guidance.instructions)
    setNewGuidance('')
    setError('')
    setMessage('Alterações descartadas.')
  }

  const runSandbox = async () => {
    if (!sandboxMessage.trim() || busy) return
    setBusy(true)
    setSandbox(null)
    setSandboxError('')
    try {
      setSandbox(await runAiAutomationSandbox(sandboxMessage.trim()))
    } catch (requestError) {
      setSandboxError(describeApiError(requestError, 'Não foi possível simular a IA agora.'))
    } finally {
      setBusy(false)
    }
  }

  const guidanceChanged = JSON.stringify(guidance) !== JSON.stringify(data.guidance.instructions)

  return (
    <main className="general-settings-page ai-automation-page">
      <header><h1>IA e Automação</h1><p>Gerencie o comportamento da automação e acompanhe a operação do assistente inteligente.</p></header>
      {message ? <p className="payments-feedback">{message}</p> : null}
      {error ? <p className="payments-feedback" role="alert">{error}</p> : null}
      <div className="account-grid">
        <Card><SectionTitle title="Estado da IA" /><dl className="security-status-list"><div><dt>Provider</dt><dd>{data.provider}</dd></div><div><dt>Modelo</dt><dd>{data.model ?? 'Ambiente local'}</dd></div><div><dt>API Key</dt><dd>{data.api_key_configured ? 'Configurada' : 'Não configurada'}</dd></div><div><dt>Última execução</dt><dd>{formatDate(data.last_execution_at)}</dd></div><div><dt>Última falha</dt><dd>{data.last_failure ?? 'Nenhuma falha recente'}</dd></div></dl></Card>
        <Card><SectionTitle title="Modo de operação" /><SelectField disabled={!canManage || busy} label="Rollout" onChange={(value) => void saveRollout(value as AiAutomationSettings['rollout'])} options={[{ value: 'disabled', label: 'Desativado' }, { value: 'shadow', label: 'Shadow' }, { value: 'act_safe', label: 'Act Safe' }]} value={data.rollout} /><p className="muted-text">Automação: {data.automation_enabled ? 'Ativa' : 'Desativada'} · Envio automático: {data.allow_auto_send ? 'Ativo' : 'Desativado'}</p>{!canManage ? <p className="muted-text">A alteração do rollout é restrita à gerência.</p> : null}</Card>
      </div>
      <Card><SectionTitle title="Proteções da automação" /><p className="muted-text">A IA não confirma, rejeita ou anula pagamentos; não valida comprovantes, concede crédito, faz refund, aplica desconto excepcional, altera taxas ou executa exclusões administrativas. Essas proteções não são configuráveis nesta tela.</p></Card>
      <Card className="ai-guidance-card">
        <SectionTitle title="Orientações da IA" />
        <p className="muted-text">Instruções adicionais que o Copilot deve considerar no atendimento, sempre abaixo das proteções obrigatórias do sistema.</p>
        {guidance.length ? <div className="ai-guidance-list">{guidance.map((instruction, index) => <div className="ai-guidance-item" key={`${instruction}-${index}`}><input aria-label={`Orientação ${index + 1}`} disabled={!canManage || busy} maxLength={MAX_GUIDANCE_LENGTH} onChange={(event) => setGuidance((current) => current.map((value, itemIndex) => itemIndex === index ? event.target.value : value))} value={instruction} />{canManage ? <Button disabled={busy} onClick={() => setGuidance((current) => current.filter((_, itemIndex) => itemIndex !== index))} size="sm" variant="ghost">Remover</Button> : null}</div>)}</div> : <p className="ai-guidance-empty">Nenhuma orientação adicional cadastrada.</p>}
        {canManage ? <><div className="ai-guidance-add"><input disabled={busy || guidance.length >= MAX_GUIDANCE_ITEMS} maxLength={MAX_GUIDANCE_LENGTH} onChange={(event) => setNewGuidance(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); addGuidance() } }} placeholder="Escreva uma nova orientação..." value={newGuidance} /><Button disabled={busy || !newGuidance.trim() || guidance.length >= MAX_GUIDANCE_ITEMS} onClick={addGuidance} variant="secondary">Adicionar</Button></div><div className="ai-guidance-actions"><Button disabled={busy || !guidanceChanged} onClick={() => void saveGuidance()} variant="primary">Salvar orientações</Button><Button disabled={busy || !guidanceChanged} onClick={discardGuidance} variant="ghost">Descartar</Button><span>{guidance.length}/{MAX_GUIDANCE_ITEMS}</span></div></> : <p className="muted-text">Somente a gerência pode alterar estas orientações.</p>}
      </Card>
      <div className="account-grid">
        <Card><SectionTitle title="Handoffs e revisão humana" />{data.handoffs.length ? <ul className="whatsapp-errors">{data.handoffs.map((handoff, index) => <li key={`${handoff.reason}-${index}`}><strong>{handoff.reason}</strong><span>{formatDate(handoff.occurred_at)}</span></li>)}</ul> : <p className="muted-text">Nenhuma revisão humana recente.</p>}</Card>
        <Card className="ai-sandbox-card"><SectionTitle title="Testar comportamento da IA" /><label className="form-field"><span>Digite uma mensagem de cliente para simular...</span><textarea disabled={busy} maxLength={1000} onChange={(event) => setSandboxMessage(event.target.value)} placeholder="Quero uma N5 com arroz, feijão e porco" rows={4} value={sandboxMessage} /></label><Button disabled={busy || !sandboxMessage.trim()} onClick={() => void runSandbox()} variant="primary">{busy ? 'Simulando...' : 'Simular com segurança'}</Button>{sandboxError ? <p className="form-error" role="alert">{sandboxError}</p> : null}{sandbox ? <dl className="ai-sandbox-result"><div><dt>Resposta proposta</dt><dd className="ai-sandbox-result__messages">{sandbox.reply_messages.map((reply, index) => <p key={`${index}-${reply}`}>{reply}</p>)}</dd></div><div><dt>Classificação</dt><dd>{sandbox.classification}</dd></div><div><dt>Precisa de humano?</dt><dd>{sandbox.requires_human_review ? 'Sim' : 'Não'}</dd></div><div><dt>Ação proposta</dt><dd>{sandbox.action}</dd></div><div><dt>Motivo</dt><dd>{sandbox.reason}</dd></div><div><dt>Modo atual</dt><dd>{rolloutLabel(sandbox.rollout)}</dd></div></dl> : null}<p className="muted-text">A simulação consulta o Copilot em modo somente leitura: não envia WhatsApp nem cria conversa, pedido, pagamento ou outra alteração operacional.</p></Card>
      </div>
    </main>
  )
}
