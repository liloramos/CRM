import { useEffect, useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { ErrorState, LoadingState } from '../../components/ui/States'
import { getAccountCompany, getSession, type AccountCompany } from '../../services/crm.service'
import type { RouteKey } from '../../types/crm'

export function BrandSettingsPage({ onNavigate }: { onNavigate: (route: RouteKey) => void }) {
  const [company, setCompany] = useState<AccountCompany | null>(null); const [canManage, setCanManage] = useState(false); const [error, setError] = useState(''); const [retry, setRetry] = useState(0)
  useEffect(() => { let active = true; void Promise.all([getAccountCompany(), getSession()]).then(([data, session]) => { if (!active) return; setCompany(data); setCanManage(session.user?.roles.some((role) => role === 'super_admin' || role === 'admin_gerente') ?? false) }).catch(() => { if (active) setError('Não foi possível carregar a identidade da empresa.') }); return () => { active = false } }, [retry])
  if (!company) return error ? <ErrorState actionLabel="Tentar novamente" description={error} onAction={() => { setError(''); setRetry((value) => value + 1) }} title="Identidade indisponível" /> : <LoadingState description="Carregando identidade atual." title="Aguarde" />
  const name = company.displayName || company.name
  return <main className="general-settings-page brand-settings-page"><header className="general-settings-page__header"><div><h1>Aparência e marca</h1><p>Consulte a identidade visual utilizada pelo sistema e gerencie os dados da marca da empresa.</p></div><Button className="settings-back-button" onClick={() => onNavigate('configuracoes')} variant="ghost">← Voltar para Configurações</Button></header><Card className="brand-preview"><SectionTitle title="Identidade atual" /><div className="profile-summary">{company.logoUrl ? <img alt={name} className="avatar avatar--lg" src={company.logoUrl} /> : <span className="avatar avatar--lg">{name.slice(0, 2).toUpperCase()}</span>}<div><h2>{name}</h2><p>{company.name}</p>{company.responsibleName ? <p>{company.responsibleName}</p> : null}</div></div><p className="muted-text">O nome e a logo são administrados na página Empresa. A identidade das comandas impressas é administrada separadamente nas configurações de impressão.</p>{canManage ? <Button onClick={() => onNavigate('empresa')} variant="primary">Gerenciar identidade da empresa</Button> : <p className="muted-text">Você pode consultar a identidade configurada. A edição é restrita à gerência.</p>}</Card></main>
}
