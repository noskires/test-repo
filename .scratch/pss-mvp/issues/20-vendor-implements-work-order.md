# 20 — Site Vendor implements the Work Order and declares completion

Status: ready-for-agent
Blocked by: 19, 38
Type: task

## What to build

PRD §8.3: a Work Order in `For Implementation` is visible to its Site Vendor's staff. When the work is done, the vendor declares it complete: uploads the completion documents and photos, and reports the **actual quantity used** for each Work Order line. Status → `Implemented` (now waiting for EIC validation, issue 21).

Allowed from `For Implementation`, including after an EIC Return (issue 21 sends it back to `For Implementation`).

Endpoint: `POST /api/work-orders/{wo}/completion`. Listing: `GET /api/vendor/work-orders`.

Note: this issue only depends on 19 for a Work Order to reach `For Implementation`. Tests can put Work Orders in that status with factories.

## Acceptance criteria

- [ ] Only staff of the Work Order's Site Vendor can submit: `user.vendor_id` equals the `vendor_id` captured on the Work Order's E-Call (not null). A user who can't `view` the Work Order gets a plain 403 first, whatever its status (no status oracle, as in issue 38). A viewer who isn't that Vendor's staff also gets 403.
- [ ] It only works from `For Implementation`, re-checked inside a transaction that locks the Work Order row. Any other status returns 422 (shown only to users who can view the Work Order).
- [ ] Every Work Order line needs an actual quantity >= 0, with at most 3 decimal places (`BoqQuantity`, `pss.boq.quantity_decimal_places`). A missing line, a duplicate line, or a line from another Work Order returns 422. At least one completion document is required.
- [ ] Success sets `Implemented` and stores the actual quantities and files. A resubmission after Return keeps the earlier submission in history.
- [ ] `GET /api/vendor/work-orders` lists only Work Orders whose E-Call's `vendor_id` is the caller's Vendor. Non-vendor users get 403.

## Comments
- 2026-11-23 (product-owner): Re-checked before hand-off (main 911cff9). Blockers 19 and 38 are merged; I added 38 to "Blocked by" for its view-first and actor-helper rules. Still ready-for-agent. Clarifications, scope unchanged:
  - **Which Vendor**: `work_orders` has no `vendor_id`. Use the `vendor_id` captured on the Work Order's E-Call at filing (issue 12). Don't add re-routing if the Site Vendor changes later. That's the same as how issue 16 treats proposals.
  - **Order of checks**: (1) `view` on the Work Order (`WorkOrderPolicy::view`), otherwise a plain 403; (2) the caller is that Vendor's staff, otherwise 403; (3) inside the lock, the status is `For Implementation`, otherwise 422; (4) payload validation, otherwise 422.
  - **Internal-approver checks**: none in this slice (the actor is vendor staff). If one is ever needed, use `User::actsAsEmployee` / `SiteSetting::isActingEic` (issue 38). Never compare `vendor_id` / `employee_id` for approver rights directly.
  - **Status**: add `implemented` to `WorkOrderStatus`. Issue 21 adds the Return back to `For Implementation`, so tests set that state directly for the resubmission case.
  - **Completion submissions**: each one is a numbered revision with its actual quantities per Work Order line, the documents (required, at least one) and photos (optional), and optional notes. It records the submitter and `submitted_at`. Earlier revisions stay readable (e.g. on `GET /api/work-orders/{wo}` or a completions listing; the developer chooses the shape, with view-first). Use the existing upload rules in `config/pss.php`.
  - **Money (ADR 0002)**: this slice stores **quantities only**. If an actual amount is shown, compute it per ADR 0002: exact line totals in millicentavos, with the total rounded once, half-up. Don't store a Billing Amount here; the EIC sets it in issue 21.
  - **Dates (ADR 0003)**: timestamps are in Asia/Manila. There are no date rules in this slice.
  - **Approval Records**: none. Declaring completion is a vendor action, not Approve/Return/Reject/cancel.
  - Open, **non-blocking** questions for the human (build as described, and don't add a rule):
    1. May the vendor report an **actual quantity greater than** the Work Order's approved quantity (e.g. more material needed)? This slice allows any qty ≥ 0. The EIC validates in issue 21.
    2. PRD v2 §8.2 says a Work Order is visible to its Site Vendor's staff **from `For Implementation`**, but the current `WorkOrderPolicy::view` (which follows the E-Call) lets them see it from `Open`. Should vendor staff be hidden from Work Orders before `For Implementation`? This slice follows the existing policy.
