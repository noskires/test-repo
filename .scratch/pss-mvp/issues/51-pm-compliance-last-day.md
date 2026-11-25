# 51 — PM Compliance: include PM Tasks due on the last day of the period (chore, bug)

Status: ready-for-agent
Blocked by: 28, 42
Type: chore

## What to build

The dev-lead on issue 42 noticed a possible bug (decision note 13 in issue 42). `DashboardKpis::pmCompliance` picks the PM Tasks due in the period with `whereBetween('due_date', [from, PmCompliance::dueWindowEnd(...)])` (`backend/app/Dashboard/DashboardKpis.php` around line 75). `pm_tasks.due_date` has the `immutable_date` cast, which stores `Y-m-d 00:00:00` on SQLite. A string comparison against a bare `Y-m-d` upper bound can drop a task due exactly on the window end. MySQL `date` columns aren't affected, but the test suite and `.env.example` both use SQLite. Issue 42 fixed the same problem for `pm.completed_in_range` with `>= from` and `< end + 1 day` (`DashboardFinancials`).

## Acceptance criteria

- [x] First, a failing feature test (SQLite, as in `phpunit.xml`): a PM Task due exactly on the last day of the compliance window counts in `pm.compliance`. Use an explicit `from`/`to`, and also a default-period case if the window end differs there (`dueWindowEnd` caps it at today). A task due the day after the window end does not count.
- [x] A task due exactly on `from` counts (lower-bound regression test).
- [x] The fix uses the same half-open comparison as issue 42 (`>= from`, `< windowEnd + 1 day`) or `whereDate`. No raw SQL that only works on one driver.
- [x] If the new test passes without the fix (the bug doesn't reproduce), don't change the query. Keep the boundary tests and record that finding here.
- [x] The issue 28 query-count test and every existing dashboard test still pass.

## Comments

### Clarifications (product-owner, 2026-11-25)

- Triaged from the issue 42 merge report (PR #52). The other date-range queries in `backend/app` are already safe: `EquipmentHistory` uses `whereDate`, and `DashboardFinancials` uses the half-open form. This issue changes only `pmCompliance`.
- No behaviour change is intended beyond the boundary. The GLOSSARY "PM Compliance" entry already says "due in the period", so no doc change is expected.
- Small chore. Tests go in `backend/tests/Feature/DashboardKpisApiTest.php`. Record your decisions under a dated "Decisions (dev-lead, ...)" heading here.

## Implementation decisions

### Decisions (dev-lead, 2026-11-25)

1. **The bug reproduces.** Before the fix, both new boundary tests failed on SQLite: a task due exactly on the window end (the explicit `to`, or today in the default period) was dropped from `due` (the tests got 1 and 0 where 2 and 1 were expected). The lower bound (`from`) was already counted correctly.
2. **The fix** is in `backend/app/Dashboard/DashboardKpis.php::pmCompliance`. It replaces `whereBetween` with the issue 42 half-open form: `due_date >= from` and `due_date < dueWindowEnd(to, today) + 1 day`, built with the query builder only, so it works the same on MySQL and SQLite. `PmCompliance::calculate` (the in-PHP filter) is unchanged and already compares `Y-m-d` strings correctly.
3. **New tests** in `backend/tests/Feature/DashboardKpisApiTest.php`:
   - `test_pm_compliance_counts_tasks_due_on_the_first_and_last_day_of_an_explicit_period` uses `from=2026-11-01`, `to=2026-11-15`. Tasks due on 10-31, 11-01, 11-15 and 11-16 give `due` 2.
   - `test_pm_compliance_in_the_default_period_counts_a_task_due_today_but_not_tomorrow` has today as 2026-11-20 (Manila), where the window end is capped at today. A task due 11-20 counts. A task due 11-21 doesn't.
4. No behaviour change beyond the boundary, and no doc or GLOSSARY change. The query count is unchanged: the issue 28 query-count test passes.

### QA (qa-51, 2026-11-25): PASS

- `php artisan test --compact` (backend): 1377 passed, 6449 assertions. `vendor/bin/pint --test`: pass.
- Two new tests in `DashboardKpisApiTest` cover the explicit-period first and last day (with the day before and the day after excluded) and the default period (due today counts, due tomorrow doesn't).
