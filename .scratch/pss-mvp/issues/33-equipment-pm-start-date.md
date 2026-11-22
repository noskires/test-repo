# 33 — Set a PM start date for Equipment with no installation date

Status: needs-info
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

## Acceptance criteria (draft, only if the answer is (b))

- [ ] An admin sets a PM start date for an Equipment (behind the `manage-site-settings` Gate). The change is written to a history entry (old, new, user, timestamp).
- [ ] The next generation run creates that Equipment's first PM Task due according to Q3, and the Equipment leaves the "Needs Setup" list.

## Comments

- 2026-11-22 (product-owner): Created from the human's answer on issue 08 ("until a start date is set"). Without it, the only way for such an Equipment to leave the list is an `installedOn` appearing in the directory, which issues 08 and 32 already handle. So this doesn't block 08 or 32.
