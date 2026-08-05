type SolLogoProps = {
  compact?: boolean
}

export function SolLogo({ compact = false }: SolLogoProps) {
  return (
    <div className="sol-logo" aria-label="Marcelo Quessada">
      {compact ? (
        <img className="sol-logo__compact" src="/favicon.png" alt="Monograma MQ" decoding="async" />
      ) : (
        <img
          className="sol-logo__navbar"
          src="/imgs/logo.png"
          alt="Marcelo Quessada — Gestor de Tráfego"
          decoding="async"
        />
      )}
    </div>
  )
}
