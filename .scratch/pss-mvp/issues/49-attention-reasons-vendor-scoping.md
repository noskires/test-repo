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
