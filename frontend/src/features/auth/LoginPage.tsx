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
        <span className="eyebrow">ChatBot CRM</span>
        <h1>Operacao do restaurante</h1>
        <p>Entre para acompanhar conversas, pedidos, pagamentos e comandas com revisao humana.</p>
      </section>

      <Card className="login-card" tone="glow">
        <form className="login-form" onSubmit={handleSubmit}>
          <div>
            <span className="eyebrow">Acesso operacional</span>
            <h2>Login</h2>
            <p>Use uma conta cadastrada no Laravel. No ambiente local seedado, ha usuarios ficticios de desenvolvimento.</p>
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
            <Button
              onClick={() => {
                setEmail(LOCAL_DEMO_EMAIL)
                setPassword(LOCAL_DEMO_PASSWORD)
              }}
              variant="ghost"
            >
              Usar acesso local
            </Button>
          </div>
        </form>
      </Card>
    </main>
  )
}
