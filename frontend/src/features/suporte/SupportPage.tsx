import { useEffect, useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { SelectField } from '../../components/ui/SelectField'
import { ErrorState, LoadingState } from '../../components/ui/States'
import { createSupportTicket, getSupportTickets } from '../../services/crm.service'
import type { RouteKey, SupportTicket, SupportTicketCenter } from '../../types/crm'

const categories: Array<{ value: SupportTicket['category']; label: string }> = [{ value: 'order', label: 'Pedido' }, { value: 'payment', label: 'Pagamento' }, { value: 'printing', label: 'Impressão' }, { value: 'whatsapp', label: 'WhatsApp' }, { value: 'ai', label: 'IA' }, { value: 'delivery', label: 'Entrega' }, { value: 'menu', label: 'Cardápio' }, { value: 'access', label: 'Acesso' }, { value: 'customer', label: 'Cliente' }, { value: 'reports', label: 'Relatórios' }, { value: 'other', label: 'Outro' }]
const label = (value: string) => categories.find((item) => item.value === value)?.label ?? value
const statusLabel = (value: SupportTicket['status']) => ({ open: 'Aberto', in_review: 'Em análise', resolved: 'Resolvido' }[value])
const formatDate = (value: string | null) => value ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : 'Agora'

function ticketCreatedMessage(ticket: SupportTicket): string {
  if (ticket.email_delivery_status === 'sent') return `Chamado ${ticket.code} registrado. Notificação enviada ao suporte por e-mail.`
  if (ticket.email_delivery_status === 'failed') return `Chamado ${ticket.code} registrado. Não foi possível enviar a notificação por e-mail.`
  if (ticket.contact.whatsapp_url) return `Chamado ${ticket.code} registrado. O e-mail não está configurado; use o botão do WhatsApp para avisar o suporte.`
  return `Chamado ${ticket.code} registrado. O canal de notificação não está configurado.`
}

export function SupportPage({ onNavigate }: { onNavigate: (route: RouteKey) => void }) {
  const [center, setCenter] = useState<SupportTicketCenter | null>(null)
  const [category, setCategory] = useState<SupportTicket['category']>('other')
  const [subject, setSubject] = useState('')
  const [description, setDescription] = useState('')
  const [priority, setPriority] = useState<SupportTicket['priority']>('normal')
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [busy, setBusy] = useState(false)
  const load = async () => { try { setCenter(await getSupportTickets()) } catch { setError('Não foi possível carregar seus chamados agora.') } }
  useEffect(() => { let active = true; void getSupportTickets().then((data) => { if (active) setCenter(data) }).catch(() => { if (active) setError('Não foi possível carregar seus chamados agora.') }); return () => { active = false } }, [])
  const submit = async () => { if (!subject.trim() || !description.trim()) { setError('Informe categoria, assunto e descrição para registrar o chamado.'); return } setBusy(true); setError(''); setSuccess(''); try { const ticket = await createSupportTicket({ category, subject: subject.trim(), description: description.trim(), priority, current_route: 'suporte' }); setCenter((current) => current ? { ...current, tickets: [ticket, ...current.tickets].slice(0, 5) } : current); setSuccess(ticketCreatedMessage(ticket)); setSubject(''); setDescription('') } catch (reason) { setError(reason instanceof Error ? reason.message : 'Não foi possível registrar o chamado.') } finally { setBusy(false) } }
  if (!center && !error) return <LoadingState description="Carregando a Central de Ajuda." title="Aguarde" />
  return <main className="general-settings-page support-page"><header><h1>Central de Ajuda</h1><p>Encontre ajuda para usar o sistema ou envie um relato de suporte.</p></header><Card><SectionTitle title="Assistente do sistema" /><p className="muted-text">Pergunte como usar uma funcionalidade ou encontre rapidamente uma área do CRM.</p><Button onClick={() => onNavigate('assistente')} variant="secondary">Abrir Assistente</Button></Card><Card><SectionTitle title="Reportar um problema" /><p className="muted-text">Seu relato incluirá informações básicas da conta e da tela atual para facilitar o suporte. Não enviamos conversas, comprovantes, dados de cartão ou segredos.</p><div className="support-form-grid"><SelectField label="Categoria" onChange={(value) => setCategory(value as SupportTicket['category'])} options={categories} value={category} /><SelectField label="Prioridade" onChange={(value) => setPriority(value as SupportTicket['priority'])} options={[{ value: 'low', label: 'Baixa' }, { value: 'normal', label: 'Normal' }, { value: 'high', label: 'Alta' }]} value={priority} /><label className="form-field support-form-wide"><span>Assunto</span><input onChange={(event) => setSubject(event.target.value)} placeholder="Descreva brevemente o problema" value={subject} /></label><label className="form-field support-form-wide"><span>Descrição</span><textarea onChange={(event) => setDescription(event.target.value)} placeholder="Conte o que aconteceu e o que você esperava que acontecesse." rows={5} value={description} /></label></div>{error ? <p className="payments-feedback">{error}</p> : null}{success ? <p className="payments-feedback">{success}</p> : null}<Button disabled={busy || !subject.trim() || !description.trim()} onClick={() => void submit()} variant="primary">Registrar chamado</Button></Card><div className="account-grid"><Card><SectionTitle title="Contato" />{center?.contact.whatsapp_number ? <p><strong>WhatsApp de suporte</strong><br />{formatWhatsapp(center.contact.whatsapp_number)}</p> : null}{center?.contact.whatsapp_number ? <p className="muted-text">Use o botão WhatsApp disponível em cada chamado registrado.</p> : null}{center?.contact.email_configured ? <p className="muted-text">O chamado também será encaminhado por e-mail para o suporte.</p> : <p className="muted-text">O registro do chamado funciona mesmo sem envio por e-mail configurado.</p>}<p className="muted-text">Suporte e soluções em sistemas de software</p></Card><Card><SectionTitle title="Seus chamados recentes" />{center?.tickets.length ? <ul className="whatsapp-errors">{center.tickets.map((ticket) => <li key={ticket.id}><span><strong>{ticket.code}</strong> · {label(ticket.category)}<br /><small>{ticket.subject} · {statusLabel(ticket.status)} · {formatDate(ticket.created_at)}</small></span>{ticket.contact.whatsapp_url ? <a className="button button--ghost button--sm" href={ticket.contact.whatsapp_url} rel="noreferrer" target="_blank">Falar pelo WhatsApp</a> : null}</li>)}</ul> : <p className="muted-text">Você ainda não registrou chamados.</p>}</Card></div>{center ? null : <ErrorState actionLabel="Tentar novamente" description={error} onAction={load} title="Central de Ajuda indisponível" />}</main>
}

function formatWhatsapp(number: string): string { return number.length === 13 ? `(${number.slice(2, 4)}) ${number.slice(4, 9)}-${number.slice(9)}` : number }
