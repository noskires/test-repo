# 21 — EIC validates completed work and sets the Billing Amount

Status: ready-for-agent
Blocked by: 20, 38
Type: task

## What to build

PRD §8.4, glossary "Billing Amount": the Work Order's EIC inspects an `Implemented` Work Order:
- Sees approved vs vendor-reported actual quantities for each line, with each **discrepancy marked** (over / under / equal) and a Work Order-level "has discrepancies" flag.
- **Approve**: the EIC confirms (and may correct) the **validated actual quantity** for each line. The **Billing Amount** = sum of validated qty x line unit price. Status → `For Billing Endorsement LVL1`.
- **Return**: → `For Implementation` for rework. Remarks are required.
- **Reject**: → `Rejected`, final. Remarks are required.
- Each action writes an Approval Record.

Endpoints: `POST /api/work-orders/{wo}/validate-completion` (approve, with validated quantities), `/return-completion`, `/reject-completion`.

## Acceptance criteria

- [ ] **Who**: a user who can't `view` the Work Order gets a plain 403 first, whatever its status (no status oracle). Among viewers, only the **current Site EIC** of the E-Call's Site (`SiteSetting::isActingEic`, which covers "not vendor staff") can approve, return or reject. Others get 403. A test replaces the EIC while the Work Order is `Implemented`: the new EIC can act, and the old one gets 403.
- [ ] **In-lock re-checks**: each action runs in a transaction that locks the Work Order row and re-checks `isActingEic(..., lockForUpdate: true)` and the `Implemented` status. Any other status returns 422 (shown only to viewers).
- [ ] **Discrepancy marking**: for each Work Order line, the Work Order and completion responses expose the actual qty (from the **latest** completion revision), the approved qty, the difference (actual − approved), the exact amount of the difference (difference × unit price, in millicentavos, ADR 0002) and a marker `over` / `under` / `equal`. The Work Order has `has_discrepancies` (true if any line isn't `equal`). Tests cover an over line, an under line and an equal line, and the flag for all-equal vs mixed. Quantities above the approved qty are allowed (issue 20 decision).
- [ ] **Approve**: a validated qty ≥ 0 (3 decimals, `BoqQuantity`) for every Work Order line, exactly once each. Missing, duplicate or foreign lines return 422. Validated qty may be above the approved qty. Validated quantities are stored per line, separate from the vendor's actual quantities.
- [ ] **Billing Amount (ADR 0002)**: the exact sum of validated qty × the line's unit price (millicentavos), rounded **once**, half-up, to integer centavos, and stored separately from the estimated amount. Both are readable on the Work Order. A test where rounding per line would differ (e.g. two lines of 50.5 centavos → 101).
- [ ] **Entering billing, block and flag**: Approve moves the Work Order to `for_billing_endorsement_lvl1` (a new `WorkOrderStatus`) only if the Requestor's level-1 head resolves right now (`ImmediateHeadChain`, from `e_calls.requestor_employee_id`). Otherwise 422 naming the level, the status stays `Implemented`, there's no record, and nothing is stored (no Billing Amount, no validated quantities).
- [ ] **Entering billing, separation of duties**: the billing levels needed right now are `BillingLevels::requiredBillingLevels(rounded Billing Amount, current thresholds)`. Among the heads at levels 1..n that resolve, no employee ID may repeat, and none may be the Requestor. Otherwise 422 ("same person" / "would be the Requestor") with nothing stored. Levels above 1 that don't resolve yet don't block here; issue 23 blocks them when the Work Order would move to them. Tests for each case.
- [ ] **Return** (remarks required) → `For Implementation`, and the vendor resubmits through issue 20. **Reject** (remarks required) → `Rejected`, final. Every later action returns 422.
- [ ] Approve, return and reject each write an Approval Record through `ApprovalRecorder` (the `work_order` alias), with previous and resulting status.
- [ ] No new packages, and `php artisan test` passes.

## Comments
- 2026-11-22 (product-owner): Rounding decided by the human (docs/adr/0002-money-rounding.md). Per-line amounts stay exact, and only the Billing Amount is rounded to the centavo, half-up. Acceptance criterion updated.
- 2026-11-22 (product-owner): Human decision (issue 11 Decisions, spec.md): when a Site's EIC is replaced, the **current Site EIC** (`site_settings.eic_employee_id` at the time of the request) acts on pending items. The EIC captured on the record is history only and isn't used for authorization. "The Work Order's EIC" means the current Site EIC of its Site. Add a test where the EIC is replaced while the Work Order is `Implemented`: the new EIC can act, and the old one gets 403.
- 2026-11-23 (product-owner): From the human's issue 23 decision (billing endorsement uses the same rules as 19). The EIC's **Approve** moves the Work Order to `For Billing Endorsement LVL1`. If the Requestor's level-1 Immediate Head doesn't resolve at that moment (or the Requestor has no `employee_id`), Approve returns **422** with a clear message, the status stays `Implemented`, and no Approval Record is written. The Work Order appears on the Needs Setup list (issue 32). Add a criterion for this when the issue is picked up. Any EIC check uses `SiteSetting::isActingEic`.
- 2026-11-23 (product-owner): Human decision (issue 38): when the EIC's Approve moves the Work Order into billing, apply the **separation-of-duties chain check** for the billing levels needed right now. The heads must be distinct and none may be the Requestor; otherwise 422, the status stays `Implemented`, no record, and the Work Order goes on Needs Setup. The endpoints authorize `view` first (no status oracle).
- 2026-11-23 (product-owner): Re-checked before hand-off (main 2ee8bef). Blockers 20 and 38 are merged; I added 38 for the shared rules. I replaced the criteria with final ones that fold in all the notes above, plus the human's issue 20 decision: **mark discrepancies**. The vendor may report over-quantities, the EIC sees per-line over/under/equal with the exact difference and a `has_discrepancies` flag, and the EIC may validate above the approved qty. Approver checks go only through `SiteSetting::isActingEic` / `User::actsAsEmployee` (issue 38), never by comparing `vendor_id` / `employee_id` directly. Dates are Asia/Manila (ADR 0003); there are no date rules here. If issue 32 has merged, the Work Order's Needs Setup entries for these 422s come from 32/23 (the reasons `missing_billing_endorser_level_1` and `endorsement_chain_conflict`), so this issue doesn't build list entries. No open questions.
