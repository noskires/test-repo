# 38 — Shared vendor-staff rule and endorsement hardening

Status: ready-for-agent
Blocked by: none
Type: chore

## What to build

qa flagged in the issue 19 review that the rule "only an internal employee (not vendor staff) can act as this approver" now lives in two places, which could drift apart:
- `SiteSetting::isActingEic`: `vendor_id !== null || employee_id === null` → false, then compares `employee_id` with the Site's current EIC (issue 37).
- `WorkOrder::isEndorser`: `vendor_id === null && employee_id !== null && employee_id === $head->id` (issue 19). Its comment even says it "mirrors SiteSetting::isActingEic".

Extract **one shared helper** for "this user is an internal employee with this employee ID". For example, on `User`, `isInternalEmployee(): bool` (no `vendor_id`, has an `employee_id`) plus `actsAsEmployee(string $employeeId): bool`, or a small dedicated class. The name and place are the developer's choice. Then:
- `SiteSetting::isActingEic` uses it for the user part, and keeps only the "who is the Site's current EIC" lookup (with its lock option).
- `WorkOrder::isEndorser` uses it (or is replaced by it), with no `vendor_id` / `employee_id` comparison of its own.
- Document it as the rule every approver check uses: EIC, Work Order endorsement, and billing endorsement (issue 23).

**Behaviour is unchanged** for the helper refactor. In addition, the human decided (2026-11-23) to fold in two security fixes from a review of the merged issue 19 code:

**A. Separation of duties in Work Order endorsement.**
- Submit and every endorse action (approve, return, reject) refuse a chain where the level-1 and level-2 heads have the **same employee ID**, or where **either head is the Requestor** (the same `employee_id` as `e_calls.requestor_employee_id`). The refusal is 422 with a clear message, no status change and no Approval Record, and the Work Order is flagged on the Needs Setup list (issue 32, reason `endorsement_chain_conflict`).
- **Approve** also refuses an actor who **already approved a different level in the current submission cycle**, meaning since the Work Order's most recent submit to `For LVL1 Endorsement`. The actor is matched by `actor_user_id` **or** `actor_employee_id`, and the level comes from the record's `previous_status`. One person can't approve two different levels, even if the chain changes in between. **Re-approving the same level** (e.g. L1 approves again after an L2 → L1 return) is allowed. **Return and Reject are never blocked** by this rule. (Refined 2026-11-23 by the team lead to avoid a deadlock.)
- `ImmediateHeadChain` detects **cycles** (an employee ID repeating while walking up) and treats them as a broken chain (`headAtLevel` returns null). That gives the existing missing-level 422 and the Needs Setup flag.

**B. No status oracle on endorsement endpoints.**
- `submit`, `approve`, `return`, `reject` and `GET .../approvals` first authorize **`view`** on the Work Order (the same rule as `GET /api/work-orders/{wo}`). A user who can't view it gets a **plain 403** whatever the status or chain state, so the response doesn't reveal whether the Work Order exists in a given status or has a broken chain. The detailed 422 messages (wrong status, missing level, chain conflict) only go to users who can view it.

Out of scope: the vendor **visibility** checks in `ECallPolicy`, `PmTaskPolicy` and `ECall` scopes (`vendor_id === null || vendor_id === record's vendor`). That's a different rule (who can see a record, not who can approve it), and issue 04 alignment item 2 covers it.

## Acceptance criteria

- [x] One shared helper holds the "not vendor staff and has this employee ID" rule. `SiteSetting::isActingEic` and the Work Order endorser check both call it, and neither compares `vendor_id` or `employee_id` itself. A grep in `backend/app` finds the comparison only in the helper.
- [x] Unit test for the helper: an internal employee with a matching ID passes; a vendor user with a matching `employee_id` fails; a user with a null `employee_id` fails; a non-matching ID fails.
- [x] Behaviour is unchanged. The existing tests for issues 11, 17, 19 and 37 (EIC approve/return, E-Call review, Work Order endorsement, vendor staff never EIC) pass without changing their expectations.
- [x] **SoD, chain conflicts**: with the fake directory, (a) level-1 head = level-2 head, (b) level-1 head = the Requestor, and (c) level-2 head = the Requestor. Each case gives 422 on submit, with the status staying `Open` and no record, and 422 on approve/return/reject while the Work Order waits, with the status unchanged and no record. A test for each.
- [x] **SoD, two different levels**: a user who approved at level 1 and then becomes the level-2 head (the chain changed mid-cycle) gets 422 on level-2 **approve**, matched by user ID, and by employee ID too (a different user row with the same `employee_id`). The same user can still **return** or **reject** at level 2. After an L2 → L1 return, the L1 endorser can **approve L1 again** (same level, allowed). After an L1 Return and a resubmit, a new cycle starts and approvals from the earlier cycle no longer block.
- [x] **Cycle detection**: a chain A → B → A (and a self-loop A → A) makes `ImmediateHeadChain::headAtLevel` return null from the repeating level onward (unit test on `ImmediateHeadChain`). When the loop falls within levels 1–2, submit and endorse return the specific separation-of-duties 422 ("would be the Requestor" / "same person"). Otherwise they return the missing-level 422. Either way it's a 422 with no status change and no Approval Record.
- [x] **No status oracle**: a user who can't `view` the Work Order gets 403 (not 422) on submit, approve, return, reject and approvals. Test it on a Work Order with a broken chain and on one that isn't pending endorsement. Users who can view it still get the 422 details.
- [x] Full `php artisan test` passes. No new packages.

## Comments

- 2026-11-23 (product-owner): Created at the team lead's request after qa's issue 19 review. Issue 23 (billing endorsement) should use this helper once it exists.
- 2026-11-23 (product-owner): Expanded by the human's decision: folded in separation of duties (A) and no status oracle (B) from a security review of the merged issue 19 code, and retitled. Build order: **38 first**, then 20 → 21 → 23. Billing endorsement (23) and the EIC's entry into billing (21) must apply the same separation-of-duties checks (noted on those issues).
- 2026-11-23 (qa): QA PASS. `php artisan test` from backend/ gives 714 passed (2904 assertions), 0 failed. The four focused files (UserActsAsEmployeeTest, ImmediateHeadChainTest, WorkOrderEndorsementHardeningTest, WorkOrderEndorsementApiTest) give 84 passed (498 assertions). Every acceptance criterion maps to a test that asserts it. `User::actsAsEmployee` / `isInternalEmployee` hold the only approver-rights comparison in app/. The tests for issues 11, 17, 19 and 37 have no diff and pass. Double approval follows the refined rule. A loop within levels 1-2 returns the specific separation-of-duties 422 (criterion reworded to match). Work Orders pending before the migration get a round floor of 0, which is harmless before go-live.
