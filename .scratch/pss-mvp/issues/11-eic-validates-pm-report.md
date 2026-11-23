# 11 — EIC validates a PM Report (and shared Approval Records)

Status: done
Merged: PR #19 (6b99081), 2026-11-22
Blocked by: 10, 34
Type: task

## What to build

The Site's EIC reviews a PM Report in `For Validation` (PRD §6.7, glossary):
- **Approve**: PM Status → `Completed`. The PM Task's Work Done Date is confirmed as the vendor reported it (glossary: not the approval date). Fire a `PmTaskCompleted` event. Issue 08's generator listens to it and creates the successor.
- **Return**: PM Status → `Returned`, back to the vendor. Remarks are required.
- There is **no Reject** for PM Reports (glossary overrides PRD §6.7).

Build the **shared Approval Record mechanism** here, to be reused by issues 17, 19, 21, 23 and 25. It's a polymorphic `approval_records` table: subject, approver user / `employee_id`, action (`approve` / `return` / `reject`), remarks, previous status, resulting status, timestamp. Expose `GET /api/pm-tasks/{task}/approvals`.

Endpoints: `POST /api/pm-tasks/{task}/approve`, `POST /api/pm-tasks/{task}/return`.

## Acceptance criteria

- [x] Only the **current** Site EIC (from the Site settings at the time of the request) can approve or return. Others get 403.
- [x] After the Site's EIC is replaced while a task is `For Validation`, the **new** EIC can approve or return it, and the **old** EIC (the one captured on the task) gets 403. The task's captured `eic_employee_id` is left unchanged, as history.
- [x] Approve or Return on a task not in `For Validation` returns 422.
- [x] Approve sets `Completed`, keeps the vendor's Work Done Date, writes an Approval Record, and fires `PmTaskCompleted` (`Event::fake()` test).
- [x] Return without remarks returns 422. With remarks, it sets `Returned` and writes an Approval Record.
- [x] There is no reject endpoint for PM Tasks.
- [x] Approval Records hold approver, action, remarks, previous and resulting status, and timestamp, and are listed in chronological order.
- [x] The Approval Record logic is a reusable component (trait/service) with its own unit test.

## Comments
- 2026-11-22 (qa): QA PASS (final, replaces the earlier withdrawn PASSes). `php artisan test` from backend/ gives 526 passed (1889 assertions), 0 failed. `tests/Feature/PmTaskValidationApiTest.php` and `tests/Unit/Approvals` give 49 passed (197 assertions). Every acceptance criterion maps to a test that asserts it. The current Site EIC is checked by the policy, then again inside the transaction with the task and settings rows locked. An EIC replaced after the policy check gets 403 with no record, no event and no status change. The new EIC can act, the old EIC gets 403, and so does everyone when the Site has no EIC or no settings row. `pm_tasks.eic_employee_id` is kept as history.

## Decisions (grilling, 2026-11-22)
- The reviewer is the **Site's EIC**, the same engineer who reviews E-Calls at that Site.

- 2026-11-22 (product-owner): Re-checked before hand-off (main 2314ae4). Blockers 10 and 34 are merged, and 35 (timezone) too. I added 34 to "Blocked by" because the Work Done Date window it adds is what Approve confirms. Still ready-for-agent. Clarifications, scope unchanged:
  - ~~**Who is the EIC**~~ (superseded by the human's decision below): the task's captured `eic_employee_id` (set at generation, issue 08), matched to `users.employee_id`. This is what the first criterion says. If the Site's EIC is replaced while a task is open, whether the new EIC takes over is a non-blocking question for the human below. Don't add re-routing.
  - **Approve**: Work Done Date stays exactly as the vendor reported (it's already on `pm_tasks.work_done_date` from issues 10 and 34). The EIC can't edit it. Remarks are optional. Set `Completed`, let the open-task key be released (the `PmTask` model's saving hook already sets `open_equipment_id` to null when the status isn't open, so don't duplicate that; just assert it in a test), and fire a new `PmTaskCompleted` event (task ID, equipment ID, Work Done Date). Generating the successor straight from that event is optional. The daily job covers it anyway (issue 08).
  - **Return**: remarks are required. Set `Returned`. The vendor resubmits through issue 10's report endpoint, which already allows `Returned`.
  - **Approval Record design, so 13, 17, 19, 21, 23 and 25 can reuse it unchanged**:
    - One `approval_records` table with a polymorphic `subject` (`subject_type`, `subject_id`). Register a **morph map** with stable aliases (`pm_task` now; `e_call` and `work_order` are added by later issues), so stored rows don't depend on class names.
    - Columns: `action`, `actor_user_id`, `actor_employee_id` (nullable string, copied from the user at the time of the action), `remarks` (nullable), `previous_status` and `resulting_status` (strings, because each subject has its own status enum), and `created_at`. There's no `updated_at`, because records are **append-only**. The component offers no update or delete, and the model blocks them.
    - `action` is a backed enum with `approve`, `return`, `reject` and `cancel` (GLOSSARY "Approval Record" includes cancellation). Issue 25 uses `cancel`. Multi-level endorsements (19, 23) use `approve`/`return`/`reject`, and the level shows in the status columns (e.g. `for_billing_endorsement_lvl1` → `..._lvl2`).
    - A reusable service (or trait plus service), e.g. `ApprovalRecorder::transition($subject, $actor, ApprovalAction, $toStatus, ?$remarks)`. It runs in a DB transaction with the subject row locked, checks nothing about the workflow itself (each workflow validates its own transitions first), reads the previous status from the locked subject, writes the new status and the record together, and returns the record. Status changes and records can never diverge.
    - A reusable `ApprovalRecordResource` and a chronological listing (oldest first, ties broken by ID). Here it's exposed as `GET /api/pm-tasks/{task}/approvals`, visible to the task's EIC and to its Vendor's staff (the same people who can see the task), with 403 for others.
    - The unit test covers the service on its own, using a PM Task as the subject: record fields, previous and resulting status, rollback when the subject write fails, and that update and delete are refused.
  - **Approval Records for vendor actions** (start, submit) aren't written. The glossary limits them to Approve, Return, Reject and cancellation.
  - Open, **non-blocking** question for the human (build as above):
    1. If the Site's EIC is replaced while a PM Task is `For Validation`, should the **new** Site EIC validate it, or the EIC captured on the task? The same question applies to E-Calls (issue 12 captures the EIC at filing) and Work Orders.

## Decisions (human, 2026-11-22)
- **The current Site EIC acts on pending items.** If a Site's EIC is replaced, the current Site EIC (`site_settings.eic_employee_id`, matched to `users.employee_id` at the time of the request) approves or returns PM Tasks that are `For Validation`. The EIC captured on the task (`pm_tasks.eic_employee_id`) is **history only**: don't overwrite it, and don't use it for authorization. The same applies to `GET /api/pm-tasks/{task}/approvals` (the current Site EIC can see it, the old one can't). This answers the non-blocking question above. It applies to E-Calls and Work Orders too (issues 13, 17, 21, 24, 25).
