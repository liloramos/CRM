import { useState, type FormEvent } from 'react'
import { Button } from '../../components/ui/Button'
import { useAuth } from './auth-state'
import {
  AuthSelect,
  AuthInput,
  BrandLogo,
  LoginHeroPanel,
  LoginSubmitButton,
  PasswordInput,
  RememberCheckbox,
} from './LoginComponents'

const LOCAL_DEMO_EMAIL = 'admin.gerente@example.test'
const LOCAL_DEMO_PASSWORD = 'password'
const ACCESS_ROLE_OPTIONS = [
  { label: 'Atendimento', value: 'atendente' },
  { label: 'Gerencia', value: 'gerente' },
  { label: 'Cozinha / impressao', value: 'cozinha' },
]

export function LoginPage() {
  const { error, login } = useAuth()
  const [email, setEmail] = useState(LOCAL_DEMO_EMAIL)
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(true)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [formNotice, setFormNotice] = useState<string | null>(null)
  const [mode, setMode] = useState<'login' | 'access'>('login')
  const [isPasswordVisible, setIsPasswordVisible] = useState(false)
  const [accessRole, setAccessRole] = useState(ACCESS_ROLE_OPTIONS[0].value)
  const isDev = import.meta.env.DEV

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setIsSubmitting(true)
    setFormError(null)
    setFormNotice(null)

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

      <div className="auth-shell">
        <section className="login-auth-panel auth-form-panel" aria-label="Acesso ao ChatBot CRM">
          <div className="login-auth-panel__inner">
            <BrandLogo />

            <div className="login-copy">
              <h1>{mode === 'login' ? 'Bem-vindo de volta.' : 'Solicitar acesso'}</h1>
              <p>
                {mode === 'login'
                  ? 'Acesse sua conta para continuar.'
                  : 'Solicite liberacao ao gerente para acessar o sistema com o perfil adequado.'}
              </p>
            </div>

            <div className="login-tabs" role="tablist" aria-label="Acesso ao CRM">
              <button
                aria-selected={mode === 'login'}
                className={mode === 'login' ? 'tab is-active' : 'tab'}
                onClick={() => {
                  setMode('login')
                  setFormNotice(null)
                  setFormError(null)
                }}
                role="tab"
                type="button"
              >
                Entrar
              </button>
              <button
                aria-selected={mode === 'access'}
                className={mode === 'access' ? 'tab is-active' : 'tab'}
                onClick={() => {
                  setMode('access')
                  setFormNotice(null)
                  setFormError(null)
                }}
                role="tab"
                type="button"
              >
                Solicitar acesso
              </button>
            </div>

            <div className="login-form-viewport" data-mode={mode}>
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
                      onClick={() => {
                        setFormError(null)
                        setFormNotice('Redefinicao de senha deve ser solicitada ao gerente neste MVP.')
                      }}
                      type="button"
                    >
                      Esqueci minha senha
                    </button>
                  </div>

                  <div className="auth-feedback-slot" aria-live="polite">
                    {formError || error ? (
                      <p className="auth-error" role="alert">
                        {formError ?? error}
                      </p>
                    ) : formNotice ? (
                      <p className="auth-inline-notice" role="status">
                        {formNotice}
                      </p>
                    ) : null}
                  </div>

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
                  <AuthSelect
                    inputId="access-role"
                    label="Perfil solicitado"
                    onChange={setAccessRole}
                    options={ACCESS_ROLE_OPTIONS}
                    value={accessRole}
                  />
                  <div className="auth-note">
                    <strong>Cadastro controlado</strong>
                    <p>Nenhum usuario e criado automaticamente por esta tela. A liberacao real fica com perfil administrativo.</p>
                  </div>
                  <Button onClick={() => setMode('login')} variant="secondary">
                    Voltar ao login
                  </Button>
                </div>
              )}
            </div>

          </div>
        </section>

        <LoginHeroPanel />
      </div>
    </main>
  )
}
