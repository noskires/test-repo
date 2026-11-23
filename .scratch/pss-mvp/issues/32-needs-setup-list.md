# 32 — Admin "Needs Setup" list

Status: ready-for-agent
Blocked by: 08, 19
Type: task

## What to build

A read-only list for administrators of what stops PM Tasks from being generated (glossary "Needs Setup list", issue 08 decisions). It is **computed live** from the directory and PSS's own Site settings and PM Tasks. Nothing new is stored.

Two kinds of entry:
- **Site**: a Site that has a PM Frequency but no Site Vendor, or no EIC, or neither. The reason says which: `no_site_vendor`, `no_eic`.
- **Equipment**: an Equipment at a Site with a PM Frequency that has no `installedOn` in the directory and no PM history in PSS. Reason: `no_start_date`. Each entry shows the Equipment and its Site.

- **Work Order** (added 2026-11-23, human decision on issue 19): a Work Order in `Open`, `For LVL1 Endorsement` or `For LVL2 Endorsement` whose remaining endorsement levels don't resolve right now from the E-Call Requestor's Immediate Head chain (`ImmediateHeadChain`). That means levels 1 and 2 for `Open` and `For LVL1 Endorsement`, and level 2 for `For LVL2 Endorsement`. Reasons: `no_requestor_employee_id`, `missing_endorser_level_1`, `missing_endorser_level_2`, `endorsement_chain_conflict` (the same person at two levels, or a head who is the Requestor; issue 38). A cycle in the chain shows as a missing level. Each entry shows the Work Order, its E-Call and the Requestor.
- **Work Order, billing chain gap** (added 2026-11-23, human decision on issue 23): a Work Order in `Implemented` or `For Billing Endorsement LVLn` whose next or current billing level (levels 1 to 4, up to the levels required right now from the rounded Billing Amount and the current thresholds) doesn't resolve from the Requestor's chain. Reasons: `no_requestor_employee_id`, `missing_billing_endorser_level_<n>`. The billing part needs issues 21 and 23's statuses.

Endpoint: `GET /api/needs-setup`, behind the `manage-site-settings` Gate. Each entry has a type (`site` / `equipment`), IDs, display names and reasons.

## Acceptance criteria

- [ ] A Site with a PM Frequency and no Site Vendor appears with `no_site_vendor`. One with no EIC appears with `no_eic`. Both reasons are listed when both are missing.
- [ ] A Site with no PM Frequency does not appear, and neither does its Equipment.
- [ ] An Equipment with no `installedOn` and no PM Task appears with `no_start_date`. Once the fake directory gives it an `installedOn`, it no longer appears.
- [ ] An Equipment with no `installedOn` that already has PM history does not appear.
- [ ] A Work Order whose Requestor has no level-2 Immediate Head appears with `missing_endorser_level_2`. Once the fake directory fixes the chain, it no longer appears. `For Implementation`, `Rejected` and later Work Orders never appear.
- [ ] 403 when the Gate denies, 401 when unauthenticated. Tests use the directory fake. No new packages, and `php artisan test` passes.

## Comments

- 2026-11-22 (product-owner): Created from the human's answer on issue 08: Equipment with no installation date and no PM history gets no task and is listed here until a start date is set. Issue 08 skips these Equipment and Sites but doesn't build the list. If issue 33 adds a PSS-side start date, an Equipment that has one leaves this list too. That change belongs to 33.
- 2026-11-23 (product-owner): Added **Work Order** entries (a broken endorsement chain) from the human's issue 19 decision: block and flag. Blocked by 19 now as well, since it needs 19's Work Order statuses. Still computed live, with nothing stored.
- 2026-11-23 (product-owner): Added **billing chain gaps** from the human's issue 23 decision. To keep 32 small and unblocked, build the Site, Equipment and endorsement-gap entries now (blocked by 08 and 19). The **billing** entries are added by **issue 23** if 32 is already merged, or by 32 itself if 23 merges first. Whichever is built second adds them, so 32 doesn't wait for 21 and 23. Issue 23's criteria already require the Work Order to be flagged.
- 2026-11-23 (product-owner): Issue 23 merged first (PR #28) without adding the billing entries, so per the comment above **32 now builds the billing chain-gap entries as well**. Add this criterion: a Work Order in `Implemented` or `For Billing Endorsement LVLn` whose required billing level n has no Immediate Head appears with `missing_billing_endorser_level_<n>`, and it no longer appears once the fake directory fixes the chain. Reuse how issue 23 works out the required levels; don't copy it.
