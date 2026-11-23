# 24 — "Waiting for me" list

Status: ready-for-agent
Blocked by: 11
Type: task

## What to build

The MVP has no email notifications. Each user instead has an in-app list of the items waiting for their action (PRD §3.7 "Approvals", MVP decision in spec).

`GET /api/me/waiting` returns items for the acting user, each with type, ID, title, Site Snapshot name, current status and "waiting since". This slice covers the item types that exist once issue 11 is merged:
- PM Tasks in `For Validation` where I am the EIC.
- (Vendor staff) PM Tasks in `Scheduled`, `In Progress` or `Returned` assigned to my Vendor.

Build it as an extensible list of "waiting item providers", so that later issues (12–23) each add their own provider with a test (E-Calls for vendor assessment and EIC review, Work Orders for endorsement / implementation / validation / billing).

## Acceptance criteria

- [ ] An EIC sees only PM Tasks for validation where they are the EIC.
- [ ] Vendor staff see only their own Vendor's open PM Tasks.
- [ ] A Completed task disappears from the list.
- [ ] Items are sorted oldest-waiting first.
- [ ] Adding a new provider requires no change to the endpoint, shown by a test with a dummy provider.

## Comments
- 2026-11-22 (product-owner): Human decision (issue 11 Decisions, spec.md): when a Site's EIC is replaced, the **current Site EIC** (`site_settings.eic_employee_id` at the time of the request) acts on pending items. The EIC captured on the record is history only and isn't used for authorization. "Where I am the EIC" means where I am the **current** Site EIC. A replaced EIC's pending items move to the new EIC's list.
- 2026-11-23 (product-owner): Clarifications for dev-lead, re-checked against main 07e647e.
  - **Scope stays PM Tasks only** (the two providers above), plus the provider mechanism. Issues 12–23 shipped without adding providers, so the E-Call and Work Order items are now separate issues **39** (E-Calls) and **40** (Work Orders), both blocked by 24. Don't build them here.
  - **"Where I am the EIC"** = `SiteSetting::isActingEic($task->site_id, $user)` (the current Site EIC, never vendor staff). Test that a replaced EIC's For Validation task moves to the new EIC's list. "My Vendor" = `$user->vendor_id`. A user with neither role gets an empty list (200, not 403).
  - **Providers:** an interface (for example `App\Waiting\WaitingItemProvider` with `itemsFor(User): iterable<WaitingItem>`) and a `WaitingItem` value object. Providers are registered in a container tag or an array in a service provider, and the controller only merges and sorts. The dummy-provider test registers an extra provider in the test and sees its item, with no endpoint change.
  - **Item shape:** `type` (`pm_task`), `id`, `title` (the Equipment Snapshot name), `site` (`{id, name}` from the Site Snapshot), `status` (the PmStatus value), `timeliness` (from issue 09's rule), `waiting_since` (ISO 8601 date-time).
  - **`waiting_since`** = when the item entered its current status: For Validation = `submitted_at` of the latest PM Report; Returned = `created_at` of the latest Approval Record; In Progress = `started_at`; Scheduled = the task's `created_at`. Ties are broken by type, then id.
  - `GET /api/me/waiting` goes inside the existing `auth` route group (401 when unauthenticated). Avoid N+1 queries: load the latest report and approval in bulk.
  - No frontend change is required in this issue.

## Implementation decisions

1. **Endpoint:** `GET /api/me/waiting` (route name `me.waiting.index`) inside the `auth` group. Response `{"data": [ {type, id, title, site: {id, name}, status, timeliness, waiting_since} ]}`. A user with no EIC Site and no Vendor gets `{"data": []}` with 200.
2. **No pagination or limit.** The whole list is returned in one response. If lists grow large (many open tasks per Vendor), add paging later; the provider contract doesn't block it.
3. **`waiting_since` format:** Laravel's standard date serialization, the same as `started_at`/`created_at` elsewhere in the API (ISO 8601 in UTC, e.g. `2026-11-21T01:00:00.000000Z`). Stored times are Asia/Manila; the instant is the same.
4. **`waiting_since` fallback:** when the expected source is missing (a For Validation task with no PM Report, a Returned task with no Approval Record, an In Progress task with no `started_at`), the task's `updated_at` is used. Not reachable through the API; it only guards hand-made or legacy rows.
5. **Sort:** oldest `waiting_since` first, then `type`, then `id` (all ascending). Done in PHP after merging providers.
6. **Provider mechanism:** `App\Waiting\WaitingItemProvider` (`itemsFor(User): iterable<WaitingItem>`) and the `App\Waiting\WaitingItem` value object. Providers are tagged `WaitingItemProvider::TAG` in `AppServiceProvider::registerWaitingItemProviders()`; `App\Waiting\WaitingList` merges and sorts, and the controller only calls it. Issues 39/40 add a provider class plus one line in that list.
7. **"Current Site EIC" in bulk:** calling `SiteSetting::isActingEic` per task would be N+1, so I added `SiteSetting::siteIdsWhereActingEic(User)`, a subquery with the same rule (internal employee only, current `site_settings.eic_employee_id`). Its docblock says to keep the two in step. Tests cover the replaced EIC, an EIC on the task record that is no longer current, and vendor staff who have a matching employee ID.
8. **Vendor status not checked:** vendor staff of an inactive Vendor still see their Vendor's open tasks. The issue doesn't say to hide them, so no rule was invented.
9. **Queries:** each provider runs one query (the latest report's `submitted_at` and latest Approval Record's `created_at` are correlated subqueries). A test asserts the query count doesn't grow with the number of items.
10. **`timeliness`** comes from `PmTimelinessRule` with today in the app timezone, the same as `PmTaskResource`. So For Validation is `overdue` or `null` according to the Work Done Date.
11. No frontend change.
