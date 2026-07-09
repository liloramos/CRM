import { useState, type FormEvent } from 'react'
import { Button } from '../../components/ui/Button'
import { useAuth } from './auth-state'
import {
  AuthInput,
  BrandLogo,
  LoginHeroPanel,
  LoginSubmitButton,
  PasswordInput,
  RememberCheckbox,
} from './LoginComponents'

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
  const [isPasswordVisible, setIsPasswordVisible] = useState(false)
  const isDev = import.meta.env.DEV

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
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
      <div className="login-screen__ambient" aria-hidden="true">
        <span />
        <span />
        <span />
      </div>

      <section className="login-auth-panel" aria-label="Acesso ao ChatBot CRM">
        <div className="login-auth-panel__inner">
          <BrandLogo />

          <div className="login-copy">
            <span className="eyebrow">Acesso seguro</span>
            <h1>{mode === 'login' ? 'Bem-vindo de volta.' : 'Solicitar acesso'}</h1>
            <p>
              {mode === 'login'
                ? 'Entre com uma conta cadastrada no Laravel para acessar pedidos, cardapio e comandas.'
                : 'O cadastro publico permanece fechado. Um gerente libera novos usuarios com permissao adequada.'}
            </p>
          </div>

          <div className="login-tabs" role="tablist" aria-label="Acesso ao CRM">
            <button
              aria-selected={mode === 'login'}
              className={mode === 'login' ? 'tab is-active' : 'tab'}
              onClick={() => setMode('login')}
              role="tab"
              type="button"
            >
              Entrar
            </button>
            <button
              aria-selected={mode === 'access'}
              className={mode === 'access' ? 'tab is-active' : 'tab'}
              onClick={() => setMode('access')}
              role="tab"
              type="button"
            >
              Solicitar acesso
            </button>
          </div>

          {mode === 'login' ? (
            <form className="login-form" onSubmit={handleSubmit}>
              <AuthInput
                autoComplete="email"
                icon="mail"
                inputId="login-email"
                label="E-mail"
                onChange={(event) => setEmail(event.target.value)}
                placeholder="usuario@exemplo.local"
                required
                type="email"
                value={email}
              />

              <PasswordInput
                isVisible={isPasswordVisible}
                onToggleVisibility={() => setIsPasswordVisible((current) => !current)}
                onValueChange={setPassword}
                value={password}
              />

              <div className="login-form__meta">
                <RememberCheckbox checked={remember} onChange={setRemember} />
                <button
                  className="auth-link"
                  onClick={() => setFormError('Redefinicao de senha deve ser solicitada ao gerente neste MVP.')}
                  type="button"
                >
                  Esqueci minha senha
                </button>
              </div>

              {formError || error ? (
                <p className="auth-error" role="alert">
                  {formError ?? error}
                </p>
              ) : null}

              <div className="login-actions">
                <LoginSubmitButton isSubmitting={isSubmitting} />
                {isDev ? (
                  <Button
                    className="auth-dev-button"
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
            <div className="login-form" role="tabpanel">
              <AuthInput icon="user" inputId="access-name" label="Nome" placeholder="Nome do colaborador" />
              <AuthInput icon="mail" inputId="access-email" label="E-mail" placeholder="email@exemplo.local" type="email" />
              <label className="auth-field" htmlFor="access-role">
                <span>Perfil solicitado</span>
                <span className="auth-field__control">
                  <select defaultValue="atendente" id="access-role">
                    <option value="atendente">Atendimento</option>
                    <option value="gerente">Gerencia</option>
                    <option value="cozinha">Cozinha / impressao</option>
                  </select>
                </span>
              </label>
              <div className="auth-note">
                <strong>Cadastro controlado</strong>
                <p>Nenhum usuario e criado automaticamente por esta tela. A liberacao real fica com perfil administrativo.</p>
              </div>
              <Button icon="arrow" onClick={() => setMode('login')} variant="secondary">
                Voltar ao login
              </Button>
            </div>
          )}

          <div className="login-screen__checks">
            <span>Pedidos com revisao humana</span>
            <span>Cardapio por componentes</span>
            <span>Previa HTML segura</span>
          </div>
        </div>
      </section>

      <LoginHeroPanel />
    </main>
  )
}
