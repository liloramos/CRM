import { RotateCcw } from 'lucide-react'
import type { ChampsStateFilter } from '../utils/champs-filters'
import type {
  ChampsQualificationFilter,
  ChampsResultFilters,
  ChampsResultsOrder,
} from '../utils/champs-results'
import { FilterSelect, type FilterSelectOption } from './FilterSelect'

const STATE_OPTIONS: FilterSelectOption[] = [
  { label: 'Todos', value: 'TODOS' },
  { label: 'São Paulo', value: 'SP' },
  { label: 'Rio de Janeiro', value: 'RJ' },
  { label: 'Outros estados', value: 'OUTROS' },
]

const QUALIFICATION_OPTIONS: FilterSelectOption[] = [
  { label: 'Todos', value: 'TODOS' },
  { label: 'Somente qualificados', value: 'QUALIFICADOS' },
  { label: 'Não qualificados', value: 'NAO_QUALIFICADOS' },
]

const ORDER_OPTIONS: FilterSelectOption[] = [
  { label: 'Maior score', value: 'SCORE_DESC' },
  { label: 'Menor score', value: 'SCORE_ASC' },
  { label: 'Maior avaliação', value: 'RATING_DESC' },
  { label: 'Menor avaliação', value: 'RATING_ASC' },
]

type ChampsLeadFiltersProps = {
  filters: ChampsResultFilters
  onChange: (filters: ChampsResultFilters) => void
}

export function ChampsLeadFilters({ filters, onChange }: ChampsLeadFiltersProps) {
  function resetFilters() {
    onChange({
      stateFilter: 'TODOS',
      qualificationFilter: 'TODOS',
      minimumScore: 0,
      query: '',
      order: 'SCORE_DESC',
    })
  }

  return (
    <div className="champs-filters champs-filters--api">
      <label className="champs-filter-search">
        Buscar
        <input
          maxLength={120}
          onChange={(event) => onChange({ ...filters, query: event.target.value })}
          placeholder="Nome, endereço, cidade, contato..."
          type="search"
          value={filters.query}
        />
      </label>

      <FilterSelect
        label="Qualificação"
        onChange={(value) => onChange({
          ...filters,
          qualificationFilter: value as ChampsQualificationFilter,
        })}
        options={QUALIFICATION_OPTIONS}
        value={filters.qualificationFilter}
      />

      <FilterSelect
        label="Estado"
        onChange={(value) => onChange({ ...filters, stateFilter: value as ChampsStateFilter })}
        options={STATE_OPTIONS}
        value={filters.stateFilter}
      />

      <label>
        Score mínimo
        <input
          max={100}
          min={0}
          onChange={(event) => onChange({
            ...filters,
            minimumScore: normalizeMinimumScore(event.target.valueAsNumber),
          })}
          type="number"
          value={filters.minimumScore}
        />
      </label>

      <FilterSelect
        label="Ordenar por"
        onChange={(value) => onChange({ ...filters, order: value as ChampsResultsOrder })}
        options={ORDER_OPTIONS}
        value={filters.order}
      />

      <button className="champs-filter-reset" onClick={resetFilters} type="button">
        <RotateCcw aria-hidden="true" size={15} />
        Limpar filtros
      </button>
    </div>
  )
}

function normalizeMinimumScore(value: number): number {
  if (!Number.isFinite(value)) {
    return 0
  }

  return Math.min(100, Math.max(0, Math.trunc(value)))
}
