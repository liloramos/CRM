import { useEffect, useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { SelectField } from '../../components/ui/SelectField'
import { ErrorState, LoadingState } from '../../components/ui/States'
import { getGeneralSettings, saveGeneralSettings, type GeneralSettings } from '../../services/crm.service'

const days = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado']
type Exception = GeneralSettings['operating_exceptions'][number]

export function GeneralSettingsPage({ onBack }: { onBack: () => void }) {
  const [saved, setSaved] = useState<GeneralSettings | null>(null)
  const [draft, setDraft] = useState<GeneralSettings | null>(null)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)
  const load = () => void getGeneralSettings().then((value) => { setSaved(value); setDraft(structuredClone(value)) }).catch(() => setError('Não foi possível carregar as configurações gerais.'))

  useEffect(load, [])
  if (!draft) return error ? <ErrorState actionLabel="Tentar novamente" description={error} onAction={load} title="Configurações indisponíveis" /> : <LoadingState description="Carregando horários persistidos." title="Aguarde" />

  const updateHour = (weekday: number, key: 'is_open' | 'opens_at' | 'closes_at', value: boolean | string) => setDraft((current) => current ? { ...current, operating_hours: current.operating_hours.map((day) => day.weekday === weekday ? { ...day, [key]: value } : day) } : current)
  const updateException = (index: number, key: keyof Exception, value: boolean | string | null) => setDraft((current) => current ? { ...current, operating_exceptions: current.operating_exceptions.map((item, itemIndex) => itemIndex === index ? { ...item, [key]: value } : item) } : current)
  const addException = () => setDraft((current) => current ? { ...current, operating_exceptions: [...current.operating_exceptions, { date: '', is_open: false, opens_at: null, closes_at: null, notes: '' }] } : current)
  const removeException = (index: number) => setDraft((current) => current ? { ...current, operating_exceptions: current.operating_exceptions.filter((_, itemIndex) => itemIndex !== index) } : current)
  const validHours = draft.operating_hours.every((day) => !day.is_open || Boolean(day.opens_at && day.closes_at && day.opens_at < day.closes_at))
  const validExceptions = draft.operating_exceptions.every((item) => item.date && (!item.is_open || Boolean(item.opens_at && item.closes_at && item.opens_at < item.closes_at)))
  const valid = draft.timezone !== '' && validHours && validExceptions
  const save = async () => {
    if (!valid) { setError('Cada dia aberto ou horário especial precisa ter início anterior ao fim.'); return }
    setBusy(true); setError(''); setMessage('')
    try { const value = await saveGeneralSettings(draft); setSaved(value); setDraft(structuredClone(value)); setMessage('Configurações gerais salvas com sucesso.') } catch { setError('Não foi possível salvar as configurações.') } finally { setBusy(false) }
  }

  return <main className="general-settings-page">
    <header className="general-settings-page__header"><div><h1>Configurações gerais</h1><p>Defina o fuso, a rotina semanal e exceções operacionais por data.</p></div><Button className="settings-back-button" onClick={onBack} variant="ghost">← Voltar para Configurações</Button></header>
    {message ? <p className="payments-feedback" role="status">{message}</p> : null}{error ? <p className="payments-feedback" role="alert">{error}</p> : null}
    <Card><SectionTitle title="Geral" /><SelectField label="Fuso horário" onChange={(value) => setDraft({ ...draft, timezone: value })} options={[{ value: 'America/Sao_Paulo', label: 'America/Sao_Paulo' }, { value: 'UTC', label: 'UTC' }]} value={draft.timezone} /></Card>
    <Card><SectionTitle eyebrow="Rotina recorrente" title="Horário de funcionamento" /><div className="hours-editor">{draft.operating_hours.map((day) => <div className="hours-row" key={day.weekday}><strong>{days[day.weekday]}</strong><label><input checked={day.is_open} onChange={(event) => updateHour(day.weekday, 'is_open', event.target.checked)} type="checkbox" /> {day.is_open ? 'Aberto' : 'Fechado'}</label><input disabled={!day.is_open} onChange={(event) => updateHour(day.weekday, 'opens_at', event.target.value)} type="time" value={day.opens_at ?? ''} /><span>até</span><input disabled={!day.is_open} onChange={(event) => updateHour(day.weekday, 'closes_at', event.target.value)} type="time" value={day.closes_at ?? ''} /></div>)}</div></Card>
    <Card><div className="general-settings-page__section-header"><SectionTitle eyebrow="Feriados e datas especiais" title="Exceções de funcionamento" /><Button onClick={addException} size="sm" variant="secondary">Adicionar data</Button></div><p className="general-settings-page__help">Uma exceção substitui somente a rotina daquela data. Use “Fechado” para feriados ou informe um horário especial.</p>{draft.operating_exceptions.length === 0 ? <p className="general-settings-page__empty">Nenhuma exceção cadastrada.</p> : <div className="hours-editor">{draft.operating_exceptions.map((item, index) => <div className="hours-exception-row" key={`${item.date}-${index}`}><input aria-label="Data da exceção" onChange={(event) => updateException(index, 'date', event.target.value)} type="date" value={item.date} /><label><input checked={item.is_open} onChange={(event) => updateException(index, 'is_open', event.target.checked)} type="checkbox" /> {item.is_open ? 'Aberto' : 'Fechado'}</label><input aria-label="Abertura especial" disabled={!item.is_open} onChange={(event) => updateException(index, 'opens_at', event.target.value)} type="time" value={item.opens_at ?? ''} /><input aria-label="Fechamento especial" disabled={!item.is_open} onChange={(event) => updateException(index, 'closes_at', event.target.value)} type="time" value={item.closes_at ?? ''} /><input aria-label="Motivo ou observação" onChange={(event) => updateException(index, 'notes', event.target.value)} placeholder="Motivo ou observação" value={item.notes ?? ''} /><Button onClick={() => removeException(index)} size="sm" variant="ghost">Remover</Button></div>)}</div>}</Card>
    <div className="inline-actions"><Button disabled={busy || !valid} onClick={() => void save()} variant="primary">{busy ? 'Salvando...' : 'Salvar alterações'}</Button><Button disabled={busy} onClick={() => saved && setDraft(structuredClone(saved))} variant="secondary">Descartar</Button></div>
  </main>
}
