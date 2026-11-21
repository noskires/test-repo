# 09 — PM Timeliness (Upcoming / Due / Overdue)

Status: needs-info
Blocked by: 08
Type: task

## What to build

Compute **PM Timeliness** for each open PM Task. It is shown next to PM Status and never replaces it, so a task can be "Returned" and "Overdue" at the same time (glossary). This is used by the PM Calendar, the dashboard and the "overdue" reports.

## Open questions (for the human)

1. **Upcoming**: how many days before the due date does a task become "Upcoming" (e.g. 7 days)? Is it one number for all frequencies, or does it depend on the frequency?
2. **Due**: PRD says "scheduled for the current period". Does that mean due date = today, due within the current calendar month, or within a grace window?
3. **Overdue**: overdue the day after the due date, or after a grace period?
4. Does a task in `For Validation` still count as Overdue if the EIC hasn't approved by the due date? Or is the vendor's Work Done Date what counts?

## Acceptance criteria (draft)

- [ ] A pure function `timeliness(dueDate, today, status)` with unit tests at every boundary.
- [ ] Completed and Cancelled tasks have no timeliness.
- [ ] The PM Task API returns timeliness alongside status.

## Comments

## Decisions (grilling, 2026-11-22)
- **Upcoming**: from 7 days before the due date, the same for every frequency.
- **Due**: on the due date.
- **Overdue**: from the day after the due date, with no grace period, if the work isn't done.
- **On time vs late is judged by the Work Done Date**, not the approval date. A task the vendor submitted (with a Work Done Date) on or before the due date shows as For Validation, not Overdue, while it waits for the EIC.
