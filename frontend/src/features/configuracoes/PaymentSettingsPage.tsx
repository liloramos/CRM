import { useEffect, useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { Icon, type IconName } from '../../components/ui/Icon'
import { ErrorState, LoadingState } from '../../components/ui/States'
import { getPaymentSettings, getSession, savePaymentSettings } from '../../services/crm.service'
import type { PaymentSettingMethod, PaymentSettings } from '../../types/crm'

const paymentIcons: Record<PaymentSettingMethod['code'], IconName> = {
  pix: 'qr-code',
  cash: 'cash',
  debit_card: 'payment',
  credit_card: 'wallet-cards',
  customer_credit: 'wallet',
  other: 'plus-circle',
}

export function PaymentSettingsPage({ onBack }: { onBack: () => void }) {
  const [saved, setSaved] = useState<PaymentSettings | null>(null); const [draft, setDraft] = useState<PaymentSettings | null>(null); const [canManage, setCanManage] = useState(false); const [busy, setBusy] = useState(false); const [error, setError] = useState(''); const [message, setMessage] = useState('')
  const load = () => void Promise.all([getPaymentSettings(), getSession()]).then(([settings, session]) => { setSaved(settings); setDraft(structuredClone(settings)); setCanManage(session.user?.permissions.includes('settings.manage') ?? false) }).catch(() => setError('Não foi possível carregar as configurações de pagamentos.'))
  useEffect(load, [])
  if (!draft) return error ? <ErrorState actionLabel="Tentar novamente" description={error} onAction={load} title="Pagamentos indisponíveis" /> : <LoadingState description="Carregando as formas de pagamento aceitas." title="Aguarde" />
  const toggle = (code: string) => setDraft((current) => current ? { ...current, methods: current.methods.map((method) => method.code === code ? { ...method, enabled: !method.enabled } : method) } : current)
  const save = async () => { setBusy(true); setError(''); try { const value = await savePaymentSettings(draft); setSaved(value); setDraft(structuredClone(value)); setMessage('Configurações de pagamento salvas com sucesso.') } catch { setError('Não foi possível salvar as configurações de pagamento.') } finally { setBusy(false) } }
  const pixEnabled = draft.methods.find((method) => method.code === 'pix')?.enabled ?? false
  return <main className="general-settings-page payment-settings-page"><header className="general-settings-page__header"><div><h1>Pagamentos</h1><p>Configure as formas de pagamento aceitas pelo restaurante.</p></div><Button className="settings-back-button" onClick={onBack} variant="ghost">← Voltar para Configurações</Button></header>{message ? <p className="payments-feedback">{message}</p> : null}{error ? <p className="payments-feedback">{error}</p> : null}<Card><SectionTitle title="Formas aceitas" /><div className="payment-method-list">{draft.methods.map((method) => <label className="payment-method-row" key={method.code}><span className="payment-method-icon" aria-hidden="true"><Icon name={paymentIcons[method.code]} size={19} /></span><span className="payment-method-copy"><strong>{method.label}</strong><small>{method.description}</small></span><input aria-label={`Ativar ${method.label}`} checked={method.enabled} className="payment-method-toggle" disabled={!canManage || busy} onChange={() => toggle(method.code)} type="checkbox" /></label>)}</div></Card>{pixEnabled ? <Card className="payment-pix-card"><SectionTitle title="Configuração do Pix" /><p className="payment-pix-card__description">Informe somente a identificação pública que o cliente pode utilizar para pagar.</p><label className="payment-pix-field">Chave ou identificação pública<input disabled={!canManage || busy} maxLength={160} onChange={(event) => setDraft({ ...draft, pix: { public_key: event.target.value || null } })} placeholder="Chave Pix ou identificação pública" value={draft.pix.public_key ?? ''} /></label><p className="muted-text">Credenciais de integração não são expostas.</p></Card> : null}<div className="inline-actions payment-settings-page__actions"><Button disabled={!canManage || busy} onClick={() => void save()} variant="primary">Salvar alterações</Button><Button disabled={!canManage || busy} onClick={() => saved && setDraft(structuredClone(saved))} variant="secondary">Descartar</Button></div>{!canManage ? <p className="muted-text">Você pode consultar esta configuração; a edição é restrita à gerência.</p> : null}</main>
}
