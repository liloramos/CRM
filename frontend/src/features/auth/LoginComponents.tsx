import type { InputHTMLAttributes, ReactNode } from 'react'
import brandLogo from '../../../../imgs/logo.png'
import { Button } from '../../components/ui/Button'
import { Icon, type IconName } from '../../components/ui/Icon'

type BrandLogoProps = {
  compact?: boolean
}

export function BrandLogo({ compact = false }: BrandLogoProps) {
  return (
    <div className={compact ? 'auth-brand auth-brand--compact' : 'auth-brand'}>
      <span className="auth-brand__halo">
        <img alt="Sol Restaurante" src={brandLogo} />
      </span>
      <div>
        <strong>Sol Restaurante</strong>
        <small>ChatBot CRM operacional</small>
      </div>
    </div>
  )
}

type AuthInputProps = InputHTMLAttributes<HTMLInputElement> & {
  icon: IconName
  inputId: string
  label: string
}

export function AuthInput({ icon, inputId, label, ...props }: AuthInputProps) {
  return (
    <label className="auth-field" htmlFor={inputId}>
      <span>{label}</span>
      <span className="auth-field__control">
        <Icon name={icon} size={18} />
        <input id={inputId} {...props} />
      </span>
    </label>
  )
}

type PasswordInputProps = {
  isVisible: boolean
  onToggleVisibility: () => void
  onValueChange: (value: string) => void
  value: string
}

export function PasswordInput({ isVisible, onToggleVisibility, onValueChange, value }: PasswordInputProps) {
  return (
    <label className="auth-field" htmlFor="login-password">
      <span>Senha</span>
      <span className="auth-field__control">
        <Icon name="lock" size={18} />
        <input
          autoComplete="current-password"
          id="login-password"
          onChange={(event) => onValueChange(event.target.value)}
          placeholder="Digite sua senha"
          required
          type={isVisible ? 'text' : 'password'}
          value={value}
        />
        <button
          aria-label={isVisible ? 'Ocultar senha' : 'Mostrar senha'}
          className="auth-field__toggle"
          onClick={onToggleVisibility}
          type="button"
        >
          <Icon name={isVisible ? 'eye-off' : 'eye'} size={18} />
        </button>
      </span>
    </label>
  )
}

type RememberCheckboxProps = {
  checked: boolean
  onChange: (checked: boolean) => void
}

export function RememberCheckbox({ checked, onChange }: RememberCheckboxProps) {
  return (
    <label className="remember-checkbox">
      <input checked={checked} onChange={(event) => onChange(event.target.checked)} type="checkbox" />
      <span aria-hidden="true" className="remember-checkbox__box" />
      <span>Manter sessao neste dispositivo</span>
    </label>
  )
}

type LoginHeroPanelProps = {
  children?: ReactNode
}

export function LoginHeroPanel({ children }: LoginHeroPanelProps) {
  return (
    <section className="login-hero-panel" aria-label="Resumo visual do CRM operacional">
      <div className="login-hero-panel__glow" />
      <BrandLogo compact />
      <div className="login-hero-panel__copy">
        <span className="eyebrow">Operacao conectada</span>
        <h1>Atendimento, pedidos e comanda em um so painel.</h1>
        <p>Uma experiencia escura, rapida e clara para conferir mensagens, montar marmitas e liberar preparo com seguranca.</p>
      </div>

      <div className="login-product-preview">
        <div className="preview-card preview-card--conversation">
          <div className="preview-card__header">
            <span>Conversa</span>
            <strong>Em conferencia</strong>
          </div>
          <p>Cliente confirmou retirada por terceiro. Atendente valida antes de imprimir.</p>
        </div>
        <div className="preview-card preview-card--order">
          <div className="preview-card__header">
            <span>Pedido</span>
            <strong>#MVP-24</strong>
          </div>
          <div className="preview-order-row">
            <span>Marmita montada</span>
            <strong>2 itens</strong>
          </div>
          <div className="preview-order-row">
            <span>Comanda</span>
            <strong>Previa HTML</strong>
          </div>
        </div>
        <div className="preview-card preview-card--metric">
          <div className="preview-chart">
            <span style={{ height: '42%' }} />
            <span style={{ height: '62%' }} />
            <span style={{ height: '78%' }} />
            <span style={{ height: '55%' }} />
            <span style={{ height: '88%' }} />
          </div>
          <strong>Fluxo do dia</strong>
          <p>Pagamentos, entrega e impressao com revisao humana.</p>
        </div>
      </div>

      <div className="login-hero-panel__badges">
        <span>Laravel session</span>
        <span>Rotas protegidas</span>
        <span>Sem credenciais na tela</span>
      </div>
      {children}
    </section>
  )
}

type LoginSubmitButtonProps = {
  isSubmitting: boolean
}

export function LoginSubmitButton({ isSubmitting }: LoginSubmitButtonProps) {
  return (
    <Button className="auth-submit" disabled={isSubmitting} icon="arrow" type="submit" variant="primary">
      {isSubmitting ? 'Entrando...' : 'Entrar no CRM'}
    </Button>
  )
}
