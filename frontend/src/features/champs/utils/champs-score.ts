export type LeadClassification = 'Baixo potencial' | 'Potencial médio' | 'Bom potencial' | 'Alta prioridade'

export type ScoreCriterion =
  | 'location_sp_rj'
  | 'website'
  | 'contact_phone'
  | 'contact_email'
  | 'business_profile'
  | 'recent_activity'
  | 'followers'
  | 'structured_profile'

export type ScoreInput = {
  displayName: string
  city: string
  state: string
  followersCount: number
  website: string
  phone: string
  email: string
  recentPostsCount: number
  isBusinessProfile: boolean
}

export type ScoreResult = {
  score: number
  classification: LeadClassification
  reasons: string[]
  appliedCriteria: ScoreCriterion[]
}

export function calculateScore(lead: ScoreInput): ScoreResult {
  let score = 0
  const reasons: string[] = []
  const appliedCriteria: ScoreCriterion[] = []

  function apply(points: number, criterion: ScoreCriterion, reason: string) {
    score += points
    appliedCriteria.push(criterion)
    reasons.push(reason)
  }

  if (lead.state === 'SP' || lead.state === 'RJ') {
    apply(25, 'location_sp_rj', 'Localizado em SP ou RJ')
  }

  if (lead.website) {
    apply(15, 'website', 'Possui site próprio')
  }

  if (lead.phone) {
    apply(10, 'contact_phone', 'Telefone ou WhatsApp identificado')
  }

  if (lead.email) {
    apply(10, 'contact_email', 'E-mail comercial identificado')
  }

  if (lead.isBusinessProfile) {
    apply(10, 'business_profile', 'Perfil comercial')
  }

  if (lead.recentPostsCount >= 6) {
    apply(10, 'recent_activity', 'Publicação frequente')
  }

  if (lead.followersCount >= 5000) {
    apply(10, 'followers', 'Audiência relevante')
  }

  if (lead.displayName && lead.city) {
    apply(10, 'structured_profile', 'Cadastro estruturado')
  }

  const cappedScore = Math.min(score, 100)

  return {
    score: cappedScore,
    classification: classifyScore(cappedScore),
    reasons,
    appliedCriteria,
  }
}

export function classifyScore(score: number): LeadClassification {
  if (score >= 85) {
    return 'Alta prioridade'
  }

  if (score >= 70) {
    return 'Bom potencial'
  }

  if (score >= 40) {
    return 'Potencial médio'
  }

  return 'Baixo potencial'
}

export function scoreTone(score: number): 'low' | 'medium' | 'good' | 'high' {
  if (score >= 85) {
    return 'high'
  }

  if (score >= 70) {
    return 'good'
  }

  if (score >= 40) {
    return 'medium'
  }

  return 'low'
}
