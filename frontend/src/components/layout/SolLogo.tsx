type SolLogoProps = {
  compact?: boolean
}

export function SolLogo({ compact = false }: SolLogoProps) {
  return (
    <div
      aria-label="Champs"
      style={{
        display: 'flex',
        minHeight: compact ? 54 : 76,
        alignItems: 'center',
        justifyContent: 'center',
        padding: compact ? 8 : 18,
      }}
    >
      <span
        style={{
          color: '#6246ea',
          fontSize: compact ? 22 : 26,
          fontWeight: 900,
          letterSpacing: compact ? '-0.06em' : '0.08em',
        }}
      >
        {compact ? 'C' : 'CHAMPS'}
      </span>
    </div>
  )
}