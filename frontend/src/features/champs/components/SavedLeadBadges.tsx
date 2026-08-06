import type { ChampsLeadPriority, ChampsLeadStage } from '../services/champs.service'
import { LEAD_PRIORITY_LABELS, LEAD_STAGE_LABELS } from '../utils/saved-leads'

export function LeadStageBadge({ stage }: { stage: ChampsLeadStage }) {
  return <span className={`saved-lead-badge saved-lead-badge--stage-${stage}`}>{LEAD_STAGE_LABELS[stage]}</span>
}

export function LeadPriorityBadge({ priority }: { priority: ChampsLeadPriority }) {
  return <span className={`saved-lead-badge saved-lead-badge--priority-${priority}`}>{LEAD_PRIORITY_LABELS[priority]}</span>
}
