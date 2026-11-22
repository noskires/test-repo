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
