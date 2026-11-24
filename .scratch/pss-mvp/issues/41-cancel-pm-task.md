# 41 — Cancel a PM Task

Status: ready-for-agent
Blocked by: 08, 25, 32
Type: task

## What to build

Split from issue 25 on 2026-11-24. The PRD lists `CANCELLED` for PM Tasks ("cancelled with an authorized reason", §6.8). The human decided (issue 25 Decisions, 2026-11-22):

- **Who**: the **current** Site EIC (a generated PM Task has no human creator).
- **When**: only before the vendor has started the work, i.e. while `Scheduled`. Once it's `In Progress` or later, it can't be cancelled.
- **Reason**: always required. Writes an Approval Record (action `cancel`).
- **Next task**: cancelling does **not** automatically create the next PM Task. The EIC picks the next due date when cancelling (or later). Until then, that Equipment has no scheduled PM.

Blocked by 25 only so it reuses the cancel pattern (endpoint shape, Approval Record) that 25 sets up.

## Open questions (for the human)

1. **Generation job after a Cancelled task** (raised by qa in the issue 08 review, still unanswered). Issue 08's job counts a new task's due date from the latest `Completed` task's Work Done Date, or from `installedOn` if there is none. If an Equipment's only past PM Task was **Cancelled**, the job counts from `installedOn` again, so the new task could be **due in the past** (immediately Overdue). Which should happen:
   - (a) the job generates nothing for that Equipment after a Cancelled task until the EIC sets the next due date (this matches "until then, that Equipment has no scheduled PM"),
   - (b) the job keeps counting from `installedOn` / the last Completed task, even if the date is past, or
   - (c) something else, e.g. counting from the cancellation date?
2. **"Later"**: if the EIC doesn't pick the next due date when cancelling, where do they set it later? A separate endpoint on the Equipment? Should an Equipment waiting for a date show on the Needs Setup list (issue 32)?
3. Can the EIC also cancel a PM Task that is `Returned` or `For Validation` (the vendor has already done the work)? Our reading of "before the vendor has started" is **no**; please confirm.

## Decisions (human, 2026-11-24)

- Q1 (a): after a **Cancelled** task, the generation job **creates nothing** for that Equipment until the EIC sets the next due date.
- Q2: a separate **"set next PM due date"** action by the **current Site EIC**. It works only while the Equipment has **no open PM Task**, and it **creates the task**. To change the date later, the EIC cancels that task again. Equipment waiting for a date shows on **Needs Setup** ("waiting for next PM date").
- Q3: **only `Scheduled` tasks can be cancelled**. `Returned` and `For Validation` (and every other status) get 422.

## Acceptance criteria

- [ ] `POST /api/pm-tasks/{pmTask}/cancel` with a required `reason`: only the **current** Site EIC. Others (former EIC, vendor staff, other users) get 403. Only `Scheduled`, otherwise 422. A blank reason is 422. It becomes `Cancelled` and writes one Approval Record (`cancel`) with the reason.
- [ ] Optional `next_due_date` on the cancel: when given, a new `Scheduled` PM Task is created for that Equipment with that due date, in the same transaction (same Site Vendor, current checklist version, as the generation job would).
- [ ] `POST /api/equipment/{equipmentId}/next-pm-due-date` with `due_date`: current Site EIC only (403 otherwise). 422 if the Equipment already has an open PM Task. Creates the `Scheduled` PM Task.
- [ ] Generation job: an Equipment whose latest PM Task is `Cancelled` and that has no open task gets **no** new task, whatever its installation date or last Completed task. Test that the issue 08 case (a Cancelled task with an old `installedOn`) no longer creates a past-due task.
- [ ] Needs Setup lists such Equipment as "waiting for next PM date", and it drops off once a task exists.
- [ ] After a task created by the EIC is Completed, the job's normal sliding schedule (Work Done Date + frequency) takes over again.

## Comments

### Clarifications (product-owner, 2026-11-24)

- **Provisional (to confirm with the human)**: `next_due_date` / `due_date` must be today or later (Asia/Manila), otherwise 422, so the EIC can't create an already-Overdue task. Both actions need the Site to have a Site Vendor (422 otherwise, the same reason the job skips it).
- Reuse the cancel pattern from issue 25 (endpoint shape, reason, Approval Record) and the task-building code of the generation job (issue 08). Don't copy them.
- Blocked by 32 too, for the Needs Setup list it adds to.
- Record your decisions under a dated "Decisions (dev-lead, ...)" heading here.
