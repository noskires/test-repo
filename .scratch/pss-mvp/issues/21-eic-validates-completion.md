# 21 — EIC validates completed work and sets the Billing Amount

Status: ready-for-agent
Blocked by: 20
Type: task

## What to build

PRD §8.4, glossary "Billing Amount": the Work Order's EIC inspects an `Implemented` Work Order:
- Sees approved vs vendor-reported actual quantities for each line.
- **Approve**: the EIC confirms (and may correct) the **validated actual quantity** for each line. The **Billing Amount** = sum of validated qty x line unit price. Status → `For Billing Endorsement LVL1`.
- **Return**: → `For Implementation` for rework. Remarks are required.
- **Reject**: → `Rejected`, final. Remarks are required.
- Each action writes an Approval Record.

Endpoints: `POST /api/work-orders/{wo}/validate-completion` (approve, with validated quantities), `/return-completion`, `/reject-completion`.

## Acceptance criteria

- [ ] Only the Work Order's EIC can act. Others get 403. Actions only work from `Implemented`.
- [ ] Approve requires a validated qty >= 0 for every line. The Billing Amount is the exact sum of validated qty x unit price, rounded once to the whole centavo, half-up (ADR 0002).
- [ ] The Billing Amount is stored separately from the estimated (approved) amount. Both are readable on the Work Order.
- [ ] Return sets `For Implementation`. Reject sets `Rejected`. Remarks are required for both.
- [ ] Approval Records are written for every action.

## Comments
- 2026-11-22 (product-owner): Rounding decided by the human (docs/adr/0002-money-rounding.md). Per-line amounts stay exact, and only the Billing Amount is rounded to the centavo, half-up. Acceptance criterion updated.
- 2026-11-22 (product-owner): Human decision (issue 11 Decisions, spec.md): when a Site's EIC is replaced, the **current Site EIC** (`site_settings.eic_employee_id` at the time of the request) acts on pending items. The EIC captured on the record is history only and isn't used for authorization. "The Work Order's EIC" means the current Site EIC of its Site. Add a test where the EIC is replaced while the Work Order is `Implemented`: the new EIC can act, and the old one gets 403.
