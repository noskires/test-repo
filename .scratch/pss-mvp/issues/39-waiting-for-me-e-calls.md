# 39 — "Waiting for me": E-Call items

Status: ready-for-agent
Blocked by: 24
Type: task

## What to build

Add an E-Call provider to the "Waiting for me" list (issue 24). It plugs into 24's provider mechanism, with no endpoint change. Who acts on an E-Call in each status, as shipped in issues 12, 16, 17 and 18:

- **For Vendor Assessment** and **Returned**: the staff of the E-Call's Vendor (`e_calls.vendor_id`), who submit the FSR and BOQ (`ECallStatus::acceptsProposal`).
- **For EIC Review**: the Site's **current** EIC (`SiteSetting::isActingEic`), never vendor staff.
- **Approved**: the E-Call's Requestor (`requestor_user_id`), who creates the Work Order (issue 18).
- Closed, Rejected and Cancelled: nobody.

## Acceptance criteria

- [x] Each of the four statuses above shows the E-Call to exactly the right user(s) and to nobody else (one test per status, including another Vendor's staff and a non-EIC employee).
- [x] A replaced EIC no longer sees a For EIC Review E-Call, and the new EIC does.
- [x] Closed, Rejected and Cancelled E-Calls appear for nobody.
- [x] Item shape: `type` = `e_call`, `id`, `title` (nature of issue), `site` (from the Snapshot), `status`, `priority`, and `waiting_since` = the latest of the E-Call's `created_at`, its latest proposal's `submitted_at` and its latest Approval Record's `created_at`. Items sort together with PM Task items, oldest first.
- [x] No change to the `GET /api/me/waiting` controller. `php artisan test` passes.

## Comments

- 2026-11-23 (qa): PASS. `php artisan test` 924 passed (4089 assertions); WaitingListECallApiTest + WaitingListApiTest targeted run passed. Every criterion maps to a real test.
- 2026-11-23 (product-owner): Split out of issue 24, because issues 12–23 shipped without adding providers. Each rule comes from shipped behaviour; there are no open questions.
- 2026-11-23 (product-owner): Clarifications for dev-lead, re-checked against main b4dd3d2 (issue 24 shipped).
  - Plug in the way 24 did: a new class in `app/Waiting/` that implements `WaitingItemProvider`, added to the tag list in `AppServiceProvider::registerWaitingItemProviders()`. You can split it into one provider per role, as 24 did with `PmTasksForValidationProvider` / `VendorPmTasksProvider`. Look at those two for the bulk-loading pattern.
  - `WaitingItem` has no `priority` yet. Add a nullable `priority` to the value object and to `toArray()` (null for PM Tasks). `timeliness` is null for E-Calls. Update 24's tests only where the JSON shape now has the extra key.
  - "No controller change" means the `GET /api/me/waiting` controller and `WaitingList` stay as they are. Adding a key to `WaitingItem` is expected.
  - For EIC Review: check the current Site EIC per Site with `SiteSetting` in bulk (for example, the Site IDs where the user is the acting EIC), not one query per E-Call. Vendor staff never qualify as EIC (issue 37).
  - Approved: the Requestor is `e_calls.requestor_user_id` = the user's id (this is the same check as `ECallPolicy`, line 47).
  - No frontend change is required.


## Implementation decisions

1. Three providers, one per role, each a single query: `VendorECallsProvider` (For Vendor Assessment + Returned, from `ECallStatus::acceptsProposal`, matched on `e_calls.vendor_id`), `ECallsForEicReviewProvider` (For EIC Review, `site_id` in `SiteSetting::siteIdsWhereActingEic`), `ApprovedECallsProvider` (Approved, `requestor_user_id` = user id). Shared item building lives in `app/Waiting/ECallWaitingItems.php`. All three are tagged in `AppServiceProvider::registerWaitingItemProviders()`. The controller and `WaitingList` are unchanged.
2. The Vendor that counts is the one copied onto the E-Call at filing (`e_calls.vendor_id`), not the Site's current Vendor. If the Site Vendor changes later, the old Vendor's staff keep the open E-Call and the new Vendor's staff do not see it. This matches the issue text and the shipped proposal check. Tested.
3. The EIC copied onto the E-Call is history only. For EIC Review goes to the Site's current EIC, so a replaced EIC loses the item. Vendor staff never qualify, even when their employee ID matches.
4. `WaitingItem` gains `?string $priority = null` as the last constructor argument, so existing callers still work. `toArray()` emits `priority` after `status`, and it is `null` for PM Tasks. E-Call `timeliness` is `null`. In issue 24's test, only the exact-JSON shape test changed (it now has `'priority' => null`).
5. `waiting_since` is the latest of `created_at`, the highest-revision proposal's `submitted_at`, and the highest-id Approval Record's `created_at`, loaded as two correlated subqueries. If all three are somehow missing, it falls back to `updated_at`, which can't happen through the API.
6. A user with more than one role (for example, a Requestor who is also the EIC) can't get duplicate items, because each status belongs to exactly one provider.
7. No frontend change. The new `priority` key is additive JSON.
8. Performance: each provider runs one query no matter how many items there are. A query-count test covers this for vendor staff, the EIC and the Requestor.
