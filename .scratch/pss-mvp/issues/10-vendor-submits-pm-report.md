# 10 — Site Vendor starts a PM Task and submits the PM Report

Status: done
Merged: PR #16 (3f086d5), 2026-11-22
Blocked by: 07, 08
Type: task

## What to build

Vendor staff (users whose `vendor_id` = the task's vendor) work on their PM Tasks (PRD §6.6):
- `GET /api/vendor/pm-tasks`: lists only PM Tasks assigned to the user's Vendor.
- `POST /api/pm-tasks/{task}/start`: `Scheduled` → `In Progress`.
- `POST /api/pm-tasks/{task}/report`: submits the **PM Report**: a response for each Checklist Item of the task's checklist version, the **Work Done Date**, findings / observations, materials and consumables used (free-text lines with quantity), and file attachments (PM Service Report, photos). Status → `For Validation`. Allowed from `In Progress` or `Returned`.

Validation follows the checklist version the task was generated with:
- Every mandatory item needs a response of the right type.
- A failed item (`fail` / `defective` / `no`, or numeric out of range) with `remarks_required_on_fail` needs remarks.
- `photo_required` items need a photo.

Files are stored with Laravel Storage, with DB references.

Note: this issue only depends on 08 for the PM Task table and model. Tests can create PM Tasks directly with factories and don't need 08's generator job. If 08 is still needs-info, the dev-lead can pull the PM Task model/migration into this issue.

## Acceptance criteria

- [x] Vendor staff see only their own Vendor's tasks. Another vendor's task returns 403 for start and report.
- [x] Start works only from `Scheduled`. Any other status returns 422.
- [x] A report missing a mandatory item, missing remarks on a failed item, or missing a required photo returns 422, naming the item.
- [x] Numeric readings outside the acceptable range count as failed.
- [x] A Work Done Date in the future returns 422.
- [x] A valid submission stores responses against the checklist version, stores the attachments, and sets status `For Validation`.
- [x] Resubmitting from `Returned` replaces the current responses and keeps the earlier submission in history.
- [x] Feature tests use `Storage::fake()`.

## Comments

- 2026-11-22 (product-owner): Re-checked before hand-off (main 584994c). Blockers 07 and 08 are merged, and the `pm_tasks` table and `PmTask` model already exist from 08, so the "pull the model in" note above no longer applies. Still ready-for-agent. Clarifications from the merged code and PRD v2 §6, scope unchanged:
  - **Tasks flagged `no_checklist`** (no checklist version): the report is accepted with **no checklist responses**. All the other parts still apply (PRD v2 §6: "The vendor submits a report without one").
  - **Work Done Date on the task**: on submission, also write it to `pm_tasks.work_done_date`, overwriting it on a resubmission. Issue 09 judges timeliness by the Work Done Date while a task is For Validation (PRD v2 §6), and 08's job only reads it from `Completed` tasks, so writing it early is safe. The EIC confirms it in issue 11.
  - **Failed responses**: `fail` (pass_fail), `defective` (good_defective), `no` (yes_no), or a numeric reading below `acceptable_min` or above `acceptable_max`, when those are set. Compare as decimals, never floats. `text` and `photo` responses never count as failed.
  - **Photos**: a `photo` response-type item is answered by uploading a photo for that item. A `photo_required` item of any type needs at least one photo **linked to that item**. General attachments (the PM Service Report, other photos) aren't linked to an item. Reuse the upload rules in `config/pss.php` (extensions, max size).
  - **Materials used**: free-text description plus quantity > 0, using the same 3-decimal limit as BOQ quantities (`pss.boq.quantity_decimal_places`). No prices. They aren't Price List items.
  - **Submission history**: each submission (first, or after `Returned`) is a numbered report revision with its own responses and attachments. The task's current responses are those of its latest revision. `Returned` is set by issue 11, so tests set it directly.
  - **Approval Records** come in issue 11. Here, record the submitter and the submission time on the revision.
  - Open, **non-blocking** questions for the human (build as described, and don't invent extra rules):
    1. Is the **PM Service Report file mandatory**? The criteria don't require it, so this slice treats it as optional.
    2. May the Work Done Date be **earlier than the task's start**, or earlier than the previous task's Work Done Date? This slice only rejects future dates.
    3. Can the vendor still start or report a task flagged `equipment_removed`? This slice doesn't block it. The EIC decides whether to cancel (issue 25).
- 2026-11-22 (qa): QA PASS. `php artisan test` from backend/ gives 452 passed (1584 assertions), 0 failed. `tests/Feature/PmReportApiTest.php` and `tests/Unit/Checklists/ChecklistResponseEvaluatorTest.php` give 84 passed (265 assertions). Every acceptance criterion, and each product-owner clarification above, maps to a test that asserts it. The three open questions are built as stated: the PM Service Report file is optional, only future Work Done Dates are rejected, and tasks flagged `equipment_removed` aren't blocked.

## Decisions (human, 2026-11-22, after the merge in PR #16)
- Q1: the **PM Service Report file is required** on every PM Report submission. The merged build has it optional, and the change goes in **issue 34**.
- Q2: the **Work Done Date must not be earlier than the task's start date**, and must not be in the future. There's no rule about the previous task's Work Done Date. The merged build only rejects future dates, and the change (including recording `started_at`) goes in **issue 34**.
- Q3: tasks flagged `equipment_removed` **can still be started and reported**. The EIC decides whether to cancel them (issue 25). The merged build already does this, so nothing changes.
