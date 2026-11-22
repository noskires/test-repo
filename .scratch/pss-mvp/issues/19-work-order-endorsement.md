# 19 — Work Order endorsement (2 levels) before implementation

Status: ready-for-agent
Blocked by: 18
Type: task

## What to build

PRD §8.2 and lifecycle §5: the creator submits an `Open` Work Order.
- Submit → `For LVL1 Endorsement`, routed to the Level 1 approver (Supervisor / Team Head = Immediate Head, 1 level up).
- L1 Approve → `For LVL2 Endorsement`, routed to the Division Head (2 levels up).
- L2 Approve → `For Implementation` (the vendor is authorised. The in-app "waiting for me" list replaces email in the MVP).
- **Return** goes back exactly one step (glossary): from L1 back to the creator (`Open`, editable again), from L2 back to L1.
- **Reject**: final, `Rejected`.
- Approve, Return and Reject write an Approval Record. Endorsers come from the **E-Call Requestor's** Immediate Head chain (`ImmediateHeadChain`, issue 02) and are **resolved current at each step**. A short or missing chain **blocks and flags** the Work Order (see Decisions).

## Acceptance criteria

- [x] **Submit** (`POST /api/work-orders/{wo}/submit`): only the Work Order's creator (the E-Call's Requestor), only from `Open`, moves it to `For LVL1 Endorsement`. Others get 403. Any other status returns 422.
- [x] **Chain check at submit**: using `App\MasterData\ImmediateHeadChain`, both level 1 (`headAtLevel(requestorEmployeeId, 1)`) and level 2 (`headAtLevel(requestorEmployeeId, 2)`) must resolve from the **E-Call Requestor's** `employee_id` (`e_calls.requestor_employee_id`). If the Requestor has no `employee_id`, or either level doesn't resolve, the submit returns **422 with a clear message** saying which level is missing. The status stays `Open`, and no Approval Record is written.
- [x] **Current at each step**: the endorser for the current level is resolved **at the time of the action** (inside the lock), not stored at submission. A test changes the Requestor's Immediate Head in the fake directory while the Work Order is `For LVL1 Endorsement`. The **new** head can approve, and the old head gets 403. The same applies at level 2.
- [x] Only the resolved endorser for the current level can approve, return or reject: a user whose `employee_id` equals the resolved head's ID and who isn't vendor staff (`vendor_id === null`). Others get 403, including the creator, the EIC and the other level's endorser.
- [x] **L1 Approve** → `For LVL2 Endorsement`. **L2 Approve** → `For Implementation`.
- [x] **Return** (remarks required) goes back exactly one step: from L1 to `Open` (editable again by the creator, who can resubmit), and from L2 to `For LVL1 Endorsement`.
- [x] **Reject** (remarks required) from either level → `Rejected`, final. Every later submit, approve, return, reject or PATCH returns 422.
- [x] **Chain broken mid-flight**: if the current level's endorser no longer resolves (e.g. the head was removed upstream), every approve, return or reject returns 422 with a clear message, and the status is unchanged. The Work Order shows up on the Needs Setup list (issue 32). Once the chain is fixed in the directory, the then-current endorser can act.
- [x] Approve, return and reject each write an **Approval Record** through `ApprovalRecorder`, with previous and resulting status. Submit writes none, because it isn't an Approve, Return, Reject or cancel (GLOSSARY "Approval Record"). `GET /api/work-orders/{wo}/approvals` lists them oldest first, with the same visibility as `GET /api/work-orders/{wo}`.
- [x] Issue 18's "PATCH only while `Open`" tests still pass with the new statuses. PATCH works again after an L1 Return puts the Work Order back to `Open`.
- [x] Each action re-checks the status and the actor's right to act inside a transaction that locks the Work Order row. Tests use the directory fake. No new packages, and `php artisan test` passes.

## Comments
- 2026-11-23 (qa): QA PASS. `php artisan test` from backend/ gives 687 passed (2714 assertions), 0 failed. `tests/Feature/WorkOrderEndorsementApiTest.php` and `tests/Feature/WorkOrderApiTest.php` give 99 passed (510 assertions). Every acceptance criterion maps to a test that asserts it. Endorsers are resolved from the Requestor's Immediate Head chain at each action, inside the Work Order lock. Submit requires both levels to resolve and writes no Approval Record. Return goes back one step, and Reject is final. A chain broken mid-flight blocks every action with 422. The only change to WorkOrderApiTest replaces the test that pinned the enum to ['open'], and issue 18's PATCH tests are unchanged and pass.

## Decisions (grilling, 2026-11-22)
- Q1 answered: the **Work Order creator's chain**, and the creator is always the E-Call's Requestor (issue 18). Billing endorsement uses the same chain.
- Q2 answered (earlier grilling): if a required level is missing from the chain, the Work Order is **blocked** at that step until the chain is fixed. The Immediate Head now lives in the external system, so the fix happens there. Q3 is still open.
- ~~Q3 answered: approvers are fixed when the Work Order is submitted (the chain is captured then). Later org-chart changes don't re-route a Work Order that's in flight.~~ **Superseded 2026-11-22 by the human**: endorsers are resolved current at each step (see below).
- 2026-11-22 (product-owner): FYI. The human decided that the **current Site EIC** acts on pending items, and the EIC captured on a record is history only (issue 11 Decisions). Endorsers here come from the Immediate Head chain, not the EIC, so nothing changes unless the chain question (Q1) ends up starting from the EIC.

## Decisions (human, 2026-11-23)
- **Chain**: the **E-Call Requestor's** Immediate Head chain. Level 1 is the Requestor's Immediate Head. Level 2 is that person's Immediate Head. (This matches Q1 above, since the creator is always the Requestor.)
- **Short or missing chain: block and flag.** Submitting for endorsement fails with a clear 422, and the Work Order appears on the admin Needs Setup list (issue 32) until the chain is fixed in the external system. The same applies if the chain breaks while the Work Order is waiting at a level.
- **Endorsers are resolved current at each step**, not fixed at submission. This supersedes the earlier Q3 answer and matches the current-Site-EIC rule (issue 11). It overrides ADR 0001's "Approvers are captured when a Work Order is submitted". ADR 0001 has a dated amendment for it.

- 2026-11-23 (product-owner): Rewrote the criteria from the decisions above, and replaced the old open questions and draft criteria. **No open questions remain. Status: ready-for-agent.** Hand-over notes from issue 18 (merged in PR #23):
  - **Approval Records**: make `WorkOrder` an `ApprovalSubject` (the `HasApprovalRecords` trait) and add a `work_order` alias to the enforced morph map in `AppServiceProvider`. Reuse `ApprovalRecorder::transition(...)` and `ApprovalRecordResource`. Don't build a second mechanism.
  - **Statuses**: extend `WorkOrderStatus` with `for_lvl1_endorsement`, `for_lvl2_endorsement`, `for_implementation` and `rejected`. Issue 18's PATCH guard compares the stored value against `Open`, so its "PATCH only while `Open`" tests must keep passing unchanged.
  - **EIC**: no EIC acts in this slice. If any EIC-based check comes up, it must use `SiteSetting::isActingEic` (issue 37). The endorser check mirrors its "not vendor staff" rule.
  - **Missing Requestor `employee_id`** (`e_calls.requestor_employee_id` is nullable) counts as a missing chain: 422 at submit, and listed on Needs Setup.
  - The Needs Setup **list entry** itself is built in issue 32, which is now also blocked by 19. This issue only needs the 422s and the status guard.
