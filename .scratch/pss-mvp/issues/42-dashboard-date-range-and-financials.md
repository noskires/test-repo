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
