# 27 — Dashboard: operational counts

Status: ready-for-agent
Blocked by: 11, 17, 21
Type: task

## What to build

PRD §4.1: one endpoint `GET /api/dashboard` returning the **current counts that are already well defined**, with filters for site, equipment category and vendor:
- PM: open PM Tasks by PM Status.
- CM: E-Calls by E-Call Status, plus an open total. Work Orders by status, plus the groups: awaiting endorsement, under implementation, awaiting EIC validation, awaiting billing endorsement, Closed.

Split on 2026-11-24: the **date range filter**, "PM Tasks Completed in the date range" and the **financial sums** moved to issue 42 (needs-info), because what the date range applies to and which Work Orders count toward "approved" are still open. PM Timeliness counts, PM compliance % and Equipment requiring attention are issue 28's.

## Acceptance criteria

- [x] With seeded fixtures, every count matches a hand-calculated expectation:
  - PM: one count per open PM Status (`Scheduled`, `In Progress`, `For Validation`, `Returned`); `Completed` and `Cancelled` tasks aren't counted.
  - E-Calls: one count per E-Call Status (all seven, zero included), and `open` = every status except `Closed`, `Rejected` and `Cancelled`.
  - Work Orders: one count per Work Order Status (all of them, zero included), and the groups `awaiting_endorsement` (For Lvl1 + For Lvl2 Endorsement), `under_implementation` (For Implementation), `awaiting_eic_validation` (Implemented), `awaiting_billing_endorsement` (For Billing Endorsement LVL1–LVL4), `closed` (Closed).
- [x] `site_id`, `equipment_category_id` and `vendor_id` each narrow every count, a test each, and they combine (AND).
- [x] Vendor staff only ever get counts for their own Vendor, even if they pass another `vendor_id`.
- [x] An unknown filter value returns all-zero counts (200), not an error. A `vendor_id` that isn't an integer returns 422.

## Comments

- 2026-11-24 (product-owner): **Split and clarifications for dev-lead** (checked against main at 8f8483c):
  - **Scope cut**: no date range, no "Completed in the date range", no money in this slice (issue 42). No PM Timeliness, compliance % or Equipment requiring attention (issue 28). Shape the response so 28 and 42 can add keys later without renaming: e.g. `{ pm: { by_status: {...} }, e_calls: { by_status: {...}, open: n }, work_orders: { by_status: {...}, groups: {...} } }`. Use the enum values as keys.
  - **Counts are current state**: they count records as they stand now, not over a period.
  - **Filter columns**: PM Tasks use `pm_tasks.site_id`, `equipment_category_id`, `vendor_id`. E-Calls use `e_calls.site_id`, `equipment_category_id`, `vendor_id`; an E-Call with no Equipment is excluded when an `equipment_category_id` filter is set. Work Orders have none of these columns: filter through their E-Call (`work_orders.e_call_id`).
  - **Who can see it**: everyone signed in. Vendor staff (`vendor_id !== null`) are always limited to their own Vendor, the same rule as the existing `view` policies and issue 26. Roles aren't defined yet (issue 04). This is a product-owner reading; put it under "Decisions to confirm" in the PR.
  - **"Cancelled"**: the original text said open E-Calls = not `Closed` / `Rejected`. That was written before Cancel existed (issue 25); `Cancelled` isn't open either (GLOSSARY "Cancel").
  - **Queries**: use grouped counts (`GROUP BY status`), not one query per status and not loading rows into PHP.
  - **Out of scope**: frontend screen, caching.
- 2026-11-24 (qa-27b): QA PASS. `php artisan test --compact` (backend/): 1078 passed (5188 assertions). `vendor/bin/pint --test` passed. DashboardApiTest: 15 tests. Every criterion maps to a real test; frontend untouched.

## Implementation decisions

1. **Endpoint**: `GET /api/dashboard` (route name `dashboard.index`), behind `auth` like the other routes. Anyone signed in may call it (product-owner reading, roles not defined yet, issue 04). Code: `backend/app/Dashboard/DashboardCounts.php`, `backend/app/Http/Controllers/Api/DashboardController.php`.
2. **Response shape** (wrapped in `data`, like the other endpoints): `{ pm: { by_status: {scheduled, in_progress, for_validation, returned} }, e_calls: { by_status: {all 7 E-Call Status values}, open }, work_orders: { by_status: {all 12 Work Order Status values}, groups: {awaiting_endorsement, under_implementation, awaiting_eic_validation, awaiting_billing_endorsement, closed} } }`. Keys are the enum values; every key is always present, zero included. `pm.by_status` lists only the four open PM Statuses (Completed and Cancelled are left out, not shown as 0). Issues 28 and 42 can add keys beside these.
3. **Filters**: `site_id` and `equipment_category_id` are strings (max 255, external IDs), `vendor_id` is an integer, and any other value gives 422. They AND together. An unknown value (no such site, category or vendor) gives all-zero counts with 200. The MasterDataDirectory is never called, so filter values aren't checked against the external API.
4. **Work Orders** are filtered through their E-Call (join on `work_orders.e_call_id`), using the E-Call's `site_id`, `equipment_category_id` and `vendor_id`. A Work Order's E-Call is counted in the E-Call counts too (usually as `approved`), because they're separate records.
5. **E-Call with no Equipment**: it's counted when no category filter is set and left out when `equipment_category_id` is set (its category is null).
6. **Vendor staff** (`users.vendor_id !== null`): their own `vendor_id` always replaces any `vendor_id` they pass, so passing another Vendor's ID gives their own counts and no error. A non-integer `vendor_id` is still a 422 for them.
7. **Queries**: three grouped `COUNT(*) ... GROUP BY status` queries, one each for PM Tasks, E-Calls and Work Orders, with no rows loaded into PHP. A test pins this at 3 count queries. Indexes already exist on `site_id` (PM Tasks and E-Calls), and `vendor_id` is a foreign key. There's no index on `equipment_category_id` or `status`. That's fine at MVP volume; consider composite indexes if the dashboard gets slow. No caching (out of scope).
8. **Time**: the counts are current state, with no date logic, so the timezone isn't involved.
