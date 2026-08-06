import {
  CHAMPS_MAX_RESULTS,
  type CreateChampsSearchPayload,
} from '../services/champs.service'

export type SearchFormErrors = Partial<
  Record<'niche' | 'city' | 'state' | 'limit' | 'minimumScore', string>
>

export function validateSearchPayload(
  payload: CreateChampsSearchPayload,
  maxResults = CHAMPS_MAX_RESULTS,
): SearchFormErrors {
  const errors: SearchFormErrors = {}

  if (payload.niche.length < 2 || payload.niche.length > 120) {
    errors.niche = 'Informe um nicho entre 2 e 120 caracteres.'
  }

  if (payload.city.length < 2 || payload.city.length > 120) {
    errors.city = 'Informe uma cidade entre 2 e 120 caracteres.'
  }

  if (!/^[A-Z]{2}$/.test(payload.state)) {
    errors.state = 'Selecione uma UF válida.'
  }

  if (!Number.isInteger(payload.limit) || payload.limit < 1 || payload.limit > maxResults) {
    errors.limit = `Informe uma quantidade entre 1 e ${maxResults}.`
  }

  if (!Number.isInteger(payload.minimumScore) || payload.minimumScore < 0 || payload.minimumScore > 100) {
    errors.minimumScore = 'Informe um score entre 0 e 100.'
  }

  return errors
}
