# 18 — Create a Work Order from an approved E-Call

Status: ready-for-agent
Blocked by: 17
Type: task

## What to build

PRD §8.1, glossary "Work Order", "Open (Work Order)":
- The E-Call's Requestor or EIC creates a **Work Order** from an `Approved` E-Call. Exactly one Work Order per E-Call.
- **Creating it closes the E-Call** (E-Call Status → `Closed`). Additional work needs a new E-Call.
- The Work Order starts as `Open` (a draft). Its creator can edit it until they submit it (submission is issue 19).
- Lines are selected from the approved BOQ's lines (scope of work and materials). Each has an approved quantity. The unit price comes from the BOQ line. Line total and the overall estimated amount are computed by the server.
- Supporting attachments.
- References the E-Call, the approved FSR and the BOQ revision.

Endpoints: `POST /api/e-calls/{eCall}/work-order`, `PATCH /api/work-orders/{wo}` (while `Open`), `GET /api/work-orders/{wo}`.

## Acceptance criteria

- [ ] Creating from an E-Call that isn't `Approved` returns 422. A second Work Order for the same E-Call returns 422.
- [ ] Only the E-Call's Requestor can create. Others, including the EIC, get 403 (see Decisions).
- [ ] On creation, the E-Call is `Closed` and the Work Order is `Open`, linked to the E-Call, FSR and BOQ revision.
- [ ] A line referencing something not in the approved BOQ returns 422. A quantity <= 0, or greater than that BOQ line's quantity, returns 422.
- [ ] The same BOQ line can't appear twice on one Work Order (422). Otherwise splitting a line would get round the quantity cap. Since a BOQ revision has no duplicate Price List items (issue 16), this also means each Price List item appears at most once.
- [ ] Line totals are exact and computed by the server. The estimated amount is their exact sum, rounded once to the whole centavo, half-up (ADR 0002).
- [ ] Only the creator can PATCH, and only while `Open`. Otherwise 403 / 422.

## Open questions (non-blocking, for the human)

- Can the approved quantity on a Work Order line be **greater** than the BOQ quantity? This slice only requires qty > 0. A cap can be added later if needed.

## Comments

## Decisions (grilling, 2026-11-22) — supersedes the "Requestor or EIC" rule above
- **Only the E-Call's Requestor** (the person who filed it) creates the Work Order. The EIC does not. Others, including the EIC, get 403.
- Quantity question answered: a Work Order line's approved quantity is **capped at the approved BOQ quantity** (0 < qty ≤ BOQ qty, otherwise 422). More work needs a revised BOQ or a new E-Call.
- 2026-11-22 (product-owner): Rounding decided by the human (docs/adr/0002-money-rounding.md). Line totals stay exact, and only the estimated amount is rounded to the centavo, half-up. Acceptance criterion updated. Also note that the open question about quantity > BOQ quantity is answered in PRD v2 §8 ("Line quantities must be > 0 and ≤ the approved BOQ quantity"). Re-check it, and add the cap to the acceptance criteria when this issue is picked up.
- 2026-11-22 (product-owner): The human confirmed that a BOQ revision can't repeat a Price List item (issue 16). I applied the same rule here: each approved BOQ line appears at most once on a Work Order (new criterion), because a repeated line would get round the quantity cap. I also brought the criteria in line with the Decisions above: only the Requestor creates, and quantity ≤ the BOQ quantity. The status is unchanged (ready-for-agent).
