# 08 — Automatic PM Task generation

Status: ready-for-agent
Blocked by: 05, 06, 07
Type: task

## What to build

A scheduled job (Laravel scheduler, queued) that makes sure every Equipment at a Site with a PM Frequency has its next **PM Task** (PRD §6.4):
- Assigned to the Site Vendor and Site EIC at the time of generation.
- Linked to the current PM Checklist version for the Equipment's category.
- Stores an Equipment and Site **Snapshot**.
- Initial PM Status `Scheduled`.
- **Sliding schedule**: the next task's due date = the previous task's Work Done Date + the frequency (glossary). Once a task is Completed (issue 11 fires `PmTaskCompleted`), its successor is generated.
- **No duplicates**: never more than one open PM Task per Equipment, even if the job runs twice at the same time.
- Historical PM Tasks remain after the next one is generated.

## Open questions (for the human)

1. **First due date**: for an Equipment with no PM history in PSS, what is the first PM Task's due date? Options: a Site "start date of maintenance cycle" (PRD §6.2), today + frequency, or a last-PM date imported from the old process.
2. **Equipment without a checklist**: if the Equipment's category has no active PM Checklist, should the task still be generated (with no checklist), or skipped and flagged?
3. **Sites without a Site Vendor or EIC**: skip them, or generate unassigned tasks?
4. **Equipment removed upstream**: if the Equipment disappears from the external API, should its open PM Task be cancelled automatically?
5. How often should the job run (daily is assumed)?

## Acceptance criteria (draft)

- [ ] Running the job creates one Scheduled PM Task per eligible Equipment, with vendor, EIC, checklist version and snapshot.
- [ ] Running it twice (or concurrently) creates no duplicates (DB unique constraint or lock, plus a test).
- [ ] Completing a task with Work Done Date D leads to a successor due at D + frequency.

## Comments

## Decisions (grilling, 2026-11-22)
- Q1 answered: a newly registered Equipment's **first PM Task is due one Site PM Frequency interval after its installation date**. After that, the schedule slides from each Work Done Date. (Check that the external API exposes the installation/commissioning date.)
- Q2 answered: if the category has no active checklist, **still generate the task**, flagged "no checklist". The vendor submits a report without a checklist, and the dashboard surfaces "missing checklist".
- Q3 answered: Sites with no Site Vendor or no EIC are **skipped** and shown on a "needs setup" list for admins.
- Q4 answered: if the Equipment is removed upstream, **keep its open PM Task and flag it**. The EIC decides whether to cancel it (issue 25). No auto-cancel.
- Q5 (job frequency) is still open. Daily is assumed.
- Q5: run the job **daily**. This was the default when the issue moved to ready-for-agent and can be tuned later.
