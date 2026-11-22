# 08 — Automatic PM Task generation

Status: ready-for-agent
Blocked by: 05, 06, 07
Type: task

## What to build

A scheduled job (Laravel scheduler, runs **daily**, queued) that makes sure every eligible Equipment has its next **PM Task** (PRD §6.4, glossary "PM Task"):
- Assigned to the Site Vendor and Site EIC at the time of generation.
- Linked to the current active PM Checklist version for the Equipment's category (issue 07).
- Stores an Equipment and Site **Snapshot** (ADR 0001).
- Initial PM Status `Scheduled`.
- **Due date** (use issue 06's `nextDueDate`):
  - The Equipment's first PM Task is due at its installation date (`installedOn`) + the Site's PM Frequency.
  - After that, the schedule slides: the successor of a `Completed` task is due at that task's Work Done Date + the frequency.
- **No duplicates**: never more than one open (not `Completed`/`Cancelled`) PM Task per Equipment, even when the job runs twice at the same time. Enforce it with a DB unique constraint or a lock.
- Historical PM Tasks stay after the next one is generated.
- The job finds Equipment through the MasterDataDirectory port (issue 02). In tests, that's the in-memory fake.

Which Equipment is eligible (decided, see Decisions):
- **The Site has no PM Frequency**: the job skips it.
- **The Site has no Site Vendor or no EIC**: the job skips it. It belongs on the admin "Needs Setup" list (issue 32).
- **No installation date and no PM history in PSS**: the job **doesn't generate a task**. It belongs on the "Needs Setup" list. The job generates the task on its next run once a start date is available. For now, that means an `installedOn` appears in the directory. Whether admins can also enter a start date in PSS is issue 33 (needs-info), and it doesn't block this slice.
- **No active checklist for the category**: the job **still generates** the task, with no checklist version and flagged `no checklist`.
- **Equipment gone from the directory**: the job keeps its open PM Task and flags it (`equipment_removed`). It never auto-cancels it. The EIC decides (issue 25).

**Frequency changes** (moved in from issue 06): on `SitePmFrequencyChanged`, re-date that Site's PM Tasks that are still `Scheduled`. New due date = the last Work Done Date + the new interval, or the installation date + the new interval if there's no completed task yet. Tasks `In Progress`, `For Validation` or `Returned` keep their due date.

Successor tasks: this slice doesn't depend on issue 11's event. The daily job generates the successor for any Equipment whose latest task is `Completed`. Tests create a `Completed` task with a Work Done Date directly. (Issue 11 may also trigger generation right away on completion. That's optional.)

PM Status values: `Scheduled`, `In Progress`, `For Validation`, `Returned`, `Completed`, `Cancelled` (GLOSSARY "PM Status"). This slice only creates `Scheduled` tasks.

## Acceptance criteria

- [x] Running the job creates one `Scheduled` PM Task per eligible Equipment, with the Site Vendor, EIC, checklist version and Site/Equipment Snapshots, due at `installedOn` + the Site's frequency.
- [x] Running it twice (or concurrently) creates no duplicates. Test both the DB unique constraint or lock and a repeated run.
- [x] An Equipment whose latest task is `Completed` with Work Done Date D gets a successor due at D + frequency. The completed task is still there.
- [x] An Equipment with no `installedOn` and no PM history gets no task. Once the fake directory gives it an `installedOn`, the next run creates it.
- [x] Equipment at a Site with no PM Frequency, no Site Vendor or no EIC gets no task.
- [x] An Equipment whose category has no active checklist gets a task flagged `no checklist`, with no checklist version.
- [x] An open task whose Equipment no longer exists in the directory is kept, flagged `equipment_removed`, and not cancelled.
- [x] Changing a Site's frequency re-dates only its `Scheduled` tasks, and the others keep their due date.
- [x] The job is registered in the scheduler to run daily. Tests use the directory fake. No new packages, and `php artisan test` passes.

## Comments

- 2026-11-22 (product-owner): Moved in from issue 06: re-dating `Scheduled` tasks on a frequency change (see What to build).
- 2026-11-22 (product-owner): The human answered the last blocking question, so the status is back to **ready-for-agent**. If an Equipment has no installation date and no PM history, **don't generate a task**. List it on the admin "Needs Setup" list until a start date is set. I've rewritten the body into final acceptance criteria and removed the stale "Open questions" and draft sections; the answers are kept under Decisions. The list itself is issue 32, so this slice only skips the Equipment. Who can "set a start date" (only the external API, or also an admin in PSS) is issue 33 (needs-info). It doesn't block this slice, because either way the job just uses the start date once it exists.
- 2026-11-22 (qa): QA PASS. `php artisan test` from backend/ gives 368 passed (1308 assertions), 0 failed. `tests/Feature/PmTaskGenerationTest.php` gives 28 passed (63 assertions). Every acceptance criterion maps to a test that asserts it. A unique index on the nullable `open_equipment_id` allows at most one open PM Task per Equipment, and a run that loses the race is skipped. The job is queued and scheduled daily at 00:00 (covered by a schedule test). Re-dating counts from the stored `due_from_date`.

## Decisions (grilling, 2026-11-22)
- Q1: a newly registered Equipment's **first PM Task is due one Site PM Frequency interval after its installation date**. After that, the schedule slides from each Work Done Date.
- Q1 follow-up (human, 2026-11-22): **no installation date and no PM history → no task**. The Equipment goes on the admin "Needs Setup" list until a start date is set.
- Q2: if the category has no active checklist, **still generate the task**, flagged "no checklist".
- Q3: Sites with no Site Vendor or no EIC are **skipped** and shown on the "Needs Setup" list for admins.
- Q4: if the Equipment is removed upstream, **keep its open PM Task and flag it**. The EIC decides whether to cancel it (issue 25). No auto-cancel.
- Q5: run the job **daily**. It can be tuned later.
