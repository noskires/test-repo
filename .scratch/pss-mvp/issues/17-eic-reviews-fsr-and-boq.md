# 17 — EIC reviews the FSR and BOQ

Status: ready-for-agent
Blocked by: 11, 16
Type: task

## What to build

PRD §7.4: the E-Call's EIC evaluates the latest FSR/BOQ:
- **Approve**: E-Call Status → `Approved`. Work Order creation is now possible (issue 18).
- **Return**: → `Returned`, back to the Site Vendor for revision. Remarks are required.
- **Reject**: → `Rejected`. This is final (glossary: to try again, file a new E-Call). Remarks are required.

Each action writes an Approval Record (issue 11's mechanism).

Endpoints: `POST /api/e-calls/{eCall}/approve`, `/return`, `/reject`.

## Acceptance criteria

- [ ] Only the **current** Site EIC of the E-Call's Site can act (`site_settings.eic_employee_id` matched to `users.employee_id`). Others get 403, including vendor staff and the Requestor.
- [ ] After the Site's EIC is replaced while the E-Call is `For EIC Review`, the new EIC can act and the old EIC (captured at filing) gets 403. The E-Call's captured `eic_employee_id` is left unchanged.
- [ ] Actions only work from `For EIC Review`. Otherwise 422.
- [ ] Approve sets `Approved`. Return sets `Returned`. Reject sets `Rejected`. Each writes an Approval Record with previous and resulting status.
- [ ] Return and Reject without remarks return 422.
- [ ] After a Reject, every further action on that E-Call that exists so far (proposal, approve, return, reject) returns 422. Work Order creation (issue 18) checks `Approved` itself.
- [ ] Approve records which proposal revision was approved (the latest one), readable on the E-Call. Earlier revisions stay readable and aren't marked approved.
- [ ] `GET /api/e-calls/{eCall}/approvals` lists the E-Call's Approval Records, oldest first, to anyone who can view the E-Call (`ECallPolicy::view`). Others get 403.

## Comments
- 2026-11-22 (product-owner): Human decision (issue 11 Decisions, spec.md): when a Site's EIC is replaced, the **current Site EIC** (`site_settings.eic_employee_id` at the time of the request) acts on pending items. The EIC captured on the record is history only and isn't used for authorization. The reviewer is the current Site EIC of the E-Call's Site, not the `eic_employee_id` captured at filing (issue 12). Add a test where the EIC is replaced while the E-Call is `For EIC Review`: the new EIC can act, and the old one gets 403.
- 2026-11-22 (product-owner): Re-checked (main 6b99081). Blockers 11 and 16 are merged. Still ready-for-agent. The team lead will hand it off **after issue 36 merges**. Clarifications, in line with how issue 11 was built:
  - **Current Site EIC, re-checked inside the lock**: as in `PmTask::validateReport`, run each action in a DB transaction that locks the E-Call row, then re-check (a) `SiteSetting::isCurrentEic($eCall->site_id, $user, lockForUpdate: true)` and (b) that the status is still `For EIC Review`, before transitioning. An EIC replaced, or a status changed, between the request check and the lock must still give 403 / 422. A Policy or FormRequest check before the transaction is fine for the early answer, but the in-lock re-check is required.
  - **Reuse `App\Approvals\ApprovalRecorder`**: make `ECall` an `ApprovalSubject` (with the `HasApprovalRecords` trait), add an `e_call` alias to the enforced morph map in `AppServiceProvider`, and call `transition(...)` with `ApprovalAction::Approve` / `Return` / `Reject`. Don't build a second recording mechanism. Reuse `ApprovalRecordResource` for the listing.
  - **Approved revision**: store the approved proposal's ID on the E-Call (e.g. a nullable `approved_proposal_id`), set in the same transaction. Issue 18 builds the Work Order from it.
  - **Return**: the vendor resubmits through issue 16's existing endpoint (`Returned` already accepts a proposal). **Reject** is final. `ECallStatus::acceptsProposal()` already refuses `Rejected`.
  - Approve remarks are optional. Return and Reject remarks are required.
  - No open questions for the human in this issue.
