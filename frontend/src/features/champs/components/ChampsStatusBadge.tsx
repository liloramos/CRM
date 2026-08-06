import type { ChampsSearchStatus } from '../services/champs.service'
import { searchStatusPresentation } from '../utils/champs-results'

export function ChampsStatusBadge({ status }: { status: ChampsSearchStatus }) {
  const presentation = searchStatusPresentation(status)

  return (
    <span className={`champs-status champs-status--${presentation.tone}`}>
      <span aria-hidden="true" className="champs-status__dot" />
      {presentation.label}
    </span>
  )
}
