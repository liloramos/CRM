import { LoaderCircle, Search } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import {
  CHAMPS_MAX_RESULTS,
  type CreateChampsSearchPayload,
} from '../services/champs.service'
import { validateSearchPayload, type SearchFormErrors } from '../utils/champs-search'
import { FilterSelect, type FilterSelectOption } from './FilterSelect'

const STATE_OPTIONS: FilterSelectOption[] = [
  { label: 'Acre (AC)', value: 'AC' },
  { label: 'Alagoas (AL)', value: 'AL' },
  { label: 'Amapá (AP)', value: 'AP' },
  { label: 'Amazonas (AM)', value: 'AM' },
  { label: 'Bahia (BA)', value: 'BA' },
  { label: 'Ceará (CE)', value: 'CE' },
  { label: 'Distrito Federal (DF)', value: 'DF' },
  { label: 'Espírito Santo (ES)', value: 'ES' },
  { label: 'Goiás (GO)', value: 'GO' },
  { label: 'Maranhão (MA)', value: 'MA' },
  { label: 'Mato Grosso (MT)', value: 'MT' },
  { label: 'Mato Grosso do Sul (MS)', value: 'MS' },
  { label: 'Minas Gerais (MG)', value: 'MG' },
  { label: 'Pará (PA)', value: 'PA' },
  { label: 'Paraíba (PB)', value: 'PB' },
  { label: 'Paraná (PR)', value: 'PR' },
  { label: 'Pernambuco (PE)', value: 'PE' },
  { label: 'Piauí (PI)', value: 'PI' },
  { label: 'Rio de Janeiro (RJ)', value: 'RJ' },
  { label: 'Rio Grande do Norte (RN)', value: 'RN' },
  { label: 'Rio Grande do Sul (RS)', value: 'RS' },
  { label: 'Rondônia (RO)', value: 'RO' },
  { label: 'Roraima (RR)', value: 'RR' },
  { label: 'Santa Catarina (SC)', value: 'SC' },
  { label: 'São Paulo (SP)', value: 'SP' },
  { label: 'Sergipe (SE)', value: 'SE' },
  { label: 'Tocantins (TO)', value: 'TO' },
]

const LIMIT_PRESETS = [5, 10, 20]

type ChampsSearchFormProps = {
  isSubmitting: boolean
  maxResults?: number
  onSubmit: (payload: CreateChampsSearchPayload) => void
}

export function ChampsSearchForm({
  isSubmitting,
  maxResults = CHAMPS_MAX_RESULTS,
  onSubmit,
}: ChampsSearchFormProps) {
  const [name, setName] = useState('')
  const [niche, setNiche] = useState('')
  const [city, setCity] = useState('')
  const [state, setState] = useState('SP')
  const [limit, setLimit] = useState(Math.min(20, maxResults))
  const [minimumScore, setMinimumScore] = useState(0)
  const [excludeSeen, setExcludeSeen] = useState(true)
  const [errors, setErrors] = useState<SearchFormErrors>({})
  const limitOptions = getLimitOptions(maxResults)

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    const payload: CreateChampsSearchPayload = {
      name: name.trim() || undefined,
      niche: niche.trim(),
      city: city.trim(),
      state,
      limit,
      minimumScore,
      excludeSeen,
    }
    const validationErrors = validateSearchPayload(payload, maxResults)

    setErrors(validationErrors)

    if (Object.keys(validationErrors).length > 0) {
      return
    }

    onSubmit(payload)
  }

  return (
    <section className="champs-panel champs-search-panel" aria-labelledby="champs-search-title">
      <div className="champs-panel__header">
        <div>
          <span className="champs-section-kicker">NOVA GARIMPAGEM</span>
          <h2 id="champs-search-title">Encontre oportunidades reais</h2>
          <p>Defina o segmento e a praça para consultar e qualificar estabelecimentos.</p>
        </div>
      </div>

      <form aria-busy={isSubmitting} className="champs-search-form" noValidate onSubmit={handleSubmit}>
        <div className="champs-form-grid">
          <label className="champs-field champs-field--name">
            Nome da busca <span>Opcional</span>
            <input
              disabled={isSubmitting}
              maxLength={120}
              onChange={(event) => setName(event.target.value)}
              placeholder="Ex.: Clínicas premium em SP"
              type="text"
              value={name}
            />
          </label>

          <label className="champs-field champs-field--niche">
            Nicho
            <input
              aria-describedby={errors.niche ? 'champs-niche-error' : undefined}
              aria-invalid={Boolean(errors.niche)}
              disabled={isSubmitting}
              maxLength={120}
              onChange={(event) => setNiche(event.target.value)}
              placeholder="Ex.: clínica de estética"
              required
              type="text"
              value={niche}
            />
            {errors.niche ? <small id="champs-niche-error">{errors.niche}</small> : null}
          </label>

          <label className="champs-field champs-field--city">
            Cidade
            <input
              aria-describedby={errors.city ? 'champs-city-error' : undefined}
              aria-invalid={Boolean(errors.city)}
              disabled={isSubmitting}
              maxLength={120}
              onChange={(event) => setCity(event.target.value)}
              placeholder="Ex.: São Paulo"
              required
              type="text"
              value={city}
            />
            {errors.city ? <small id="champs-city-error">{errors.city}</small> : null}
          </label>

          <div className="champs-field champs-field--state">
            <FilterSelect label="Estado" onChange={setState} options={STATE_OPTIONS} value={state} />
            {errors.state ? <small>{errors.state}</small> : null}
          </div>

          <div className="champs-field champs-field--limit">
            <FilterSelect
              disabled={isSubmitting}
              hint={`Máx. ${maxResults}`}
              label="Quantidade"
              onChange={(value) => setLimit(Number(value))}
              options={limitOptions}
              value={String(limit)}
            />
            {errors.limit ? <small id="champs-limit-error">{errors.limit}</small> : null}
          </div>

          <label className="champs-field champs-field--score">
            Score mínimo
            <input
              aria-describedby={errors.minimumScore ? 'champs-score-error' : undefined}
              aria-invalid={Boolean(errors.minimumScore)}
              disabled={isSubmitting}
              max={100}
              min={0}
              onChange={(event) => setMinimumScore(event.target.valueAsNumber)}
              required
              type="number"
              value={Number.isNaN(minimumScore) ? '' : minimumScore}
            />
            {errors.minimumScore ? <small id="champs-score-error">{errors.minimumScore}</small> : null}
          </label>
        </div>

        <div className="champs-search-form__footer">
          <div className="champs-progressive-option">
            <label>
              <input
                checked={excludeSeen}
                disabled={isSubmitting}
                onChange={(event) => setExcludeSeen(event.target.checked)}
                type="checkbox"
              />
              <span>Ocultar empresas já encontradas</span>
            </label>
            <p>Desmarque para permitir empresas apresentadas em buscas anteriores.</p>
          </div>
          <button className="champs-button champs-button--primary champs-search-submit" disabled={isSubmitting} type="submit">
            {isSubmitting ? (
              <LoaderCircle aria-hidden="true" className="champs-spin" size={18} />
            ) : (
              <Search aria-hidden="true" size={18} />
            )}
            {isSubmitting ? 'Garimpando...' : 'Garimpar leads'}
          </button>
        </div>
      </form>
    </section>
  )
}

function getLimitOptions(maxResults: number): FilterSelectOption[] {
  const values = LIMIT_PRESETS.filter((value) => value <= maxResults)

  if (maxResults > 0 && !values.includes(maxResults)) {
    values.push(maxResults)
  }

  return values
    .sort((first, second) => first - second)
    .map((value) => ({ label: `${value} leads`, value: String(value) }))
}
