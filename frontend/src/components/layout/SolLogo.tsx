type SolLogoProps = {
  compact?: boolean
}

export function SolLogo({ compact = false }: SolLogoProps) {
  return (
    <div className="sol-logo">
      <img
        className={compact ? 'sol-logo__compact' : 'sol-logo__navbar'}
        src={compact ? '/imgs/logo-square-mark-clean.png' : '/imgs/logo-deitada-navbar-transparent.png'}
        alt="Sol Restaurante"
      />
    </div>
  )
}
