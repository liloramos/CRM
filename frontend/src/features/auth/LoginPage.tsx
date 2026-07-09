import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { EmptyState } from '../../components/ui/States'
import { useAuth } from './auth-state'

const LOCAL_DEMO_EMAIL = 'admin.gerente@example.test'
const LOCAL_DEMO_PASSWORD = 'password'

export function LoginPage() {
  const { error, login } = useAuth()
  const [email, setEmail] = useState(LOCAL_DEMO_EMAIL)
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(true)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [mode, setMode] = useState<'login' | 'access'>('login')
  const isDev = import.meta.env.DEV

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setIsSubmitting(true)
    setFormError(null)

    try {
      await login(email, password, remember)
    } catch (loginError) {
      setFormError(loginError instanceof Error ? loginError.message : 'Nao foi possivel entrar.')
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <main className="login-screen">
      <section className="login-screen__brand">
        <img alt="Sol Restaurante" className="login-screen__logo" src="/sol-logo.png" />
        <span className="eyebrow">Sol Restaurante</span>
        <h1>Central operacional</h1>
        <p>Atendimento, pedidos, pagamento conferido e comanda HTML antes do preparo, em uma tela pensada para a rotina da equipe.</p>
        <div className="login-screen__checks">
          <span>Pedidos com revisao humana</span>
          <span>Cardapio por componentes</span>
          <span>Previa de comanda segura</span>
        </div>
      </section>

      <Card className="login-card" tone="glow">
        <div className="login-tabs" role="tablist" aria-label="Acesso ao CRM">
          <button className={mode === 'login' ? 'tab is-active' : 'tab'} onClick={() => setMode('login')} type="button">
            Entrar
          </button>
          <button className={mode === 'access' ? 'tab is-active' : 'tab'} onClick={() => setMode('access')} type="button">
            Solicitar acesso
          </button>
        </div>

        {mode === 'login' ? (
          <form className="login-form" onSubmit={handleSubmit}>
          <div>
            <span className="eyebrow">Acesso operacional</span>
            <h2>Entrar no painel</h2>
            <p>Use uma conta cadastrada no Laravel. Em desenvolvimento, o atalho local fica visivel apenas para teste.</p>
          </div>

          <label>
            E-mail
            <input autoComplete="email" onChange={(event) => setEmail(event.target.value)} required type="email" value={email} />
          </label>

          <label>
            Senha
            <input
              autoComplete="current-password"
              onChange={(event) => setPassword(event.target.value)}
              placeholder="Senha do usuario"
              required
              type="password"
              value={password}
            />
          </label>

          <label className="checkbox-row">
            <input checked={remember} onChange={(event) => setRemember(event.target.checked)} type="checkbox" />
            Manter sessao neste dispositivo
          </label>

          {formError || error ? <EmptyState description={formError ?? error ?? undefined} title="Acesso nao autorizado" /> : null}

          <div className="login-actions">
            <Button disabled={isSubmitting} icon="arrow" type="submit" variant="primary">
              {isSubmitting ? 'Entrando...' : 'Entrar'}
            </Button>
            {isDev ? (
              <Button
                onClick={() => {
                  setEmail(LOCAL_DEMO_EMAIL)
                  setPassword(LOCAL_DEMO_PASSWORD)
                }}
                variant="ghost"
              >
                Preencher acesso local
              </Button>
            ) : null}
          </div>
          </form>
        ) : (
          <div className="login-form">
            <div>
              <span className="eyebrow">Cadastro controlado</span>
              <h2>Solicitar acesso</h2>
              <p>Cadastro publico nao fica aberto neste MVP. Um gerente deve criar ou liberar usuarios dentro da operacao.</p>
            </div>
            <label>
              Nome
              <input placeholder="Nome do colaborador" />
            </label>
            <label>
              E-mail
              <input placeholder="email@exemplo.local" type="email" />
            </label>
            <label>
              Perfil solicitado
              <select defaultValue="atendente">
                <option value="atendente">Atendimento</option>
                <option value="gerente">Gerencia</option>
                <option value="cozinha">Cozinha / impressao</option>
              </select>
            </label>
            <div className="attention-box">
              <strong>Fluxo seguro</strong>
              <p>Esta tela registra apenas a intencao visual do fluxo. A criacao real de usuario precisa de permissao administrativa.</p>
            </div>
            <Button icon="check" onClick={() => setMode('login')} variant="secondary">
              Voltar ao login
            </Button>
          </div>
        )}
      </Card>
    </main>
  )
}
