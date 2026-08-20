# Product Clarifications

When the safe copilot result has purchase intent but no grounded product, the backend may return a read-only `clarification` sourced from the structured menu for the current company. A short numbered list is returned only when two to five catalog products match one explicit structured term from the customer message.

The clarification never creates an order, sends a WhatsApp message, or enables automatic mode. The current interface only lets the attendant copy the suggested reply into the composer.

A future operational step may offer an explicit `Enviar cardápio do dia` action. That action must use the same structured menu source and require an attendant decision; it must not generate or send an image automatically.

Replies such as `2` are intentionally not resolved in this phase. The option IDs and slugs remain in the structured clarification contract so a later, separately reviewed turn resolver can map a numeric reply safely.

## Level 1A proposal flow

The Level 1A copilot is read-only: Safe result -> Proposal -> Human review -> Local draft -> Manual save. It never sends a message, creates an order, confirms payment, or changes conversation mode.

`READY` means a safe local draft exists with no real missing information. Informational warnings, including a rejected ungrounded removal, remain visible for review but do not make the proposal partial. `PARTIAL` means information is still missing. `BLOCKED` means there is no safe item or a human must choose the target order.

Product clarification uses only the structured menu for the current company. A closed order creates a context boundary; no selection from that prior cycle is reused automatically. N8 Livre accepts one or two traditional meats, and an explicit `Sem carne` remains a separate structured choice. Dataset V8 remains deterministic and offline. Prices, payments, and order mutations stay outside the copilot authority.
