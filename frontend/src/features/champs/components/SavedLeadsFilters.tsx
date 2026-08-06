import { RotateCcw, SlidersHorizontal } from 'lucide-react'
import type { ChampsLeadAssignee, ChampsLeadPriority, ChampsLeadStage } from '../services/champs.service'
import type { SavedLeadsFilters } from '../services/saved-leads.service'
import { LEAD_PRIORITY_LABELS, LEAD_STAGE_LABELS } from '../utils/saved-leads'
import { FilterSelect, type FilterSelectOption } from './FilterSelect'

type SavedLeadsFiltersProps = {
  assignees: ChampsLeadAssignee[]
  filters: SavedLeadsFilters
  onChange: (filters: SavedLeadsFilters) => void
  onClear: () => void
}

const stageOptions: FilterSelectOption[] = [
  { label: 'Todas as etapas', value: '' },
  ...Object.entries(LEAD_STAGE_LABELS).map(([value, label]) => ({ label, value })),
]

const priorityOptions: FilterSelectOption[] = [
  { label: 'Todas as prioridades', value: '' },
  ...Object.entries(LEAD_PRIORITY_LABELS).map(([value, label]) => ({ label, value })),
]

const followUpOptions: FilterSelectOption[] = [
  { label: 'Todos os acompanhamentos', value: '' },
  { label: 'Vencidos', value: 'overdue' },
  { label: 'Hoje', value: 'today' },
  { label: 'Próximos', value: 'upcoming' },
  { label: 'Sem acompanhamento', value: 'none' },
]

const presenceOptions: FilterSelectOption[] = [
  { label: 'Todos', value: '' },
  { label: 'Com informação', value: 'true' },
  { label: 'Sem informação', value: 'false' },
]

const orderOptions: FilterSelectOption[] = [
  { label: 'Atualização recente', value: 'updated_at' },
  { label: 'Maior score', value: 'score' },
  { label: 'Próximo acompanhamento', value: 'next_follow_up_at' },
  { label: 'Maior prioridade', value: 'priority' },
  { label: 'Nome', value: 'name' },
]

export function SavedLeadsFilters({ assignees, filters, onChange, onClear }: SavedLeadsFiltersProps) {
  function patch(next: Partial<SavedLeadsFilters>) {
    onChange({ ...filters, ...next, page: 1 })
  }

  return (
    <section aria-label="Filtros dos leads salvos" className="saved-leads-filters">
      <div className="saved-leads-filters__topline">
        <div className="saved-leads-archive-filter" role="group" aria-label="Situação dos leads">
          <ArchiveFilterButton active={filters.archived === 'active'} onClick={() => patch({ archived: 'active' })} label="Ativos" />
          <ArchiveFilterButton active={filters.archived === 'only'} onClick={() => patch({ archived: 'only' })} label="Arquivados" />
        </div>
        <button className="saved-leads-clear" onClick={onClear} type="button">
          <RotateCcw aria-hidden="true" size={15} />
          Limpar filtros
        </button>
      </div>

      <div className="saved-leads-filters__grid">
        <label className="champs-filter-field saved-leads-filter--query">
          <span className="champs-filter-field__label"><SlidersHorizontal aria-hidden="true" size={14} /> Buscar</span>
          <input
            onChange={(event) => patch({ query: event.target.value })}
            placeholder="Nome, cidade, contato ou nota"
            type="search"
            value={filters.query ?? ''}
          />
        </label>
        <FilterSelect
          label="Etapa"
          onChange={(value) => patch({ pipelineStage: value ? value as ChampsLeadStage : undefined })}
          options={stageOptions}
          value={filters.pipelineStage ?? ''}
        />
        <FilterSelect
          label="Prioridade"
          onChange={(value) => patch({ priority: value ? value as ChampsLeadPriority : undefined })}
          options={priorityOptions}
          value={filters.priority ?? ''}
        />
        <FilterSelect
          label="Responsável"
          onChange={(value) => patch({ assignedUserId: value === '' ? undefined : value === 'unassigned' ? 'unassigned' : Number(value) })}
          options={[
            { label: 'Todos os responsáveis', value: '' },
            { label: 'Sem responsável', value: 'unassigned' },
            ...assignees.map((assignee) => ({ label: assignee.name, value: String(assignee.id) })),
          ]}
          value={filters.assignedUserId === undefined ? '' : String(filters.assignedUserId)}
        />
        <label className="champs-filter-field">
          <span className="champs-filter-field__label">Estado</span>
          <input
            maxLength={2}
            onChange={(event) => patch({ state: event.target.value.toUpperCase() || undefined })}
            placeholder="SP"
            value={filters.state ?? ''}
          />
        </label>
        <label className="champs-filter-field">
          <span className="champs-filter-field__label">Score mínimo</span>
          <input
            max={100}
            min={0}
            onChange={(event) => {
              const value = event.target.valueAsNumber
              patch({ minimumScore: Number.isFinite(value) ? value : undefined })
            }}
            placeholder="0"
            type="number"
            value={filters.minimumScore ?? ''}
          />
        </label>
        <FilterSelect
          label="Acompanhamento"
          onChange={(value) => patch({ followUp: value ? value as SavedLeadsFilters['followUp'] : undefined })}
          options={followUpOptions}
          value={filters.followUp ?? ''}
        />
        <FilterSelect
          label="Instagram"
          onChange={(value) => patch({ hasInstagram: parsePresence(value) })}
          options={presenceOptions}
          value={formatPresence(filters.hasInstagram)}
        />
        <FilterSelect
          label="Website"
          onChange={(value) => patch({ hasWebsite: parsePresence(value) })}
          options={presenceOptions}
          value={formatPresence(filters.hasWebsite)}
        />
        <FilterSelect
          label="Ordenar por"
          onChange={(value) => patch({ orderBy: value as SavedLeadsFilters['orderBy'] })}
          options={orderOptions}
          value={filters.orderBy ?? 'updated_at'}
        />
      </div>
    </section>
  )
}

function ArchiveFilterButton({ active, label, onClick }: { active: boolean; label: string; onClick: () => void }) {
  return (
    <button aria-pressed={active} className={active ? 'is-active' : undefined} onClick={onClick} type="button">
      {label}
    </button>
  )
}

function parsePresence(value: string): boolean | undefined {
  if (value === 'true') {
    return true
  }

  if (value === 'false') {
    return false
  }

  return undefined
}

function formatPresence(value: boolean | undefined): string {
  return value === undefined ? '' : String(value)
}
