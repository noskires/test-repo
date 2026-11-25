# 42 — Dashboard: date range and financial sums

Status: ready-for-agent
Blocked by: 27
Type: task

## What to build

Split from issue 27 on 2026-11-24. PRD §4.1 asks the dashboard (`GET /api/dashboard`) for a date range filter and financial figures:
- PM: PM Tasks Completed in the date range.
- Financial: total estimated amount of Work Orders, the approved amount (Work Orders past LVL2 endorsement), and the sum of Billing Amounts. All of these by Site and by Vendor.

## Open questions (for the human)

1. **What does the date range apply to?** For "PM Tasks Completed in the range" we'd use the Work Done Date (like PM Timeliness). For the financial sums: the Work Order's creation date, the date LVL2 approved it, the date it was Closed, or something else? Should the date range also narrow the current status counts from issue 27, and if so by which date?
2. **Which Work Orders count in each sum?**
   - Total estimated amount: every Work Order, or only those not `Rejected` / `Cancelled`?
   - Approved amount ("past LVL2 endorsement"): a Work Order can be Rejected later, at completion validation (issue 21). Does a Work Order that was approved at LVL2 and then Rejected still count?
   - Billing Amounts: only `Closed` Work Orders (billing endorsement finished), or also those still in billing endorsement?
3. Should vendor staff see financial figures at all (limited to their own Vendor), or only internal users?

## Decisions (human, 2026-11-24)

- **Each figure filters on its own event date**: PM Tasks Completed by **Work Done Date**; estimated amount by the Work Order's **creation date**; approved amount by the **LVL2 approval date**; Billing Amounts by the **Closed date**.
- Issue 27's **status counts ignore the range**. They stay current-state.
- **Estimated amount**: every Work Order except `Rejected` and `Cancelled`.
- **Approved amount**: Work Orders that have passed LVL2 endorsement, **except ones Rejected later**.
- **Billing Amounts**: `Closed` Work Orders only.
- **Vendor staff see their own Vendor's totals only** (forced, like issue 27).
- This unblocks issue 29.

## Acceptance criteria

- [ ] `GET /api/dashboard` accepts optional `from` and `to` (dates, `Y-m-d`, inclusive, Asia/Manila). `from` after `to`, or a bad date: 422.
- [ ] Without `from`/`to`, the period is the current Manila calendar month, the same default as issue 28's PM Compliance. When they're given, PM Compliance (`pm.compliance`) uses them too.
- [ ] `pm.completed_in_range`: PM Tasks `Completed` whose Work Done Date is in the range.
- [ ] `financials` = `{estimated, approved, billing}`. Each has a `total` plus `by_site` (`[{site_id, site_name, amount}]`) and `by_vendor` (`[{vendor_id, vendor_name, amount}]`). Amounts are decimal strings, exact per ADR 0002 (sum the already-rounded document totals, no float).
  - `estimated`: the Work Order estimated amount, for Work Orders not `Rejected` or `Cancelled` whose `created_at` (Manila date) is in the range.
  - `approved`: the estimated amount of Work Orders with an LVL2 `approve` Approval Record whose date is in the range, minus any now `Rejected`. If LVL2 approved more than once (after a Return), use the latest.
  - `billing`: the Billing Amount of `Closed` Work Orders whose close date (the Approval Record or status change that closed it) is in the range.
- [ ] The `site_id`, `equipment_category_id` and `vendor_id` filters apply to the new figures as in issue 27 (Work Orders through their E-Call). Vendor staff are forced to their own Vendor.
- [ ] Issue 27's `by_status` / `groups` counts are unchanged by `from`/`to` (regression test).
- [ ] With seeded fixtures, each sum matches a hand-calculated expectation, in total, by Site and by Vendor, including: a Work Order on the range boundary (in), one a day outside (out), a Rejected one after LVL2 (out of approved), a Cancelled one (out of estimated), one still in billing endorsement (out of billing).
- [ ] No query per Work Order: grouped SUM queries.

## Comments

### Clarifications (product-owner, 2026-11-24)

- **Provisional (to confirm with the human)**: the default period when `from`/`to` are missing is the current Manila calendar month, matching issue 28. The dates are inclusive and compared as Manila dates.
- Where things live: `App\Dashboard\DashboardCounts` (issue 27), `DashboardKpis` and `PmCompliance` (issue 28; `DashboardKpis::defaultPeriod`), `DashboardController`, `tests/Feature/DashboardApiTest.php`. Approval Records hold the LVL2 approval and close events (issues 19 and 23). Money rules: docs/adr/0002-money-rounding.md.
- Record your decisions (how the close date and LVL2 date are read, response shape details) under a dated "Decisions (dev-lead, ...)" heading here.

### Clarifications (product-owner, 2026-11-25)

- Re-checked after issues 44 (PR #50) and 49 (PR #51) merged. Scope is unchanged. Issue 49 added the effective Vendor to `DashboardKpis` attention. Reuse that same effective-Vendor value for `financials` and `pm.completed_in_range`; don't add a second way to work it out.
- The default period is still provisional: the human hasn't confirmed it. Build it as written in the acceptance criteria. The releaser lists it under "Decisions to confirm" in the PR.
- Money: sum the stored, already-rounded `work_orders.estimated_amount_centavos` and `billing_amount_centavos` (integers). Format them as decimal strings only at the edge, the same way existing endpoints format centavos. Never sum `*_millicentavos`.
- Event dates come from `approval_records` (`action`, `previous_status`, `resulting_status`, `created_at`), with no new columns:
  - LVL2 approval: an `approve` record whose `previous_status` is `for_lvl2_endorsement` (`App\Enums\WorkOrderStatus::ForLvl2Endorsement`). Use the latest one per Work Order.
  - Close date: the record whose `resulting_status` is `closed`.
  - Compare both as Manila dates.
  - If the records can't answer either one reliably, stop and write it in this issue rather than adding a column.
- "Minus any now Rejected" means the Work Order's current status is `rejected`. Cancel is allowed only up to For Lvl2 Endorsement (GLOSSARY "Cancel", issue 25), so a Cancelled Work Order should never have passed LVL2. If you find a path where it can (for example a Return back to For Lvl2 Endorsement after an earlier LVL2 approve), don't decide it yourself. Write it here as an open question for the human and count it as the current rule says: only `rejected` is excluded.
- A Site or Vendor with no amount in the range is left out of `by_site` / `by_vendor` (no zero rows). Sort each list by name. `total` is `"0.00"` when nothing matches.
- Site and Vendor names come from the PSS tables. Use snapshots where the Work Order or E-Call already has them, the same way issue 27's filters reach the Work Order through its E-Call.
- No doc changes are expected. If the GLOSSARY Dashboard entry needs the new figures, note it here and the product-owner will add it.

## Implementation decisions

Decisions (dev-lead, 2026-11-25):

1. **Range parameters**: `from` and `to` are `Y-m-d`, inclusive, read as Manila calendar dates. They come as a pair: sending only one is a 422 on the missing one (`required_with`). `to` before `from`, an impossible date (`2026-13-01`) or another format: 422. A one-day range (`from` = `to`) is allowed. No maximum range length.
2. **Default period** (provisional, to confirm with the human): without `from`/`to`, the current Manila calendar month (`DashboardKpis::defaultPeriod`), as issue 28. The applied period is echoed at `pm.compliance.period_from` / `period_to`. No separate top-level `period` key was added.
3. **One period for everything range-based**: `pm.compliance`, `pm.completed_in_range` and `financials` all use the same period. `DashboardKpis::for` takes it as a new optional `$period` argument. The issue 27 status counts and `equipment` ignore it.
4. **Response shape**: `data.pm.completed_in_range` (int) and `data.financials.{estimated,approved,billing}`, each `{total, by_site: [{site_id, site_name, amount}], by_vendor: [{vendor_id, vendor_name, amount}]}`. Amounts are decimal strings with two places (`PesoAmount::fromCentavos`), e.g. `"750.51"`. `total` is `"0.00"` when nothing matches. Rows with no amount are left out (no zero rows). `by_site` is sorted by site name then id, `by_vendor` by vendor name then id.
5. **Money**: sums the stored integer `estimated_amount_centavos` / `billing_amount_centavos` with SQL `SUM`. No `*_millicentavos` and no floats. Totals are the integer sum of the same per-(Site, Vendor) group rows, so `total` always equals the sum of `by_site` and of `by_vendor`.
6. **Event dates** (all from existing columns, no new ones). Timestamps are stored in the app timezone (Asia/Manila), so a Manila date `D` is compared as `created_at >= 'D 00:00:00'` and `< 'D+1 00:00:00'`, which keeps the index usable.
   - Estimated: `work_orders.created_at`, excluding current status `rejected` and `cancelled`.
   - Approved: the latest `approval_records` row per Work Order with `subject_type = work_order`, `action = approve`, `previous_status = for_lvl2_endorsement` (`MAX(created_at)`), excluding a current status of `rejected` only. The amount is the Work Order's estimated amount (it can only be revised while `open`, so it is the amount LVL2 approved).
   - Billing: the latest row with `resulting_status = closed` per Work Order, for Work Orders whose current status is `closed`. The amount is `billing_amount_centavos`.
7. **Cancelled after LVL2**: none found. LVL2 approve moves a Work Order to For Implementation, and no transition leads from there or later back to Open / For Lvl1 / For Lvl2 Endorsement (`WorkOrderStatus::afterEndorsement`, `isCancellable`, `WorkOrder::cancel`). So a Cancelled Work Order can't have passed LVL2 today, and "LVL2 approved more than once" can't happen yet either. The latest-approval rule is built and tested with seeded records in case a later issue adds such a path. No question for the human.
8. **Site and Vendor names**: Site name is `e_calls.site_name_snapshot`. If E-Calls of one Site carry different snapshots (the Site was renamed), the alphabetically greatest is shown (`MAX`) so the result is deterministic. Vendor name is `vendors.name` (PSS table, current name), read in one `whereIn` query.
9. **Filters and Vendor scoping**: `site_id`, `equipment_category_id` and `vendor_id` apply on the Work Order's E-Call for `financials`, and on `pm_tasks` columns for `pm.completed_in_range`. The effective Vendor is the same `DashboardCounts::visibleFilters` value issue 49 uses for attention, so vendor staff always get their own Vendor's figures, whatever `vendor_id` they pass.
10. **`pm.completed_in_range`**: PM Tasks with status `completed` and `work_done_date` in the range. It is compared as `>= from` and `< to + 1 day` because the `immutable_date` cast stores `Y-m-d 00:00:00` on SQLite. A plain `whereBetween` dropped the last day there.
11. **Queries**: fixed in number whatever the number of Work Orders. One `COUNT` for `pm.completed_in_range`, one grouped `SUM` per money figure (grouped by E-Call `site_id`, `vendor_id`), and one Vendor-name lookup. Totals and the by-Site / by-Vendor lists are folded in PHP from those few rows. A test asserts the query count does not grow with the Work Orders.
12. **Existing tests touched**: the shape assertions in `DashboardApiTest` (exact JSON structure, COUNT query count from 3 to 4) and `DashboardKpisApiTest` (key lists) now include the new keys. The issue 27 values are unchanged.
13. **Observation, out of scope**: `DashboardKpis::pmCompliance` uses `whereBetween('due_date', [from, windowEnd])`. On SQLite a task due exactly on the window end is stored as `Y-m-d 00:00:00` and may fall outside that string comparison. MySQL `date` columns are not affected. Not changed here; worth a follow-up check.
14. **GLOSSARY**: the Dashboard entry could mention the date range and the three financial figures. Left for the product-owner.
