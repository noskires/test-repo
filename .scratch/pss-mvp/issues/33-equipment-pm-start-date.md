# 33 — Set a PM start date for Equipment with no installation date

Status: ready-for-agent
Blocked by: 08, 32
Type: task

## What to build

The human decided (2026-11-22) that an Equipment with no installation date and no PM history gets no PM Task, and stays on the "Needs Setup" list (issue 32) **until a start date is set**. Who sets that start date isn't decided yet.

## Open questions (for the human)

1. **Where does the start date come from?**
   - (a) Only from the external API: the owners of the master data fill in the installation date there, and PSS builds nothing. This issue becomes `wontfix`.
   - (b) An administrator can also enter a **PM start date** for that Equipment in PSS. It would be a PSS-owned value keyed by the external Equipment ID (allowed by ADR 0001, like the per-Site assignments).
2. If (b): when the external API later gets an installation date too, which one wins?
3. If (b): is the first PM Task due **on** the start date, or at the start date + the Site's PM Frequency (the same as the installation-date rule)?

## Decisions (human, 2026-11-24)

- Q1 (b): a **PSS-owned PM start date**, keyed by the Equipment's source ID. **Only a System Administrator** sets, changes or clears it, behind `manage-site-settings`. Every change writes a history entry.
- Q2: the **installation date wins** once the master data has one. The PSS start date is only a fallback.
- Q3: the first PM Task is due **on** the start date.
- Changing the start date **doesn't move** a PM Task that already exists.

## Acceptance criteria

- [ ] `PUT /api/equipment/{equipmentId}/pm-start-date` with `pm_start_date` (a date, or `null` to clear), behind `manage-site-settings` (others 403). 404 for an unknown Equipment. Each change writes a history entry (Equipment ID, old value, new value, user, timestamp). Setting the same value again writes nothing.
- [ ] The start date lives in its own PSS table, not on the imported `equipment` table, so the nightly import (issue 03) never overwrites it.
- [ ] Generation job: an Equipment with no installation date, no PM history and a PSS start date gets its first PM Task due **on** the start date (not start date + frequency).
- [ ] When the Equipment has an installation date, the installation-date rule applies and the PSS start date is ignored (test both set).
- [ ] Changing or clearing the start date leaves an existing PM Task's due date unchanged (test).
- [ ] Needs Setup: the Equipment leaves the "no installation date" list once it has a start date, and returns if the date is cleared while it has no PM Task.
- [ ] Equipment whose latest task is Cancelled follows issue 41's rule (waits for the EIC). The start date doesn't override it.

## Comments

- 2026-11-22 (product-owner): Created from the human's answer on issue 08 ("until a start date is set"). Without it, the only way for such an Equipment to leave the list is an `installedOn` appearing in the directory, which issues 08 and 32 already handle. So this doesn't block 08 or 32.

### Clarifications (product-owner, 2026-11-24)

- A start date in the past is allowed (the first task is then immediately Overdue, which is correct for equipment that's behind). No human rule says otherwise.
- Build after 41 if both are open, since both touch the generation job and Needs Setup.
- Record your decisions under a dated "Decisions (dev-lead, ...)" heading here.
