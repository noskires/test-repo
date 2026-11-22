# 18 — Create a Work Order from an approved E-Call

Status: ready-for-agent
Blocked by: 17, 37
Type: task

## What to build

PRD §8.1, glossary "Work Order", "Open (Work Order)":
- The E-Call's **Requestor** creates a **Work Order** from an `Approved` E-Call (see Decisions). Exactly one Work Order per E-Call.
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

## Comments

## Decisions (grilling, 2026-11-22) — supersedes the "Requestor or EIC" rule above
- **Only the E-Call's Requestor** (the person who filed it) creates the Work Order. The EIC does not. Others, including the EIC, get 403.
- Quantity question answered: a Work Order line's approved quantity is **capped at the approved BOQ quantity** (0 < qty ≤ BOQ qty, otherwise 422). More work needs a revised BOQ or a new E-Call.
- 2026-11-22 (product-owner): Rounding decided by the human (docs/adr/0002-money-rounding.md). Line totals stay exact, and only the estimated amount is rounded to the centavo, half-up. Acceptance criterion updated. Also note that the open question about quantity > BOQ quantity is answered in PRD v2 §8 ("Line quantities must be > 0 and ≤ the approved BOQ quantity"). Re-check it, and add the cap to the acceptance criteria when this issue is picked up.
- 2026-11-22 (product-owner): The human confirmed that a BOQ revision can't repeat a Price List item (issue 16). I applied the same rule here: each approved BOQ line appears at most once on a Work Order (new criterion), because a repeated line would get round the quantity cap. I also brought the criteria in line with the Decisions above: only the Requestor creates, and quantity ≤ the BOQ quantity. The status is unchanged (ready-for-agent).
- 2026-11-22 (product-owner): Re-checked before hand-off (main 75de780). Blockers 17 and 37 are merged. Still ready-for-agent. I removed the stale "Open questions" section (it's answered: qty ≤ BOQ qty) and the stale "Requestor or EIC" wording. Clarifications, scope unchanged:
  - **Source**: build from `e_calls.approved_proposal_id` (issue 17). That proposal revision **is** "the approved FSR and BOQ revision". Store a reference to it on the Work Order. Lines may only reference `e_call_proposal_lines` of that proposal. Lines from an earlier or other revision return 422.
  - **Who creates**: only the user whose ID is `e_calls.requestor_user_id`. Everyone else gets 403, including the Site EIC and vendor staff. Re-check it, together with the `Approved` status and "no Work Order yet", **inside a transaction that locks the E-Call row**. Add a DB unique constraint on `work_orders.e_call_id` as the final guard against two concurrent creates (test: a second create returns 422).
  - **Lines**: at least one line. Each line has a BOQ line ID and a quantity with 0 < qty ≤ that BOQ line's quantity, up to 3 decimals (reuse `App\Billing\BoqQuantity` and `pss.boq.quantity_decimal_places`). A BOQ line appears at most once. The Requestor may leave some BOQ lines out. Copy the description, unit and `unit_price_centavos` from the BOQ line.
  - **Money (ADR 0002)**: line totals are exact (millicentavos, as in issue 16, via `BoqQuantity::lineTotalMillicentavos`). The estimated amount is their exact sum, rounded once, half-up, to integer centavos. No floats. Test it with a case where rounding per line would differ (e.g. two lines of 50.5 centavos → 101).
  - **Status**: add a `WorkOrderStatus` enum. This slice only needs `Open`. Later issues add the rest. Creating the Work Order sets the E-Call to `Closed` in the same transaction.
  - **Approval Records**: none here. Creating a Work Order or closing the E-Call isn't an Approve/Return/Reject/cancel (GLOSSARY "Approval Record"). Endorsement records start in issue 19, which adds the `work_order` morph alias.
  - **EIC**: the EIC takes no action in this slice. If any EIC-based permission is needed (e.g. reading), it must use `SiteSetting::isActingEic` (issue 37). Never compare `eic_employee_id` or `vendor_id` directly.
  - **Reading (`GET /api/work-orders/{wo}`)**: use the same visibility as the E-Call (`ECallPolicy::view`, i.e. non-vendor users plus the Work Order's Site Vendor's staff), until issue 04 alignment item 2 picks one rule.
  - **PATCH while `Open`**: creator only. The developer chooses the body. Suggested: replace the full line set and optionally add attachments, validated exactly like create. After `Open`, it returns 422. Non-creators get 403.
  - **Attachments**: optional, using the E-Call upload rules in `config/pss.php`.
  - **Dates**: timestamps follow the app timezone, Asia/Manila (ADR 0003). There are no date rules in this slice.
  - No open questions for the human in this issue.
