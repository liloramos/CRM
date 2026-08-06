import type { ChampsSearch, ChampsSearchResult } from '../services/champs.service'

type ChampsStatsProps = {
  isLocal: boolean
  results: ChampsSearchResult[]
  search: ChampsSearch | null
}

export function ChampsStats({ isLocal, results, search }: ChampsStatsProps) {
  const qualified = isLocal
    ? results.filter((result) => result.qualified).length
    : (search?.totalQualified ?? results.filter((result) => result.qualified).length)
  const averageScore = results.length
    ? Math.round(results.reduce((total, result) => total + result.score, 0) / results.length)
    : 0
  const ratedResults = results.filter((result) => result.lead?.rating !== null && result.lead?.rating !== undefined)
  const averageRating = ratedResults.length
    ? ratedResults.reduce((total, result) => total + (result.lead?.rating ?? 0), 0) / ratedResults.length
    : 0

  return (
    <section className="champs-stats" aria-label="Resumo da garimpagem">
      <Stat label="Descobertos" value={isLocal ? results.length : (search?.totalDiscovered ?? results.length)} />
      <Stat label={isLocal ? 'Nesta sessão' : 'Persistidos'} value={isLocal ? results.length : (search?.totalSaved ?? results.length)} />
      <Stat label="Qualificados" value={qualified} />
      <Stat label="Score médio" value={averageScore} suffix="/100" />
      <Stat label="Avaliação média" value={averageRating ? averageRating.toFixed(1) : '—'} suffix={averageRating ? '/5' : ''} />
    </section>
  )
}

function Stat({ label, value, suffix = '' }: { label: string; value: number | string; suffix?: string }) {
  return (
    <article className="champs-stat">
      <span>{label}</span>
      <strong>{value}{suffix}</strong>
    </article>
  )
}
