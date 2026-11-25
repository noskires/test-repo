# 49 — Equipment Requiring Attention: count only the Vendor's own records (chore, security)

Status: ready-for-agent
Blocked by: 28
Type: chore

## What to build

A security review of issue 28 found that `DashboardKpis::requiringAttention` and `equipmentWithFailedLatestReport` look at **every** Vendor's PM Tasks and E-Calls on the covered Equipment. Vendor staff only see Equipment at their own Vendor's Sites. But if a Site changed Vendor, a former Vendor's overdue PM Task, failed PM Report or emergency E-Call still adds a reason. That tells vendor staff about another Vendor's records.

## Decisions (human, 2026-11-24)

- For vendor staff, the reasons `overdue_pm`, `failed_items` and `emergency_e_call` count **only their own Vendor's** PM Tasks and E-Calls, through a `vendor_id` filter or the existing visibility scopes.

## Acceptance criteria

- [ ] When the effective Vendor is set (vendor staff, forced to their own Vendor), the open-PM-Task, failed-latest-report and emergency-E-Call queries all filter on that Vendor (`pm_tasks.vendor_id`, `e_calls.vendor_id`), or reuse the existing visibility scope.
- [ ] Feature tests, each with a Site whose PM Task or E-Call belongs to another Vendor (e.g. the Site's previous Vendor). Vendor staff see no `overdue_pm`, no `failed_items` and no `emergency_e_call` reason from it. An internal user still sees all three.
- [ ] `failed_items` for vendor staff uses the latest Completed PM Task **of their Vendor**.
- [ ] `not_operating` is unchanged (it comes from master data, not from a Vendor's records).
- [ ] An internal user who passes `vendor_id` gets the same narrowing (consistent with issue 27's filters). Test it.
- [ ] The query count stays fixed (the issue 28 query-count test still passes).

## Comments

### Clarifications (product-owner, 2026-11-24)

- This is a small chore. The code is in `backend/app/Dashboard/DashboardKpis.php` (`requiringAttention`, `equipmentWithFailedLatestReport`). The tests are in `tests/Feature/DashboardApiTest.php` or the KPI tests from issue 28.
- Applying the same narrowing to an explicit `vendor_id` filter is the product-owner's reading for consistency. The human's decision names vendor staff only.
- Record your decisions under a dated "Decisions (dev-lead, ...)" heading here.

### Clarifications (product-owner, 2026-11-25)

- Re-checked after issue 44 merged (PR #50). Nothing in 44 touches the dashboard, and the scope is unchanged.
- `DashboardKpis::requiringAttention(array $equipment, CarbonImmutable $today)` (around line 132) and `equipmentWithFailedLatestReport(array $equipmentIds)` (around line 187) take no Vendor today. Pass the effective Vendor (vendor staff are forced to their own Vendor, or an internal user's explicit `vendor_id` filter) from the caller, the same way the issue 27 counts already receive it. With no effective Vendor, the behaviour stays exactly as it is now.
- The existing attention tests are in `backend/tests/Feature/DashboardKpisApiTest.php`; that file holds the issue 28 query-count test. Add the new cases there.
- For the "former Vendor" test setup, create the PM Task or E-Call with a `vendor_id` other than the Site's current Vendor. You don't need to model a Vendor change history.
- Small backend chore. No doc changes are expected (the GLOSSARY entry for Equipment requiring attention doesn't describe Vendor scoping). If you find that it does, note it here.

## Implementation decisions

### Decisions (dev-lead, 2026-11-25)

1. The effective Vendor is `$filters['vendor_id']` after `DashboardCounts::visibleFilters` (vendor staff forced to their own Vendor; otherwise an internal user's explicit `vendor_id`). `DashboardKpis::for` passes it to `requiringAttention(..., ?int $vendorId, ...)`, which passes it to `equipmentWithFailedLatestReport($equipmentIds, ?int $vendorId)`. With no effective Vendor (`null`) the queries are unchanged.
2. Narrowing is a plain `where vendor_id = ?` added with `->when(...)` on the three existing queries (open PM Tasks, latest Completed PM Tasks, open Emergency E-Calls). No new query, so the query count stays fixed (issue 28 query-count test passes unchanged).
3. `failed_items` with a Vendor: "latest Completed PM Task" is chosen among that Vendor's Completed PM Tasks only. So if the Vendor's own latest task failed but another Vendor later completed a clean one, vendor staff still see `failed_items`; an internal user without a filter does not (their latest task is the clean one). Tested both ways.
4. `not_operating` is not narrowed (comes from the external API's master data).
5. An internal user's explicit `vendor_id` gets the same narrowing (product-owner's consistency reading; the human's decision names vendor staff only). Confirm this.
6. No API shape change, no migration, no doc change: the GLOSSARY "Equipment Requiring Attention" entry does not mention Vendor scoping.
7. Test helper `eCall()` in `DashboardKpisApiTest` gained an optional `$attributes` array (like `pmTask()`), used to set another Vendor's `vendor_id`.
