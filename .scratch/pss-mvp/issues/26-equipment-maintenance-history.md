# 26 — Equipment maintenance history

Status: done
Merged: PR #41 (993c5a2), 2026-11-24
Blocked by: 11, 13, 18
Type: task

## What to build

PRD §5.3 / §7.1: one combined, read-only timeline per Equipment (external ID): its PM Tasks (with status, Work Done Date, findings), E-Calls (with priority, status), and Work Orders (with status, estimated amount, and Billing Amount when set). Each entry links to its record. Entries for Equipment that has since been removed upstream still show, using the stored **Snapshot**.

Endpoint: `GET /api/equipment/{equipmentId}/history`, newest first, with optional `from` / `to` date filters.

## Acceptance criteria

- [x] PM Tasks, E-Calls and Work Orders for that Equipment are returned in one list, newest first by entry date (see clarifications). Records for other Equipment aren't included. Records in every status are included, including `Cancelled` and `Rejected`.
- [x] Each entry has its type (`pm_task` / `e_call` / `work_order`), record ID, entry date, status, and the type's fields: PM Task Work Done Date and findings (latest PM Report revision); E-Call priority; Work Order estimated amount and Billing Amount (null until set), as exact money values.
- [x] An E-Call filed from a PM Task shows the link to that PM Task. A Work Order entry shows its E-Call ID.
- [x] Removing the Equipment from the directory fake still returns its history, with the snapshot name. The endpoint never calls the directory.
- [x] `from` and `to` (inclusive, `Y-m-d`) narrow the list by entry date, a test each. `from` after `to`, or a bad date, returns 422.
- [x] Vendor staff see only records of their own Vendor. Internal users see all. An Equipment ID with no records returns 200 with an empty list.

## Comments

- 2026-11-24 (qa-26): QA PASS. `php artisan test --compact` (backend/): 1063 passed (5015 assertions). `vendor/bin/pint --test` passed. EquipmentHistoryApiTest: 23 tests. Every criterion maps to a real test; frontend untouched.

- 2026-11-24 (product-owner): **Clarifications for dev-lead** (checked against main at 4ae2d21):
  - **Entry date** (sort key and what `from` / `to` filter): PM Task = `due_date`; E-Call = the date it was filed (`created_at` as a Manila date, ADR 0003); Work Order = the date it was created (`created_at`, Manila date). Ties: newer `created_at` first, then higher ID. This is a product-owner reading of "newest first", listed under "Decisions to confirm" for the PR.
  - **Which Equipment**: match on the stored `equipment_id` (`pm_tasks.equipment_id`, `e_calls.equipment_id`). Work Orders have no equipment column: they belong to the Equipment of their E-Call (`work_orders.e_call_id`). E-Calls with no Equipment never appear.
  - **Snapshots**: use the stored snapshot columns (`equipment_name_snapshot`, `equipment_category_snapshot`, `site_name_snapshot`) on each entry. A Work Order entry uses its E-Call's snapshots. Don't call `MasterDataDirectory`. "Removing from the fake" in the test means binding a directory fake that lacks the Equipment and checking the response is unchanged.
  - **Findings**: `pm_reports.findings` of the task's latest revision, or null if no report yet.
  - **Visibility**: same rule as the existing `view` policies (`PmTaskPolicy::view`, `ECallPolicy::view`, `WorkOrderPolicy::view`): a user with `vendor_id === null` sees all; vendor staff see only records whose `vendor_id` (the Work Order's: its E-Call's) is their own. Filter in the query, don't 403. `ECall::scopeVisibleTo` already exists; reuse or mirror it.
  - **Money**: return `estimated_amount` / `estimated_amount_exact` / `billing_amount` / `billing_amount_exact` exactly as `WorkOrderResource` does (`PesoAmount::fromCentavos` / `fromMillicentavos`, ADR 0002). No new rounding.
  - **Links**: "links to its record" = the entry's `type` + `id` (the frontend builds the URL). No URL field needed.
  - **No N+1**: load the latest PM Report findings and Work Order E-Calls with eager loading or joins; add a query-count test or assert on a fixture with several records.
  - **Out of scope**: pagination, frontend screen, report export (issue 29).

## Implementation decisions

1. **Endpoint**: `GET /api/equipment/{equipmentId}/history` (route `equipment.history.index`, behind `auth`), invokable `EquipmentHistoryController`. The query lives in `App\History\EquipmentHistory`, a new folder beside `app/Waiting` and `app/NeedsSetup`.
2. **Response shape**: `{"data": [entry, ...]}`. No pagination (out of scope), so an Equipment with a very long history returns everything in one response. Every entry has `type` (`pm_task` / `e_call` / `work_order`), `id`, `entry_date` (`Y-m-d`), `status` (enum value), `site_id`, `site_name_snapshot`, `equipment_id`, `equipment_name_snapshot`, `equipment_category_snapshot` and `created_at`. The type's own fields:
   - `pm_task`: `work_done_date` (the task's own `pm_tasks.work_done_date`, null until set) and `findings` (`pm_reports.findings` of the highest revision, null with no report).
   - `e_call`: `priority`, `pm_task_id` (null unless filed from a PM Task).
   - `work_order`: `e_call_id`, `estimated_amount`, `estimated_amount_exact`, `billing_amount`, `billing_amount_exact` (null until set), formatted exactly like `WorkOrderResource` (ADR 0002).
   Fields of the other types are left out, not sent as null. The frontend can treat `type` as the discriminator.
3. **Entry date and order** follow the product-owner clarification. Ties: newer `created_at` first, then higher ID, then `type` in descending string order (`work_order`, `pm_task`, `e_call`), so two records of different types with the same date, timestamp and ID still sort the same way every time.
4. **Date filters**: `from` / `to` must be `date_format:Y-m-d` (so `2026-02-30` is a 422). `to` gets `after_or_equal:from` only when `from` is sent. E-Call and Work Order filters are timestamp ranges (`>= from 00:00:00`, `< to+1 00:00:00`) on the stored Manila wall-clock `created_at` (ADR 0003), so the index can still be used. The PM Task filter is a `whereDate` on `due_date`.
5. **Visibility**: vendor staff get PM Tasks with their own `vendor_id`, plus E-Calls and Work Orders through `ECall::scopeVisibleTo` (a Work Order goes by its E-Call's Vendor). Internal users see all. Records are filtered out, never 403. An unknown Equipment ID returns 200 with `{"data": []}`, and it isn't checked against the directory.
6. **No directory calls**: everything comes from stored columns and snapshots. A test binds a Mockery mock of `MasterDataDirectory` with no expectations, so any call would fail. A Work Order takes its E-Call's snapshots.
7. **Queries**: a fixed number, whatever the number of records (PM Tasks with a correlated subquery for the latest findings; E-Calls; Work Orders with `whereHas`; one eager load of their E-Calls). A test checks that the query count doesn't change between 2 and 14 records. The three lists are merged and sorted in PHP.
