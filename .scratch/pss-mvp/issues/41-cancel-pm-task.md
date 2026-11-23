# 41 — Cancel a PM Task

Status: needs-info
Blocked by: 08, 25
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

## Acceptance criteria (draft)

- [ ] The current Site EIC can cancel a `Scheduled` PM Task with a required reason; it becomes `Cancelled` and writes one Approval Record (`cancel`).
- [ ] Others (former EIC, vendor staff, other users) get 403. Other statuses return 422. A blank reason returns 422.
- [ ] The EIC can give the next due date when cancelling; a PM Task is then created for that date.
- [ ] (After Q1/Q2) the generation job's behaviour after a Cancelled task matches the human's answer.

## Comments
