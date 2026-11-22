# 34 — PM Report: require the PM Service Report, and Work Done Date ≥ start

Status: ready-for-agent
Blocked by: 10
Type: task

## What to build

The human's answers on issue 10 (2026-11-22) change the merged build from PR #16. It took the defaults (service report optional, only future Work Done Dates rejected).

1. **PM Service Report is required** on every PM Report submission, the first one and every resubmission after `Returned`. It's a dedicated single-file field (e.g. `service_report`), stored and referenced separately from the general `attachments` and the per-item photos, so it can be identified later. Use the existing upload rules in `config/pss.php` (extensions, max size).
2. **Work Done Date window**: the date must be **on or after the date the task was started** and **not in the future**. The merged build doesn't record when a task was started, so add a nullable `started_at` timestamp to `pm_tasks`, set by `POST /api/pm-tasks/{task}/start`. Compare its **date** in the app timezone. If `started_at` is null (a task started before this change), only the "not in the future" rule applies. State this in the PR. There's no rule about the previous task's Work Done Date.

## Acceptance criteria

- [x] `start` sets `started_at` to the current time. The PM Task resource returns it.
- [x] A submission without a service report file returns 422 naming `service_report`. This applies to a first submission and to a resubmission from `Returned`. A service report with a disallowed type or size returns 422.
- [x] A valid submission stores the service report against that report revision, readable separately from the other attachments.
- [x] A Work Done Date earlier than the `started_at` date returns 422 with a clear message. The same day as `started_at` and today are both accepted. A future date still returns 422.
- [x] A task with a null `started_at` accepts any Work Done Date that isn't in the future.
- [x] **Existing stored revisions are unaffected**: a report revision stored before this change, with no service report and an earlier Work Done Date, is still readable unchanged through the API. No migration rewrites or deletes it.
- [x] Feature tests use `Storage::fake()` and factories. No new packages, and `php artisan test` passes.

## Comments

- 2026-11-22 (product-owner): Created from the human's answers to issue 10's non-blocking questions 1 and 2. Question 3 (`equipment_removed` tasks can still be started and reported) needs no change.
- 2026-11-22 (qa): QA PASS. `php artisan test` from backend/ gives 467 passed (1654 assertions), 0 failed. `tests/Feature/PmReportApiTest.php` gives 70 passed (306 assertions). Every acceptance criterion maps to a test that asserts it. The migration only adds nullable columns and rewrites no rows. A null `started_at` falls back to the not-in-future rule only. The start date is compared as a date in the app timezone. The service report is a single required file on every submission, under the E-Call upload rules (10 MB).
- 2026-11-22 (product-owner): Timezone decided by the human: the application timezone is **Asia/Manila** (docs/adr/0003-application-timezone-asia-manila.md, issue 35). The "app timezone" this issue refers to becomes Manila. The Work Done Date window (start date to today) is evaluated in Manila time, and issue 35 adds the 00:00–08:00 Manila boundary tests.
