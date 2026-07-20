import { useEffect, useId, useRef, useState, type InputHTMLAttributes, type KeyboardEvent, type ReactNode } from 'react'
import {
  Bell,
  Check,
  ChevronDown,
  ClipboardList,
  Eye,
  EyeOff,
  LayoutDashboard,
  Lock,
  LogIn,
  Mail,
  MessageCircle,
  User,
  Users,
  type LucideIcon,
} from 'lucide-react'
import { Button } from '../../components/ui/Button'

const AUTH_ICON_STROKE = 1.85
const AUTH_BRAND_ICON_SRC = '/imgs/logo-cores-invertidas-square-login.png'
const HERO_BRAND_LOGO_SRC = '/imgs/logo-sol-transparent.png'

const authIcons = {
  lock: Lock,
  mail: Mail,
  user: User,
} satisfies Record<string, LucideIcon>

type AuthIconName = keyof typeof authIcons

function LoginIcon({ IconComponent, className = '', size = 18 }: { IconComponent: LucideIcon; className?: string; size?: number }) {
  return <IconComponent aria-hidden="true" className={`auth-lucide-icon ${className}`.trim()} size={size} strokeWidth={AUTH_ICON_STROKE} />
}

type BrandLogoProps = {
  compact?: boolean
}

export function BrandLogo({ compact = false }: BrandLogoProps) {
  return (
    <div className={compact ? 'auth-brand auth-brand--compact' : 'auth-brand'}>
      <span className="auth-brand__halo">
        <img alt="" aria-hidden="true" decoding="async" src={AUTH_BRAND_ICON_SRC} />
      </span>
      <div>
        <strong>Sol Restaurante</strong>
        <small>CRM operacional</small>
      </div>
    </div>
  )
}

type AuthInputProps = InputHTMLAttributes<HTMLInputElement> & {
  icon: AuthIconName
  inputId: string
  label: string
}

export function AuthInput({ icon, inputId, label, ...props }: AuthInputProps) {
  const IconComponent = authIcons[icon]

  return (
    <label className="auth-field" htmlFor={inputId}>
      <span>{label}</span>
      <span className="auth-field__control auth-input-shell">
        <LoginIcon IconComponent={IconComponent} />
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
      <span className="auth-field__control auth-input-shell">
        <LoginIcon IconComponent={Lock} />
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
          <LoginIcon IconComponent={isVisible ? EyeOff : Eye} />
        </button>
      </span>
    </label>
  )
}

type AuthSelectOption = {
  label: string
  value: string
}

type AuthSelectProps = {
  inputId: string
  label: string
  onChange: (value: string) => void
  options: AuthSelectOption[]
  value: string
}

export function AuthSelect({ inputId, label, onChange, options, value }: AuthSelectProps) {
  const generatedId = useId().replace(/:/g, '')
  const labelId = `${inputId}-label`
  const listboxId = `${inputId}-${generatedId}-listbox`
  const rootRef = useRef<HTMLDivElement>(null)
  const selectedIndex = Math.max(
    0,
    options.findIndex((option) => option.value === value),
  )
  const [isOpen, setIsOpen] = useState(false)
  const [activeIndex, setActiveIndex] = useState(selectedIndex)
  const selectedOption = options[selectedIndex] ?? options[0]

  useEffect(() => {
    if (!isOpen) {
      return undefined
    }

    function handlePointerDown(event: PointerEvent) {
      if (!rootRef.current?.contains(event.target as Node)) {
        setIsOpen(false)
      }
    }

    document.addEventListener('pointerdown', handlePointerDown)

    return () => document.removeEventListener('pointerdown', handlePointerDown)
  }, [isOpen])

  function openSelect() {
    setActiveIndex(selectedIndex)
    setIsOpen(true)
  }

  function selectOption(index: number) {
    const nextOption = options[index]

    if (!nextOption) {
      return
    }

    onChange(nextOption.value)
    setActiveIndex(index)
    setIsOpen(false)
  }

  function moveActiveIndex(direction: 1 | -1) {
    setActiveIndex((currentIndex) => {
      const optionCount = options.length

      if (optionCount === 0) {
        return 0
      }

      return (currentIndex + direction + optionCount) % optionCount
    })
  }

  function handleKeyDown(event: KeyboardEvent<HTMLButtonElement>) {
    if (event.key === 'ArrowDown') {
      event.preventDefault()

      if (!isOpen) {
        openSelect()
        return
      }

      moveActiveIndex(1)
    }

    if (event.key === 'ArrowUp') {
      event.preventDefault()

      if (!isOpen) {
        openSelect()
        return
      }

      moveActiveIndex(-1)
    }

    if (event.key === 'Home' && isOpen) {
      event.preventDefault()
      setActiveIndex(0)
    }

    if (event.key === 'End' && isOpen) {
      event.preventDefault()
      setActiveIndex(Math.max(0, options.length - 1))
    }

    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault()

      if (!isOpen) {
        openSelect()
        return
      }

      selectOption(activeIndex)
    }

    if (event.key === 'Escape' && isOpen) {
      event.preventDefault()
      setIsOpen(false)
    }
  }

  return (
    <div className="auth-field auth-select" onBlur={(event) => {
      if (!event.currentTarget.contains(event.relatedTarget as Node | null)) {
        setIsOpen(false)
      }
    }} ref={rootRef}>
      <span id={labelId}>{label}</span>
      <span className={isOpen ? 'auth-field__control auth-input-shell auth-select__control is-open' : 'auth-field__control auth-input-shell auth-select__control'}>
        <input name={inputId} type="hidden" value={selectedOption?.value ?? ''} />
        <button
          aria-activedescendant={isOpen ? `${listboxId}-option-${activeIndex}` : undefined}
          aria-controls={listboxId}
          aria-expanded={isOpen}
          aria-haspopup="listbox"
          aria-labelledby={`${labelId} ${inputId}`}
          className="auth-select__trigger"
          id={inputId}
          onClick={() => {
            if (isOpen) {
              setIsOpen(false)
              return
            }

            openSelect()
          }}
          onKeyDown={handleKeyDown}
          type="button"
        >
          <span>{selectedOption?.label}</span>
          <LoginIcon className="auth-select__chevron" IconComponent={ChevronDown} size={17} />
        </button>
        {isOpen ? (
          <div aria-labelledby={labelId} className="auth-select__menu" id={listboxId} role="listbox">
            {options.map((option, index) => (
              <button
                aria-selected={option.value === selectedOption?.value}
                className={index === activeIndex ? 'auth-select__option is-active' : 'auth-select__option'}
                id={`${listboxId}-option-${index}`}
                key={option.value}
                onClick={() => selectOption(index)}
                onMouseDown={(event) => event.preventDefault()}
                onMouseEnter={() => setActiveIndex(index)}
                role="option"
                tabIndex={-1}
                type="button"
              >
                <span>{option.label}</span>
                {option.value === selectedOption?.value ? <LoginIcon IconComponent={Check} size={15} /> : null}
              </button>
            ))}
          </div>
        ) : null}
      </span>
    </div>
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
    <section className="login-hero-panel auth-hero-panel" aria-label="Resumo visual do CRM operacional">
      <div className="login-hero-panel__glow" />
      <div className="login-brand-composition" aria-hidden="true">
        <div className="login-brand-composition__mesh" />
        <div className="login-brand-composition__halo" />
        <svg className="login-brand-composition__lines" fill="none" viewBox="0 0 560 520" xmlns="http://www.w3.org/2000/svg">
          <path d="M34 348C104 286 176 261 250 273C344 288 406 241 515 107" />
          <path d="M66 143C138 186 207 199 273 181C348 160 415 175 498 231" />
          <path d="M115 424C178 382 243 366 310 377C370 387 414 364 470 306" />
          <circle cx="168" cy="250" r="4" />
          <circle cx="386" cy="190" r="4" />
          <circle cx="432" cy="348" r="4" />
        </svg>
        <img
          alt=""
          className="login-brand-composition__logo"
          decoding="async"
          src={HERO_BRAND_LOGO_SRC}
        />
        <div className="login-brand-composition__panel login-brand-composition__panel--dashboard">
          <LoginIcon IconComponent={LayoutDashboard} size={16} />
          <span />
          <span />
        </div>
        <div className="login-brand-composition__panel login-brand-composition__panel--chat">
          <LoginIcon IconComponent={MessageCircle} size={16} />
          <span />
          <span />
        </div>
        <div className="login-brand-composition__panel login-brand-composition__panel--order">
          <LoginIcon IconComponent={ClipboardList} size={16} />
          <span />
          <span />
        </div>
        <div className="login-brand-composition__panel login-brand-composition__panel--clients">
          <LoginIcon IconComponent={Users} size={16} />
          <span />
          <span />
        </div>
        <div className="login-brand-composition__status login-brand-composition__analytics">
          <svg
            aria-hidden="true"
            className="login-brand-composition__chart"
            fill="none"
            viewBox="0 0 132 54"
            xmlns="http://www.w3.org/2000/svg"
          >
            <path className="login-brand-composition__chart-grid" d="M8 42H124M8 28H124M8 14H124" />
            <path className="login-brand-composition__chart-line" d="M10 38C23 32 28 18 42 22C55 26 57 38 72 34C88 30 88 13 103 16C113 18 119 11 124 8" />
            <circle cx="42" cy="22" r="2.8" />
            <circle cx="72" cy="34" r="2.8" />
            <circle cx="103" cy="16" r="2.8" />
          </svg>
        </div>
        <div className="login-brand-composition__node login-brand-composition__node--one" />
        <div className="login-brand-composition__node login-brand-composition__node--two" />
        <div className="login-brand-composition__node login-brand-composition__node--three" />
        <div className="login-brand-composition__pulse">
          <LoginIcon IconComponent={Bell} size={15} />
        </div>
      </div>

      <div className="login-hero-panel__copy">
        <span className="eyebrow">Gestao operacional</span>
        <h1>
          <span>Operacao mais clara.</span>
          <strong>Atendimento mais rapido.</strong>
        </h1>
        <p>Organize pedidos, cardapio e comandas em uma rotina mais simples e visual.</p>
        <span className="login-hero-panel__accent" />
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
    <Button className="auth-submit" disabled={isSubmitting} type="submit" variant="primary">
      {isSubmitting ? (
        <>
          <span className="auth-submit__loader" aria-hidden="true" />
          Entrando
        </>
      ) : (
        <>
          <LoginIcon IconComponent={LogIn} />
          Entrar no CRM
        </>
      )}
    </Button>
  )
}
