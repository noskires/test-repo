# 17 — EIC reviews the FSR and BOQ

Status: ready-for-agent
Blocked by: 11, 16
Type: task

## What to build

PRD §7.4: the E-Call's EIC evaluates the latest FSR/BOQ:
- **Approve**: E-Call Status → `Approved`. Work Order creation is now possible (issue 18).
- **Return**: → `Returned`, back to the Site Vendor for revision. Remarks are required.
- **Reject**: → `Rejected`. This is final (glossary: to try again, file a new E-Call). Remarks are required.

Each action writes an Approval Record (issue 11's mechanism).

Endpoints: `POST /api/e-calls/{eCall}/approve`, `/return`, `/reject`.

## Acceptance criteria

- [ ] Only the E-Call's EIC can act. Others get 403.
- [ ] Actions only work from `For EIC Review`. Otherwise 422.
- [ ] Approve sets `Approved`. Return sets `Returned`. Reject sets `Rejected`. Each writes an Approval Record with previous and resulting status.
- [ ] Return and Reject without remarks return 422.
- [ ] After a Reject, every further action on that E-Call (proposal, approve, Work Order creation) returns 422.

## Comments
- 2026-11-22 (product-owner): Human decision (issue 11 Decisions, spec.md): when a Site's EIC is replaced, the **current Site EIC** (`site_settings.eic_employee_id` at the time of the request) acts on pending items. The EIC captured on the record is history only and isn't used for authorization. The reviewer is the current Site EIC of the E-Call's Site, not the `eic_employee_id` captured at filing (issue 12). Add a test where the EIC is replaced while the E-Call is `For EIC Review`: the new EIC can act, and the old one gets 403.
