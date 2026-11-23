# 13 — EIC files an E-Call from a PM Task's findings

Status: ready-for-agent
Blocked by: 11, 12
Type: task

## What to build

PRD §6.10: while reviewing a PM Task, the EIC can file an E-Call straight from it. The E-Call is pre-filled with the PM Task's Site and Equipment and references the originating PM Task. Endpoint: `POST /api/pm-tasks/{task}/e-calls` (body: nature, description, priority, attachments).

**Important rule:** filing the E-Call must **not** change the PM Task's status. The PM Task completes only through its own validation (issue 11).

## Acceptance criteria

- [ ] Only the PM Task's EIC can do this. Others get 403.
- [ ] The created E-Call has the PM Task's Site and Equipment and a `pm_task_id` reference, and follows issue 12's rules (routing, snapshot, initial status).
- [ ] The PM Task's status is identical before and after (tested for `For Validation` and `Returned`).
- [ ] `GET /api/pm-tasks/{task}` lists the E-Calls filed from it.

## Comments
- 2026-11-22 (product-owner): Human decision (issue 11 Decisions, spec.md): when a Site's EIC is replaced, the **current Site EIC** (`site_settings.eic_employee_id` at the time of the request) acts on pending items. The EIC captured on the record is history only and isn't used for authorization. "The PM Task's EIC" in the criteria means the **current Site EIC** of the task's Site. Add a test where a replaced EIC gets 403.
- 2026-11-23 (product-owner): Clarifications for dev-lead, re-checked against main 7845107.
  - **Statuses (provisional, see the open question below):** filing is allowed only while the PM Task is `For Validation` or `Returned` (the PRD's "EIC reviews the finding"). Any other status gives 422 with a clear message. It's easier to widen this later than to narrow it.
  - **Who:** use the existing `PmTaskPolicy::validate` (`SiteSetting::isActingEic`, the current Site EIC, never vendor staff). The replaced-EIC test gets 403. Vendor staff get 403.
  - **Reuse issue 12, don't duplicate it:** create through `ECall::file(...)`. Requestor = the EIC user. Routing = the Site's current Site Vendor and EIC (the same no-Vendor/no-EIC 422 messages as `StoreECallRequest`). Site and Equipment Snapshots are re-read from the master data directory at filing time, as issue 12 does; the PM Task's own snapshots are not copied. The initial status is For Vendor Assessment. The attachment limits come from `config/pss.php`. If the Equipment is no longer at the Site in the directory, return 422 and create nothing.
  - **Close the hole issue 12 left open:** `POST /api/e-calls` currently accepts any `pm_task_id` without checking it (`StoreECallRequest` rule `nullable|integer`; the e_calls migration comment says "Issue 13 validates it"). Make the generic endpoint refuse `pm_task_id` (422, `prohibited`), so the only way to link an E-Call to a PM Task is the new endpoint with its EIC check. Add a nullable foreign key `e_calls.pm_task_id -> pm_tasks.id` in a new migration. `tests/Feature/ECallApiTest.php` ~line 170 currently asserts that `pm_task_id: 42` is accepted, and ~line 189 has a validation case for it. Change them to assert the 422 `prohibited` response. This was deferred to this issue on purpose, so it's in scope here and needs no follow-up issue.
  - **`GET /api/pm-tasks/{task}` does not exist yet.** Add it (authorize with the existing `view` policy) returning `PmTaskResource` (which now includes `timeliness`, from issue 09) plus `e_calls`: the E-Calls with this `pm_task_id`, oldest first, each with at least id, status, priority, nature_of_issue and created_at.
  - Test the "status unchanged" criterion by checking the status **and** `work_done_date` before and after, and that no Approval Record was written.
  - No frontend change is required in this issue.
  - **Open question for the human (doesn't block):** can the EIC also file an E-Call from a **Completed** PM Task (after approving the report, for example once they have read the findings)? Until it's answered, Completed gives 422.


## Implementation decisions

1. **Endpoint:** `POST /api/pm-tasks/{pmTask}/e-calls` (multipart or JSON), body `nature_of_issue` (required, max 255), `description` (required, max 5000), `priority` (emergency/high/normal/low), `attachments[]` (optional, limits from `config/pss.php` `e_call_attachments`, same rules as `POST /api/e-calls`). Returns 201 with the usual `ECallResource`. The issue's "nature" field is named `nature_of_issue` to match issue 12.
2. **Who:** `PmTaskPolicy::validate` (current Site EIC via `SiteSetting::isActingEic`). The EIC captured on the task, vendor staff and any other employee get 403; guests get 401. The check is done once in the FormRequest. Filing changes no task state, so the task row is not locked (unlike approve/return).
3. **Statuses:** only `for_validation` and `returned`. Any other status gives 422 on key `pm_task`: "An E-Call can only be filed from a PM Task that is For Validation or Returned." Completed gives 422 until the human answers the open question. Widening it means changing `StorePmTaskECallRequest::FILEABLE_STATUSES`.
4. **422 order and keys** (checked after field validation, first failure wins): `pm_task` (status), then `site` (Site not in directory: "The PM Task's Site is not known to the site directory."; then the two `StoreECallRequest` no-Vendor / no-EIC messages, reused as constants), then `equipment` ("The PM Task's Equipment is no longer at its Site in the equipment directory.", which also covers Equipment missing from the directory). Nothing is created on any 422.
5. **Creation** goes through `ECall::file(...)`: requestor = the EIC user, routing = the Site's current Site Vendor and EIC (so it can differ from the PM Task's vendor), Site/Equipment/Category snapshots re-read from the directory at filing time (the task's snapshots are not copied), status For Vendor Assessment, `pm_task_id` = the task.
6. **Task unchanged:** the endpoint never writes to `pm_tasks` and writes no Approval Record. Tested for For Validation and Returned (status, `work_done_date`, Approval Record count).
7. **Generic endpoint:** `POST /api/e-calls` now rejects any `pm_task_id` with 422 (`prohibited`), and always stores `pm_task_id = null`. `ECallApiTest` was updated accordingly. API break for any client sending `pm_task_id` there (none known; no frontend sends it).
8. **Foreign key:** new migration `2026_11_23_215151_add_pm_task_foreign_key_to_e_calls_table.php` adds `e_calls.pm_task_id -> pm_tasks.id` (no cascade, so a PM Task referenced by an E-Call cannot be deleted). **Deployment note:** before adding the key it sets `pm_task_id` to null on any existing E-Call whose value points at no PM Task (issue 12 stored it unchecked). Valid references are kept. `down()` drops the key but does not restore cleared values.
9. **`GET /api/pm-tasks/{pmTask}`** (new): authorized by the existing `view` policy (any internal user, or the task's Vendor's staff; another Vendor's staff get 403). Returns `PmTaskResource` (with `timeliness`) plus `e_calls`: `[{id, status, priority, nature_of_issue, created_at}]`, oldest first (by id). `e_calls` appears only on this endpoint (other PM Task endpoints don't load it).
10. **Vendor visibility on show:** vendor staff only see E-Calls routed to their own Vendor (`ECall::visibleTo`), the same rule as `GET /api/e-calls`. So if the Site Vendor changed between filings, the PM Task's vendor does not see the E-Call routed to the new vendor. Internal users see all of them.
11. No frontend change.
