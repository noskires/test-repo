# 40 — "Waiting for me": Work Order items

Status: ready-for-agent
Blocked by: 24
Type: task

## What to build

Add a Work Order provider to the "Waiting for me" list (issue 24). It plugs into 24's provider mechanism, with no endpoint change. Who acts in each status, as shipped in issues 18–23:

- **Open**: the Requestor who created the Work Order (they edit it and submit it for endorsement).
- **For LVL1 / LVL2 Endorsement** and **For Billing Endorsement LVL1–4**: the employee who holds that level **right now** in the Requestor's Immediate Head chain. Resolve it with the same code `WorkOrder::endorse` / billing endorsement use (`endorserAtLevel`), not a copy. A Work Order whose level doesn't resolve appears for nobody here. It appears on the Needs Setup list instead (issue 32).
- **For Implementation**: the staff of the Work Order's Vendor.
- **Implemented**: the Site's **current** EIC, who validates the completed work and sets the Billing Amount (issue 21).
- Closed and Rejected: nobody.

## Acceptance criteria

- [x] Each status above shows the Work Order to exactly the right user and to nobody else (one test per status group).
- [x] When the fake directory changes who holds a level's Immediate Head position, the item moves to the new holder.
- [x] A Work Order with a broken chain appears for nobody, and the endpoint doesn't error.
- [x] Closed and Rejected Work Orders appear for nobody.
- [x] Item shape: `type` = `work_order`, `id`, `title`, `site` (from the Snapshot), `status`, and `waiting_since` = the latest of the Work Order's `created_at` and its latest Approval Record's `created_at`. Items sort together with the other types, oldest first.
- [x] No change to the `GET /api/me/waiting` controller. Resolve the chain once per Requestor per request, not once per Work Order per level. `php artisan test` passes.

## Comments

- 2026-11-23 (qa): PASS. `php artisan test --compact` 947 passed (4394 assertions); WaitingListWorkOrderApiTest 23 passed. Every criterion maps to a real test.
- 2026-11-23 (product-owner): Split out of issue 24, because issues 18–23 shipped without adding providers. Each rule comes from shipped behaviour (the endorsers are the current holders, issue 19 decisions; a broken chain is blocked and flagged, issue 19/23 decisions); there are no open questions.
- 2026-11-23 (product-owner): Clarifications for dev-lead, re-checked against main e400211 (issues 24 and 39 shipped).
  - Plug in the way 24 and 39 did: provider classes in `app/Waiting/` (see `ECallWaitingItems` + `ECallsForEicReviewProvider` / `VendorECallsProvider` / `ApprovedECallsProvider`), registered in `AppServiceProvider::registerWaitingItemProviders()`. `timeliness` and `priority` are null for Work Orders. You may set `priority` from the E-Call if that's simple, but it isn't required.
  - The `work_orders` table has no Site, Vendor, Requestor or title of its own. Take them from its **E-Call** (`e_call_id`): Site Snapshot, `vendor_id`, `requestor_user_id`/`requestor_employee_id`, and title = the E-Call's nature of issue. **Open** goes to `work_orders.created_by_user_id` (the creator, who is the Requestor; see `WorkOrder` line ~468).
  - **Implemented**: the current EIC, using the same rule as `WorkOrder::isActingEic` (the Site comes through the E-Call). Do the check in bulk per Site, as 39 did.
  - **Endorsers:** `endorserAtLevel` (~line 693) and `billingEndorserAtLevel` (~line 278) are private. Expose **one public method** on `WorkOrder` (for example `currentEndorser(): ?Employee`, which returns null when the level doesn't resolve or the chain conflicts) and build it on those helpers. Don't copy the chain logic into the provider. Then compare its employee id with `$user->employee_id`. Respect issue 23's rule that one person can't sign two billing levels in the same round (`billingLevelApprovedThisRoundByOtherThan`): if `WorkOrder::endorse` would refuse this user, the item must not show for them. Add a test for it.
  - Performance: the "resolve once per Requestor" criterion means caching `ImmediateHeadChain` lookups per request (for example, memoize per requestor employee id inside the provider). Don't add a new package or a cache store.
  - No frontend change is required.


## Implementation decisions

1. **Four providers** in `backend/app/Waiting/`, registered in `AppServiceProvider::registerWaitingItemProviders()`; no controller change: `OpenWorkOrdersProvider` (Open, `created_by_user_id` = user), `WorkOrderEndorsementsProvider` (LVL1/LVL2 and Billing LVL1–4), `VendorWorkOrdersProvider` (For Implementation, the E-Call's `vendor_id` = the user's Vendor), `ImplementedWorkOrdersProvider` (Implemented, the E-Call's Site is in `SiteSetting::siteIdsWhereActingEic($user)`, the bulk form of `WorkOrder::isActingEic`). Shared item building is in `WorkOrderWaitingItems`.
2. **Item shape**: `type` = `work_order`. `title` is the E-Call's nature of issue, and `site` is the E-Call's Site Snapshot (`site_id`, `site_name_snapshot`). `timeliness` and `priority` are both null: I left priority unset rather than copying it from the E-Call. `waiting_since` = the later of the Work Order's `created_at` and its latest Approval Record's `created_at` (latest by id).
3. **One new public method**: `WorkOrder::isWaitingForEndorsementBy(User $user, ?ImmediateHeadChain $chain = null): bool`. It is built on the existing private helpers (`assertBillingChainSound`, `billingEndorserAtLevel`, `assertNoChainConflict`, `resolveEndorser`), which now take an optional chain. Their behaviour is unchanged when no chain is passed. It returns false when no endorsement is pending, the user is not an internal employee, the Requestor has no employee ID, the pending level doesn't resolve, or the chain conflicts (issues 23/38). I made it a bool instead of the suggested `currentEndorser(): ?Employee` because the separation-of-duties check (point 4) needs the user.
4. **Separation of duties**: if the user already approved a different level in the current round, the item is hidden. This applies to billing (`billingLevelApprovedThisRoundByOtherThan`) and also to the pre-implementation endorsement (`levelApprovedThisRoundByOtherThan`), because `WorkOrder::endorse` refuses that Approve in both phases. Note: such a user could still Return or Reject through `endorse`, but the item is hidden from them anyway (the clarification asked for this). Approvals from an earlier billing round don't hide the item.
5. **"Level doesn't resolve" means the level the Work Order is waiting at.** A Work Order waiting at billing LVL1 whose chain stops before LVL2 still shows to the LVL1 holder. That matches shipped behaviour: they can act, and only an Approve onward is blocked with a 422 (issue 23). It shows to nobody once it waits at the level that doesn't resolve.
6. **"Resolve once per Requestor"**: `ImmediateHeadChain` now memoizes `findEmployee` per instance. The endorsement provider shares one instance across the whole list, so each employee is fetched from the directory at most once per request (a test counts the directory calls). Every other caller resolves a fresh instance per action, as before, so org changes are still seen immediately. No new package and no cache store.
7. **Performance**: the endorsement provider loads every Work Order in the six endorsement statuses (with E-Calls eager loaded) and filters in PHP. The directory can't be searched by head, so the "who is the holder" filter can't be pushed into SQL. Per billing Work Order there is one `BillingThresholdSetting::current()` query. For a Work Order whose endorser matches the user, there is one Approval Record query for the separation-of-duties check. This is fine at MVP volumes. If the list grows large, revisit it (for example, memoize the thresholds per request).
8. `WorkOrder::requestorEmployeeId()` reads the eager-loaded E-Call when it is loaded, to avoid N+1. Otherwise it queries as before.
