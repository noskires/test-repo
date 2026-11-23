# 25 — Cancelling E-Calls and Work Orders

Status: ready-for-agent
Blocked by: 12, 18
Type: task

## What to build

The PRD lists `CANCELLED` for Work Orders and other requests ("withdrawn or cancelled", §5) but doesn't say who can cancel or when. The human answered that (Decisions below). This slice adds Cancel for **E-Calls** and **Work Orders**. Cancelling a **PM Task** was split off to issue 41 (needs-info) on 2026-11-24, because one question about the generation job is still open.

- `POST /api/e-calls/{eCall}/cancel` with a required `remarks` (the reason).
- `POST /api/work-orders/{workOrder}/cancel` with a required `remarks`.
- Who: the record's creator (E-Call: its Requestor; Work Order: its creator, who is that E-Call's Requestor) or the **current** Site EIC.
- When: E-Call while `For Vendor Assessment`, `For EIC Review` or `Returned` (until the EIC approves the FSR/BOQ). Work Order while `Open`, `For Lvl1 Endorsement` or `For Lvl2 Endorsement` (before `For Implementation`).
- Each cancel sets the status to `Cancelled` and writes one Approval Record (action `cancel`, remarks, previous and resulting status).

## Acceptance criteria

- [ ] The E-Call's Requestor can cancel it in each of `For Vendor Assessment`, `For EIC Review` and `Returned` (a test each). It becomes `Cancelled`.
- [ ] The current Site EIC can cancel an E-Call. A former Site EIC (replaced in Site settings) gets 403. Vendor staff, including the Site Vendor's staff, get 403, as do other users.
- [ ] Cancelling an E-Call that is `Approved`, `Closed`, `Rejected` or already `Cancelled` returns 422 and changes nothing.
- [ ] The Work Order's creator can cancel it in each of `Open`, `For Lvl1 Endorsement` and `For Lvl2 Endorsement` (a test each). It becomes `Cancelled`.
- [ ] The current Site EIC (of the Work Order's E-Call's Site) can cancel a Work Order. Immediate Heads, vendor staff, a former Site EIC and other users get 403.
- [ ] Cancelling a Work Order in `For Implementation` or any later status (`Implemented`, any `For Billing Endorsement LVLn`, `Closed`, `Rejected`, `Cancelled`) returns 422 and changes nothing.
- [ ] A missing or blank `remarks` returns 422 on both endpoints and changes nothing.
- [ ] Each successful cancel writes exactly one Approval Record: action `cancel`, the actor, the remarks, the previous status and `cancelled`. It shows in `GET /api/e-calls/{eCall}/approvals` / `GET /api/work-orders/{workOrder}/approvals`.
- [ ] Cancelling a Work Order leaves its E-Call `Closed`, and creating a second Work Order for that E-Call still returns 422 (more work needs a new E-Call).
- [ ] A cancelled E-Call no longer accepts an FSR/BOQ revision, Approve, Return or Reject (422). A cancelled Work Order can't be edited, submitted, approved, returned or rejected (422).
- [ ] Cancelled E-Calls and Work Orders don't appear on the "Waiting for me" list.

## Comments

## Decisions (grilling, 2026-11-22)
- **Who**: the record's creator (the Requestor, for E-Calls and Work Orders) or the Site's EIC. A system-generated PM Task has no human creator, so for those it's the EIC.
- **When**: only before the vendor has started the work. After that, the only way to end it is Reject.
- **Reason**: always required.
- Still open: the cut-off point for an E-Call, and whether cancelling a PM Task creates the next one and from what date.
- E-Call cut-off answered: it can be cancelled **until the EIC approves the FSR/BOQ**.
- PM Task answered: cancelling does **not** automatically create the next task. The **EIC picks the next due date** when cancelling (or later). Until then, that Equipment has no scheduled PM.
- Work Order cut-off: cancellable while `Open`, `For Lvl1 Endorsement` or `For Lvl2 Endorsement` (before `For Implementation`).

- 2026-11-22 (product-owner): `Blocked by` corrected from `none` to `08, 12, 18`. Cancelling needs PM Tasks (08), E-Calls (12) and Work Orders (18) to exist. It can also be split per record type later if that helps delivery.
- 2026-11-22 (product-owner): **New open question (for the human, unresolved)**, raised by qa in the issue 08 review. Issue 08's job counts a new task's due date from the latest `Completed` task's Work Done Date, or from `installedOn` if there is none. So if an Equipment's only past PM Task was **Cancelled**, the job counts from `installedOn` again, and the new task could be **due in the past** (immediately Overdue). This conflicts with the decision above: cancelling doesn't create the next task, and the EIC picks the next due date. Which should win: (a) the job generates nothing after a Cancelled task until the EIC sets a date, (b) the job counts from `installedOn` as it does now, even if the date is past, or (c) something else, e.g. counting from the cancellation date?
- 2026-11-22 (product-owner): Human decision (issue 11 Decisions, spec.md): when a Site's EIC is replaced, the **current Site EIC** (`site_settings.eic_employee_id` at the time of the request) acts on pending items. The EIC captured on the record is history only and isn't used for authorization. "The Site's EIC" who may cancel is the current Site EIC.
- 2026-11-24 (product-owner): **Split.** PM Task cancelling (and the open generation question above) moved to issue 41 (`needs-info`). This issue now covers only E-Calls and Work Orders, which the human's decisions fully define. `Blocked by` is now `12, 18` (both done). Title and criteria rewritten for that scope.
- 2026-11-24 (product-owner): **Clarifications for dev-lead** (checked against main at efd9250):
  - **Statuses**: `ECallStatus::Cancelled` already exists. `WorkOrderStatus` has no `Cancelled` case yet: add `Cancelled = 'cancelled'`. Check every `match` on `WorkOrderStatus` still compiles and behaves (e.g. `afterEndorsement`, `billingLevel`, the Needs Setup and "Waiting for me" queries) and that no list or count treats it as open.
  - **Approval Records**: `ApprovalAction::Cancel` already exists and the `e_call` / `work_order` morph aliases are registered. Use `ApprovalRecorder::transition($locked, $actor, ApprovalAction::Cancel, <Cancelled>, $remarks)` like the Reject paths do.
  - **Who**: E-Call creator = `e_calls.requestor_user_id`. Work Order creator = `work_orders.created_by_user_id`. Current Site EIC: `SiteSetting::isActingEic($siteId, $user, lockForUpdate: true)` for E-Calls and `WorkOrder::isActingEic($user, true)` for Work Orders. These already refuse vendor staff (issues 37/38). The creator check is by user ID; a creator who is vendor staff can't exist (vendor staff can't file E-Calls), so no extra rule is needed.
  - **Order of checks**: 403 for someone who is neither the creator nor the current Site EIC, regardless of status. For an allowed user, 422 for a wrong status or missing `remarks`. Re-check the status and the actor **inside a transaction that locks the record row** (same pattern as the existing review/endorsement actions), so a cancel racing an EIC Approve or an LVL2 endorsement can't both succeed.
  - **Remarks**: required, non-blank string. Use the same max length as the existing Reject `remarks` validation.
  - **E-Call from a PM Task** (issue 13): cancelling such an E-Call has no effect on the PM Task. Keep the `pm_task_id` link.
  - **After a Work Order cancel**: the E-Call stays `Closed` (issue 18: creating the Work Order closed it, and more work needs a new E-Call). Don't reopen it. The unique `work_orders.e_call_id` already blocks a second Work Order.
  - **Policies**: add `cancel` abilities to `ECallPolicy` and `WorkOrderPolicy` if that matches how the other actions authorise. Developer's choice of where.
  - **Response**: return the updated E-Call / Work Order resource (200), like the other actions.
  - **Out of scope**: PM Task cancel (issue 41), any frontend screen, notifications.

## Implementation decisions

1. **Endpoints**: `POST /api/e-calls/{eCall}/cancel` (route `e-calls.cancel`) and `POST /api/work-orders/{workOrder}/cancel` (route `work-orders.cancel`). Body `{ "remarks": string }`. 200 returns the updated `ECallResource` / `WorkOrderResource`, like the other actions. 401 unauthenticated, 404 unknown record.
2. **New status**: `WorkOrderStatus::Cancelled = 'cancelled'`. It is final, like `Rejected`. It has no endorsement or billing level, so `endorsementLevel()` / `billingLevel()` return null, `afterEndorsement` is never reached for it, and the Needs Setup and "Waiting for me" queries (which select statuses explicitly) leave it out. No migration was needed because `status` is a plain string column.
3. **Cancellable statuses** are kept on the enums as `isCancellable()`. E-Call: `for_vendor_assessment`, `for_eic_review`, `returned`. Work Order: `open`, `for_lvl1_endorsement`, `for_lvl2_endorsement`. A test checks that every `WorkOrderStatus` case is classified, so a status added later has to be placed deliberately.
4. **Who** is checked in `ECall::mayBeCancelledBy` / `WorkOrder::mayBeCancelledBy`: the creator by user ID (`requestor_user_id` / `created_by_user_id`), or the current Site EIC through `SiteSetting::isActingEic` / `WorkOrder::isActingEic`, which never match vendor staff. The policies' `cancel` abilities call these. The Work Order request also authorises `view` first, like the other Work Order actions.
5. **Order of checks**: 403 for anyone who isn't the creator or current Site EIC, before remarks or status are looked at. For an allowed user: 422 `remarks` when remarks are missing or blank, and 422 `e_call` / `work_order` for a wrong status.
6. **Concurrency**: `ECall::cancel` / `WorkOrder::cancel` lock the record row (and the site settings row, for the EIC path) inside a transaction and re-check the actor and the status before `ApprovalRecorder::transition(..., ApprovalAction::Cancel, Cancelled, $remarks)`. A cancel that races an EIC Approve or an LVL2 approval gets 422. Tests cover this.
7. **Remarks**: `required|string|max:5000`, the same as the Reject remarks. Whitespace-only remarks become empty through the global TrimStrings / ConvertEmptyStringsToNull middleware and are rejected.
8. **Side effects**: cancelling a Work Order leaves its E-Call `Closed`, and the unique `e_call_id` still blocks a second Work Order. Cancelling an E-Call raised from a PM Task keeps `pm_task_id` and doesn't touch the PM Task. There are no notifications and no frontend changes.
9. **Existing tests updated** for the new enum case: the status lists in `WorkOrderEndorsementApiTest` and `WorkOrderBillingEndorsementApiTest`, plus a `cancelled: nobody` row in the `WaitingListWorkOrderApiTest` data provider.
