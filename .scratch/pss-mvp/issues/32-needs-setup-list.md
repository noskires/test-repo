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

Endpoint: `GET /api/needs-setup`, behind the `manage-site-settings` Gate. Each entry has a type (`site` / `equipment` / `work_order`), IDs, display names and reasons.

## Acceptance criteria

- [x] A Site with a PM Frequency and no Site Vendor appears with `no_site_vendor`. One with no EIC appears with `no_eic`. Both reasons are listed when both are missing.
- [x] A Site with no PM Frequency does not appear, and neither does its Equipment.
- [x] An Equipment with no `installedOn` and no PM Task appears with `no_start_date`. Once the fake directory gives it an `installedOn`, it no longer appears.
- [x] An Equipment with no `installedOn` that already has PM history does not appear.
- [x] A Work Order whose Requestor has no level-2 Immediate Head appears with `missing_endorser_level_2`. Once the fake directory fixes the chain, it no longer appears. `For Implementation`, `Rejected` and later Work Orders never appear.
- [x] A Work Order in `Implemented` or `For Billing Endorsement LVLn` whose required billing level n has no Immediate Head appears with `missing_billing_endorser_level_<n>`, and it no longer appears once the fake directory fixes the chain. (Added from the 2026-11-23 comment below.)
- [x] 403 when the Gate denies, 401 when unauthenticated. Tests use the directory fake. No new packages, and `php artisan test` passes.

## Comments

- 2026-11-22 (product-owner): Created from the human's answer on issue 08: Equipment with no installation date and no PM history gets no task and is listed here until a start date is set. Issue 08 skips these Equipment and Sites but doesn't build the list. If issue 33 adds a PSS-side start date, an Equipment that has one leaves this list too. That change belongs to 33.
- 2026-11-23 (product-owner): Added **Work Order** entries (a broken endorsement chain) from the human's issue 19 decision: block and flag. Blocked by 19 now as well, since it needs 19's Work Order statuses. Still computed live, with nothing stored.
- 2026-11-23 (product-owner): Added **billing chain gaps** from the human's issue 23 decision. To keep 32 small and unblocked, build the Site, Equipment and endorsement-gap entries now (blocked by 08 and 19). The **billing** entries are added by **issue 23** if 32 is already merged, or by 32 itself if 23 merges first. Whichever is built second adds them, so 32 doesn't wait for 21 and 23. Issue 23's criteria already require the Work Order to be flagged.
- 2026-11-23 (product-owner): Issue 23 merged first (PR #28) without adding the billing entries, so per the comment above **32 now builds the billing chain-gap entries as well**. Add this criterion: a Work Order in `Implemented` or `For Billing Endorsement LVLn` whose required billing level n has no Immediate Head appears with `missing_billing_endorser_level_<n>`, and it no longer appears once the fake directory fixes the chain. Reuse how issue 23 works out the required levels; don't copy it.
- 2026-11-23 (product-owner, hand-off clarifications, checked against main `2816e57`):
  1. **Entry types.** `type` is one of `site`, `equipment`, `work_order`. Billing chain gaps are `work_order` entries too; the reason (`missing_billing_endorser_level_<n>`) tells them apart. One entry per record, with all its reasons in a `reasons` array.
  2. **Equipment "no PM history" = what the PM generation job sees.** List an Equipment when `GeneratePmTasks` would skip it for lack of a start date: no `installedOn`, no open PM Task, and no `Completed` PM Task with a Work Done Date (`backend/app/Jobs/GeneratePmTasks.php`, `dueFromDate()`). Share that rule with the job (pull it out into one place both call). Don't write a second copy that could drift. The list must say exactly why the job skips something.
  3. **Equipment at a Site that is missing its Vendor or EIC** still gets its own `no_start_date` entry. The two problems are separate fixes. Only a Site with **no PM Frequency** hides its Equipment.
  4. **Work Order chain checks: reuse, don't copy.** The rules now live as private, exception-throwing methods on `backend/app/Models/WorkOrder.php` (`assertNoChainConflict`, `resolveEndorser`, `assertBillingChainSound`, `billingEndorserAtLevel`, `requiredBillingLevels`). Refactor them into one place that *reports* the gaps (e.g. returns the reasons), and have the throwing methods use it, so endorsing and the list can never disagree. Existing Work Order endorsement and billing tests must still pass unchanged.
  5. **One `ImmediateHeadChain` per request**, shared across all Work Orders in the list (see its docblock, issue 40). Use `BillingThresholdSetting::current()` once per request.
  6. **Billing entries.** Only levels 1 to the levels required now (current thresholds, rounded Billing Amount). Use `missing_billing_endorser_level_<n>` for the **lowest** level that's missing, at or after the level the Work Order waits at now: level 1 for `Implemented`, level n for `For Billing Endorsement LVLn`. A billing **conflict** (the same person at two billing levels, or the Requestor as a billing endorser) isn't in the agreed reasons, so don't list it here. It's an open question for the human, not part of this issue.
  7. **Gate.** `manage-site-settings` is only defined in tests until issue 04 (SSO and roles). Do the same here: define it in the test, and check 403 and 401 as the other admin endpoints do (`backend/tests/Feature/SiteSettingsApiTest.php`).
  8. **Read-only and live.** No migration and no new table. Order the results: sites, then equipment, then work orders, each by ID.

- 2026-11-23 (qa): QA PASS. `php artisan test --compact tests/Feature/NeedsSetupApiTest.php`: 26 passed (77 assertions). Full `php artisan test --compact`: 973 passed (4474 assertions). No existing test file was modified.

## Implementation decisions

1. **Endpoint and shape.** `GET /api/needs-setup` (route `needs-setup.index`, behind `auth`), `Gate::authorize('manage-site-settings')` in `NeedsSetupController`, so 403 on deny and 401 unauthenticated. The Gate is still defined only in tests until issue 04. Response `{"data": [...]}`. Entry shapes:
   - site: `{type: "site", id, name, reasons}`. `name` comes from the directory.
   - equipment: `{type: "equipment", id, name, site: {id, name}, reasons: ["no_start_date"]}`.
   - work_order: `{type: "work_order", id, status, e_call: {id, nature_of_issue}, site: {id, name} (E-Call Site Snapshot), requestor: {employee_id, name}, reasons}`. `requestor.name` is the requesting PSS user's name. `employee_id` is null when the Requestor has none.
2. **Order.** Sites, then Equipment, then Work Orders. Sites and Equipment sort by string ID, Work Orders by numeric ID.
3. **Site and Equipment scope.** Only Sites that are in the directory and have a PM Frequency are checked. A settings row whose Site has left the directory is skipped, the same way the PM job skips it. Reasons are listed in this order: `no_site_vendor`, then `no_eic`.
4. **One shared start-date rule.** The new `App\Scheduling\PmStartDates` holds the open-task check and `dueFromDate()` (latest Completed Work Done Date, else `installedOn`), plus `needsStartDate()`, which is true when there is no open task and no start date. `GeneratePmTasks` now calls it instead of its own private `dueFromDate()`. The job's behaviour is unchanged: it still checks lazily, one Equipment at a time. The list calls `preload()`, which uses two queries per Site instead of two per Equipment.
5. **Work Order checks are shared.** `WorkOrder::chainSetupProblems(ImmediateHeadChain, BillingThresholds)` returns the reason codes. It uses the same `resolveEndorser()` and the new `endorsementChainConflict()` that the actions use. `assertNoChainConflict()` now throws whatever `endorsementChainConflict()` reports, and `billingEndorserAtLevel()` now resolves through `resolveEndorser()`. `requiredBillingLevels()` takes optional thresholds. Error messages are unchanged, and all existing endorsement and billing tests pass unchanged.
6. **Endorsement reasons.** For `Open` and `For LVL1`, levels 1 and 2 are checked. For `For LVL2`, only level 2 is checked. Each missing level is listed (`missing_endorser_level_<n>`), followed by `endorsement_chain_conflict` if separation of duties refuses the chain. The conflict check always covers levels 1 and 2, as `endorse` does. A loop in the chain (for example level 2 resolving back to the Requestor) shows as **both** the missing level and `endorsement_chain_conflict`, because the issue says "a cycle shows as a missing level" and `endorse` refuses it as a conflict.
7. **Billing reasons.** At most one reason: the lowest unresolved level from the current level (1 for `Implemented`) up to `max(current level, levels required now)`. The current level is always included because `endorse` resolves it even when lower thresholds now need fewer levels. An `Implemented` Work Order not yet validated has no Billing Amount. Like the existing `requiredBillingLevels()`, that counts as 0, so it needs levels 1 and 2, which every amount needs. Billing conflicts are not listed (pending a human decision).
8. **`no_requestor_employee_id`** is the only reason given when the E-Call's Requestor has no employee ID, because no other level can be checked.
9. **Performance.** Live and read-only, with no migration or table. Per request: one `ImmediateHeadChain` (each employee is looked up once), one `BillingThresholdSetting::current()`, Work Orders read with `lazyById(200)` and E-Call and Requestor eager loaded, one directory `listSites()`, and one `listEquipmentAtSite()` per Site with a PM Frequency. The response is not paginated. If the real directory has very many Sites or Equipment, pagination or caching may be needed later.
10. **New folder.** `app/NeedsSetup/NeedsSetupList.php` is a new domain folder, following the `app/Waiting` precedent.
