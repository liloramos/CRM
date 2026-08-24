# Product Clarifications

When the safe copilot result has purchase intent but no grounded product, the backend may return a read-only `clarification` sourced from the structured menu for the current company. A short numbered list is returned only when two to five catalog products match one explicit structured term from the customer message.

The clarification never creates an order, sends a WhatsApp message, or enables automatic mode. The current interface only lets the attendant copy the suggested reply into the composer.

A future operational step may offer an explicit `Enviar cardápio do dia` action. That action must use the same structured menu source and require an attendant decision; it must not generate or send an image automatically.

Replies such as `2` are intentionally not resolved in this phase. The option IDs and slugs remain in the structured clarification contract so a later, separately reviewed turn resolver can map a numeric reply safely.

## Level 1A proposal flow

The Level 1A copilot is read-only: Safe result -> Proposal -> Human review -> Local draft -> Manual save. It never sends a message, creates an order, confirms payment, or changes conversation mode.

`READY` means a safe local draft exists with no real missing information. Informational warnings, including a rejected ungrounded removal, remain visible for review but do not make the proposal partial. `PARTIAL` means information is still missing. `BLOCKED` means there is no safe item or a human must choose the target order.

Product clarification uses only the structured menu for the current company. A closed order creates a context boundary; no selection from that prior cycle is reused automatically. N8 Livre accepts one or two traditional meats, and an explicit `Sem carne` remains a separate structured choice. Dataset V8 remains deterministic and offline. Prices, payments, and order mutations stay outside the copilot authority.

## Operational-day context

Full conversation history remains persistent and visible. Only the recent operational order context sent to the copilot is bounded. When there is no active order, the boundary is the most recent of a closed order and the company's operational-day start, calculated in the company's timezone. `company_settings.settings.operational_day_start_time` is `00:00` for Restaurante Sol and can be set to `04:00` for a late-night operation.

An active order keeps its context across the boundary. Future customer preferences must remain separate from this operational slice and may only support an explicit question; they must never silently modify a new order.

## Resolved daily configuration

The copilot resolves each product from the existing structured product configuration and `DailyStructuredMenuService` for the operational date. N8 Livre and N9 Livre use the daily menu for traditional meats; their persisted meat rule remains one or two types, with `Sem carne` only when explicitly requested. N8 Casa remains a distinct product and never replaces N8 Livre after grounding.

Daily components are exposed with `AVAILABLE_TODAY`, `UNAVAILABLE_TODAY`, or `NOT_APPLICABLE`. The master document confirms that N8/N9 Livre use the complete daily menu, so purê de batata and salada de macarrão are reported as available when present in that menu. Their detailed non-meat cardinality is not inferred until it is represented by a persisted rule. Batata frita is reported as unavailable when absent from that day's menu.

`MENU_REQUEST` and `BUSINESS_HOURS_REQUEST` are deterministic intents based on the latest inbound message and therefore do not revive an older incomplete order. The documented restaurant window is 10:00–14:00, but the backend only answers open/closed after valid `operating_hours` records exist; otherwise it asks to confirm the hours.
