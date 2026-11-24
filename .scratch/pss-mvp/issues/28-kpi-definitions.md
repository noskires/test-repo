# 28 — Define dashboard and report KPIs

Status: done
Merged: PR #48 (99373ef), 2026-11-24
Blocked by: 09
Type: task

## What to build

The PRD asks for several indicators without saying how to calculate them (§3.1, §4.1, §6.9, Module 8).

## Open questions (for the human)

1. **PM compliance %**: is it completed-on-time ÷ due in the period? Or completed (any time) ÷ scheduled? Does the Work Done Date or the EIC approval date count for "on time"?
2. **Vendor performance**: which measures (PM on-time rate, E-Call response time from filing to FSR/BOQ, number of Returns, actual vs BOQ variance)? Any weighting?
3. **Equipment requiring attention**: what triggers it (overdue PM, failed checklist items, an open Emergency E-Call, an operational status from the external API)?
4. **Operational vs non-operational equipment**: does that status come from the external API (see issue 03 Q4), or is it maintained in PSS? If in PSS, who sets it?
5. **Recurring issues / equipment reliability**: how many E-Calls or failed findings in what period count as "recurring"? Is there a reliability metric (e.g. MTBF)?

## Acceptance criteria

Scope: three KPIs, each a pure calculation in `app/Dashboard/` with PHPUnit unit tests, added to `GET /api/dashboard` as new keys next to the issue 27 keys. **Do not rename or remove any existing key.** Reports are issue 29 and the date range is issue 42, so neither is part of this issue.

**PM Compliance**
- [x] Unit: given PM Tasks, a period (`from`, `to`) and today (Manila), it returns `due`, `on_time` and `percent`.
  - `due` counts the PM Tasks whose due date is in `[from, min(to, today)]`, leaving out Cancelled ones. PM Tasks due later in the period don't count yet.
  - `on_time` counts the tasks in `due` that are **Completed** and have Work Done Date ≤ due date.
  - `percent` = on_time ÷ due × 100, rounded half-up to 1 decimal place. It is `null` when `due` is 0. Never divide by zero.
- [x] Unit cases: done on the due date (compliant); done a day late (not compliant); For Validation, Returned, In Progress or Scheduled past the due date (not compliant); Cancelled (left out); due date after today (left out); due date outside the period (left out); no due tasks (`percent` null).
- [x] API: `pm.compliance` = `{period_from, period_to, due, on_time, percent}`. Until issue 42 adds a date range, the period is the **current calendar month in Asia/Manila**. Feature test with the clock frozen (`Carbon::setTestNow` / `travelTo`).

**Operational status**
- [x] Unit: classify the `operating_status` from the master data directory (case-insensitive, trimmed): `operational` → operational; `null` or empty → unknown; any other value → non-operational.
- [x] API: `equipment.operating` = `{operational, non_operational, unknown}`, counted over the Equipment the dashboard covers.

**Equipment Requiring Attention**
- [x] Unit: for one Equipment, it returns the list of reasons that apply, in this order:
  - `overdue_pm`: an open PM Task whose PM Timeliness is Overdue. Reuse `App\Scheduling\PmTimelinessRule`; don't copy it.
  - `failed_items`: the latest PM Report of its most recent **Completed** PM Task has at least one response with `is_failed = true`. "Latest" means the highest revision.
  - `emergency_e_call`: an open E-Call with priority `emergency`. Open means not Closed, Rejected or Cancelled, the same as in `DashboardCounts`.
  - `not_operating`: the operational status above is non-operational. Unknown doesn't count.

  An empty list means the Equipment doesn't need attention. Each reason gets a test on its own, plus one test with several reasons together.
- [x] API: `equipment.requiring_attention` = `{count, items: [{equipment_id, equipment_name, site_id, reasons: [...]}]}`, sorted by site_id, then equipment_id.

**Filters and visibility (same rules as issue 27)**
- [x] `site_id` and `equipment_category_id` narrow every new figure.
- [x] `vendor_id` narrows the Equipment figures to Equipment at Sites whose `site_settings.vendor_id` is that Vendor, and narrows PM Compliance through `pm_tasks.vendor_id`.
- [x] Vendor staff are forced to their own Vendor whatever they pass. Feature test: vendor staff can't see another Vendor's Equipment in `requiring_attention`.
- [x] Feature test: the existing issue 27 keys still come back unchanged (regression).
- [x] Performance: no query per PM Task. Load the PM Tasks, E-Calls and failed responses for the covered Equipment in a fixed number of queries. A test asserts the query count doesn't grow with the number of Equipment.

**Docs**
- [x] `backend/README.md` dashboard section (if it has one) lists the new keys.

## Comments

### Clarifications (product-owner, 2026-11-24)

- **Out of scope**: vendor performance and recurring-issue/reliability metrics (human, 2026-11-22). Reports and export are issue 29, the dashboard date range and financial sums are issue 42.
- **Where things live**: the dashboard is `App\Dashboard\DashboardCounts` plus `App\Http\Controllers\Api\DashboardController` (issue 27). Its existing tests are in `backend/tests/Feature/DashboardApiTest.php`. Equipment and its `operatingStatus` come from the `MasterDataDirectory` port (`listSites()`, `listEquipmentAtSite()`). PSS stores no Equipment table and **must not add one** (domain rule: equipment, sites, employees and login are external). The Site Vendor is `site_settings.vendor_id`. Failed checklist answers are `pm_report_responses.is_failed`.
- **Equipment the dashboard covers**: Equipment from the directory at every Site (or the filtered Site), narrowed by category and by vendor as described above. Equipment with no `site_settings` row has no Site Vendor, so a `vendor_id` filter leaves it out.
- **Provisional interpretations (product-owner, 2026-11-24, to confirm with the human)**. Build them as written, with each rule in one named constant or method so it's easy to change:
  1. Only **Completed** (EIC-approved) PM Tasks count as "done" for compliance. A task still For Validation with an on-time Work Done Date isn't counted yet.
  2. PM Tasks due later in the period (after today) are left out of `due`, so the figure isn't dragged down mid-month.
  3. The default period is the current Manila calendar month (until issue 42).
  4. The external API's operating status values are still open (issue 03 Q4): only `operational` counts as operating, `null` is unknown, and anything else is non-operational.
- Record your own implementation decisions under a dated "Decisions (dev-lead, ...)" heading in this file.
- 2026-11-24 (qa-28): QA PASS. `php artisan test --compact` = 1265 passed (5771 assertions); `vendor/bin/pint --test` passed. Frontend untouched.

## Decisions (grilling, 2026-11-22)
- Q1 answered: **PM compliance % = PM Tasks done on time ÷ PM Tasks due in the period**. "On time" means Work Done Date ≤ due date (issue 09). Late and not-done tasks don't count as compliant.
- Q4 answered: **operational status comes from the external asset API** (see issue 03 Q4). PSS doesn't maintain it.
- Q3 answered: **Equipment requiring attention** = any of: overdue PM Task; a Fail/Defective item on its latest approved PM Report; an open Emergency-priority E-Call; not operating according to the external API.
- Q2 and Q5 answered: **vendor performance and recurring-issue/reliability metrics are out of the MVP**.

## Implementation decisions (dev-lead, 2026-11-24)

1. **Where the rules live** (each provisional rule in one place, easy to change): `App\Dashboard\PmCompliance` (`countsAsDone()` = Completed only; `dueWindowEnd()` = min(period end, today); `EXCLUDED_STATUSES` = Cancelled), `App\Dashboard\DashboardKpis::defaultPeriod()` (current Manila calendar month), `App\Dashboard\OperatingState::OPERATIONAL_VALUES` (`['operational']`, compared lower-cased and trimmed). `App\Dashboard\EquipmentAttention::reasons()` gives the reasons; `App\Dashboard\AttentionReason` fixes their order. `App\Dashboard\DashboardKpis` loads the data and calls these pure classes.
2. **API shape**: `data.pm.compliance = {period_from, period_to, due, on_time, percent}` with dates as `YYYY-MM-DD`, and `percent` a number with one decimal (e.g. `33.3`, `100.0`) or `null`. `data.equipment = {operating: {operational, non_operational, unknown}, requiring_attention: {count, items: [{equipment_id, equipment_name, site_id, reasons}]}}`. `equipment` is a new top-level key. No issue 27 key was renamed or removed.
3. **Percent rounding** uses integer arithmetic (on_time × 1000 ÷ due, rounded half-up to tenths), so it never hits float edge cases such as 6.25 → 6.3.
4. **"Today"** is `today()` in the app timezone (Asia/Manila). A test runs at 23:30 UTC on 30 Nov and checks that the period is already December.
5. **PM Compliance covers PM Tasks by their own columns** (`pm_tasks.site_id`, `equipment_category_id`, `vendor_id`). It isn't limited to Equipment the directory still lists, so tasks for Equipment that has since been removed still count toward compliance.
6. **"Most recent Completed PM Task"** for `failed_items` means the latest `due_date`, with the highest `id` as the tie-break. "Latest PM Report" means the highest `revision` of that task's report. A failed report on a task that isn't Completed yet (For Validation, Returned) doesn't count.
7. **`overdue_pm`** uses `PmTimelinessRule` on every open PM Task of the Equipment. A For Validation task whose Work Done Date was after its due date counts as Overdue, which is the existing issue 09 rule.
8. **E-Calls and open PM Tasks for `requiring_attention`** are matched by `equipment_id` only and aren't narrowed again by `vendor_id`. The vendor narrowing happens on the Equipment, through `site_settings.vendor_id`.
9. **The sort** is site_id, then equipment_id, using plain string comparison, so `EQ-10` sorts before `EQ-9`.
10. **Unknown filter values** (an unknown site, category or vendor) give zero counts and an empty list, which matches issue 27.
11. **Performance**: Equipment requiring attention takes 4 fixed queries (open PM Tasks, Completed PM Tasks, latest-revision failed reports, open Emergency E-Calls), PM Compliance takes 1, and the vendor's Sites take 1 more when there's a vendor filter. None of them grows with the number of Equipment, and a test asserts that. The directory is called once per covered Site (`listSites()` + `listEquipmentAtSite()`), which is fine with the in-memory fake. With the real HTTP adapter (issue 03), an unfiltered dashboard makes one API call per Site, so caching may be needed there. The Equipment IDs go into `WHERE IN (...)` in a single list, which suits MySQL. Thousands of Equipment would need chunking.
12. **Completed PM Tasks are loaded as `(id, equipment_id)` rows** for the covered Equipment, so the rows (not the queries) grow with PM history. If that gets slow, a "latest completed task per Equipment" subquery can replace it.
13. **Issue 27 tests**: `DashboardApiTest` used `assertExactJson` on the whole payload. It now checks each issue 27 section exactly (`data.pm.by_status`, `data.e_calls`, `data.work_orders`), and its exact-structure test lists the new keys too. The values it checks haven't changed.
14. **Vendor-staff forcing** moved into `DashboardCounts::visibleFilters()`, which both the counts and the KPIs use. The closed E-Call statuses are now `DashboardCounts::CLOSED_E_CALL_STATUSES`, shared with `emergency_e_call`.
15. **Docs**: `backend/README.md` has no dashboard section, so it wasn't changed (the criterion says "if it has one").

### Decisions (human, 2026-11-24)

- All four provisional interpretations above are **confirmed**: Completed-only, tasks due later in the period left out, the current Manila month as the default period (issue 42 now adds `from`/`to`), and `operational` / empty / other for the operating status.
- A security review found that for vendor staff, the attention reasons count every Vendor's PM Tasks and E-Calls. Follow-up: issue 49.
