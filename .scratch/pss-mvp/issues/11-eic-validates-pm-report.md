# 11 — EIC validates a PM Report (and shared Approval Records)

Status: ready-for-agent
Blocked by: 10
Type: task

## What to build

The Site's EIC reviews a PM Report in `For Validation` (PRD §6.7, glossary):
- **Approve**: PM Status → `Completed`. The PM Task's Work Done Date is confirmed as the vendor reported it (glossary: not the approval date). Fire a `PmTaskCompleted` event. Issue 08's generator listens to it and creates the successor.
- **Return**: PM Status → `Returned`, back to the vendor. Remarks are required.
- There is **no Reject** for PM Reports (glossary overrides PRD §6.7).

Build the **shared Approval Record mechanism** here, to be reused by issues 17, 19, 21, 23 and 25. It's a polymorphic `approval_records` table: subject, approver user / `employee_id`, action (`approve` / `return` / `reject`), remarks, previous status, resulting status, timestamp. Expose `GET /api/pm-tasks/{task}/approvals`.

Endpoints: `POST /api/pm-tasks/{task}/approve`, `POST /api/pm-tasks/{task}/return`.

## Acceptance criteria

- [ ] Only the employee who is the task's EIC can approve or return. Others get 403.
- [ ] Approve or Return on a task not in `For Validation` returns 422.
- [ ] Approve sets `Completed`, keeps the vendor's Work Done Date, writes an Approval Record, and fires `PmTaskCompleted` (`Event::fake()` test).
- [ ] Return without remarks returns 422. With remarks, it sets `Returned` and writes an Approval Record.
- [ ] There is no reject endpoint for PM Tasks.
- [ ] Approval Records hold approver, action, remarks, previous and resulting status, and timestamp, and are listed in chronological order.
- [ ] The Approval Record logic is a reusable component (trait/service) with its own unit test.

## Comments

## Decisions (grilling, 2026-11-22)
- The reviewer is the **Site's EIC**, the same engineer who reviews E-Calls at that Site.
