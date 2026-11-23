# 09 — PM Timeliness (Upcoming / Due / Overdue)

Status: done
Merged: PR #30 (528b72d), 2026-11-23
Blocked by: 08
Type: task

## What to build

Compute **PM Timeliness** for each open PM Task. It is shown next to PM Status and never replaces it, so a task can be "Returned" and "Overdue" at the same time (glossary). This is used by the PM Calendar, the dashboard and the "overdue" reports.

## Open questions (for the human) — all answered, see Decisions below

1. **Upcoming**: how many days before the due date does a task become "Upcoming" (e.g. 7 days)? Is it one number for all frequencies, or does it depend on the frequency?
2. **Due**: PRD says "scheduled for the current period". Does that mean due date = today, due within the current calendar month, or within a grace window?
3. **Overdue**: overdue the day after the due date, or after a grace period?
4. Does a task in `For Validation` still count as Overdue if the EIC hasn't approved by the due date? Or is the vendor's Work Done Date what counts?

## Acceptance criteria

- [x] A pure function (no DB, no clock; `today` is passed in) `timeliness(dueDate, today, status, workDoneDate)` returns `upcoming`, `due`, `overdue` or `null`, with unit tests at every boundary: due−8 (null), due−7 (upcoming), due−1 (upcoming), due (due), due+1 (overdue).
- [x] Completed and Cancelled tasks have no timeliness (`null`), even when long past the due date.
- [x] Scheduled, In Progress and Returned tasks follow the date rule against today. A Returned task past its due date is `overdue` even though it has a Work Done Date (tested).
- [x] A For Validation task is judged by its Work Done Date: Work Done Date ≤ due date gives `null` (on time, waiting for the EIC) even when today is after the due date; Work Done Date > due date gives `overdue` (tested both ways).
- [x] `PmTaskResource` returns `timeliness` next to `status`, so `GET /api/vendor/pm-tasks` and `POST /api/pm-tasks/{task}/start` include it (feature test).
- [x] "Today" is today in Asia/Manila: a feature test with time frozen between 00:00 and 08:00 Manila (16:00–24:00 UTC the day before) gets the Manila date's answer, not the UTC one.

## Comments

- 2026-11-23 (qa): PASS. `php artisan test` 866 passed (3823 assertions); `php artisan test --filter=PmTimeliness` 25 passed (63 assertions). Every criterion maps to a real test.

## Decisions (grilling, 2026-11-22)
- **Upcoming**: from 7 days before the due date, the same for every frequency.
- **Due**: on the due date.
- **Overdue**: from the day after the due date, with no grace period, if the work isn't done.
- **On time vs late is judged by the Work Done Date**, not the approval date. A task the vendor submitted (with a Work Done Date) on or before the due date shows as For Validation, not Overdue, while it waits for the EIC.
- 2026-11-22 (product-owner): Timezone decided by the human: the application timezone is **Asia/Manila** (docs/adr/0003-application-timezone-asia-manila.md, issue 35). Upcoming / Due / Overdue are judged against **today in Manila**. When this issue is built, include a test with time frozen between 00:00 and 08:00 Manila (16:00–24:00 UTC the day before).

- 2026-11-23 (product-owner): Clarifications for dev-lead, re-checked against main e77cee4.
  - Status values are `App\Enums\PmStatus` (scheduled, in_progress, for_validation, returned, completed, cancelled). Open = `isOpen()`.
  - Due date is `pm_tasks.due_date` (immutable date). Ignore `due_from_date` here: it is the generation/re-dating anchor (issue 08), not part of timeliness.
  - `work_done_date` is set on the task when the vendor submits (`PmReport` sets status For Validation + work_done_date) and is **not cleared** on Return. That is why Returned must use the date rule against today and must not look at `work_done_date`. The glossary example "Returned and Overdue" depends on this.
  - For Validation with a late Work Done Date shows `overdue`: this follows the decision "on time vs late is judged by the Work Done Date". It is the only reading of the decisions that keeps a late submission from looking on time.
  - Before the Upcoming window (more than 7 days before due) the value is `null`. There is no "on time" or "not yet due" label; the PRD and glossary list only Upcoming / Due / Overdue.
  - Put the function in a small domain class (for example `app/Scheduling/PmTimeliness.php` next to the existing Scheduling unit tests under `tests/Unit/Scheduling/`) plus an enum for the three values. Don't add a DB column: compute on read. Filtering or sorting by timeliness is not in scope (the calendar, dashboard and reports issues 27/28/29 will reuse the function).
  - App timezone is already Asia/Manila (`config/app.php`, issue 35). Use `today()` / `now()` in the resource; keep the pure function clock-free.
  - Out of scope: the PM Calendar UI, dashboard counts (27), KPIs (28). No frontend change required in this issue.

## Implementation decisions

1. **Shape**: enum `App\Enums\PmTimeliness` (`upcoming`, `due`, `overdue`) and a pure static function `App\Scheduling\PmTimelinessRule::timeliness(DateTimeInterface $dueDate, DateTimeInterface $today, PmStatus $status, ?DateTimeInterface $workDoneDate): ?PmTimeliness`. No DB column; computed on read. Issues 27/28/29 should call this function rather than re-implement the rule.
2. **Window constant**: `PmTimelinessRule::UPCOMING_DAYS = 7`, the same for every frequency. due−8 or earlier is `null`, due−7..due−1 `upcoming`, due `due`, due+1 onward `overdue`.
3. **Calendar dates only**: the function compares the `Y-m-d` of each argument and ignores time of day and time zone. The caller is responsible for passing "today" as a Manila date; the resource passes `today()` (app timezone Asia/Manila, ADR 0003).
4. **For Validation without a Work Done Date** (should not happen, since submitting a PM Report sets it): falls back to the date rule against today instead of returning `null`, so a bad row cannot hide an overdue task. Default chosen by dev-lead; not a business rule from the human.
5. **For Validation with a Work Done Date** is judged only by that date, never by today: `null` when on or before the due date, `overdue` when after. It never shows `upcoming` or `due`, even while today is still before the due date (the work is already done).
6. **Returned** ignores `work_done_date` entirely (it is kept from the earlier submission) and uses the date rule against today.
7. **API**: `PmTaskResource` adds `timeliness` (string or `null`) right after `status`. Every endpoint that returns this resource gets it, including `GET /api/vendor/pm-tasks` and `POST /api/pm-tasks/{task}/start`. Additive field, no frontend change.
8. **Performance**: pure in-memory computation per row, no extra queries. Filtering or sorting by timeliness in SQL is not supported (out of scope).
