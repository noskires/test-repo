# 06 — Set a Site's PM Frequency

Status: ready-for-agent
Blocked by: 05
Type: task

## What to build

One **PM Frequency** per Site (glossary). Every Equipment at that Site follows it. This was decided over the PRD's "per equipment or category" (see spec, conflicts table). An administrator sets it alongside the Site Vendor and EIC (issue 05's Site settings). The slice also includes a pure function that computes the next due date from a date and a frequency (sliding schedule, PRD v2 §6.1).

MVP frequencies (fixed list, no custom intervals): `monthly`, `twice_a_month` (= every 15 days), `bi_monthly` (every 2 months), `quarterly`, `semi_annual`, `annual`.

Build:
- A PM Frequency enum with the six values above.
- A nullable PM Frequency on the Site settings from issue 05, exposed in `GET /api/sites/{siteId}/settings`.
- `PUT /api/sites/{siteId}/settings/pm-frequency` with body `{pm_frequency}`, behind the `manage-site-settings` Gate. Each change writes a Site settings history entry (old value, new value, user, timestamp), like the vendor and EIC changes.
- A pure function `nextDueDate(CarbonImmutable $from, PmFrequency $frequency): CarbonImmutable` (the name is the developer's choice). Month-based intervals **clamp to the month's last day**. `twice_a_month` adds exactly 15 days.

Out of scope here: re-dating open PM Tasks when the frequency changes. PM Tasks don't exist until issue 08, so that rule (only `Scheduled` tasks are re-dated) belongs to issue 08. This slice should make the change observable, e.g. by dispatching a `SitePmFrequencyChanged` event with the site ID and the old and new frequency, so 08 can react to it.

## Acceptance criteria

- [x] `PUT .../settings/pm-frequency` with a valid value returns 200, stores it, and `GET .../settings` returns it. It is null when never set.
- [x] An unknown value returns 422. An unknown Site returns 404. The PUT returns 403 when the `manage-site-settings` Gate denies, and 401 when unauthenticated.
- [x] Changing the frequency writes a history entry with the old value, new value, user and timestamp, and dispatches `SitePmFrequencyChanged` (or equivalent). Setting the same value again writes no history entry.
- [x] Unit tests for `nextDueDate`:
  - `monthly`: 2026-01-15 → 2026-02-15. 2026-01-31 → 2026-02-28. 2028-01-31 → 2028-02-29 (leap year).
  - `twice_a_month`: 2026-01-31 → 2026-02-15. 2026-02-20 → 2026-03-07.
  - `bi_monthly`: 2026-12-31 → 2027-02-28.
  - `quarterly`: 2026-11-30 → 2027-02-28.
  - `semi_annual`: 2026-08-31 → 2027-02-28.
  - `annual`: 2028-02-29 → 2029-02-28.
- [x] Feature tests use the in-memory directory fake. No new packages, and `php artisan test` passes.

## Comments

- 2026-11-22 (product-owner): Replaced the stale open questions and draft criteria with final ones based on the grilling decisions below and PRD v2 §6.1. Re-dating `Scheduled` PM Tasks on a frequency change is moved to issue 08, because PM Tasks don't exist yet. 08 is already blocked by 06.
- 2026-11-22 (product-owner): Re-checked before hand-off (main 605c950). Blocker 05 is merged. No open questions remain for this slice. The "twice a month" rule (exactly +15 days) and the month-end rule (clamp to the last day) were settled in grilling, and the expected dates in the criteria check out. GLOSSARY "PM Frequency" now records both rules. Implementation notes, scope unchanged:
  - The existing history table `site_assignment_changes` has typed vendor/EIC columns and an `assignment` discriminator. Recording the frequency change there (e.g. `assignment = pm_frequency` plus nullable old/new frequency columns) or in a separate table is the developer's choice. Either way, the change needs the old value, new value, user and timestamp.
  - `manage-site-settings` is checked through `Gate::allows` in the form requests, as for vendor and EIC. Reuse that pattern.
  - A Site with no `site_settings` row yet gets one created on the first PUT, as the vendor and EIC assignments do. Its history entry's old value is null.
- 2026-11-22 (qa): QA PASS. Ran `php artisan test` from backend/: 263 passed (982 assertions), 0 failed. `tests/Feature/SiteSettingsApiTest.php` and `tests/Unit/Scheduling`: 48 passed (194 assertions). Every acceptance criterion maps to a test that asserts it, and all 9 `nextDueDate` example dates are unit-test data sets. `SitePmFrequencyChanged` is dispatched after commit, so issue 08 can re-date Scheduled PM Tasks.

## Decisions (grilling, 2026-11-22)
- Q1 answered: **Twice a month = every 15 days**, added to the Work Done Date.
- Q2 answered: the **fixed list is enough for the MVP** (monthly, twice a month = 15 days, every 2 months, quarterly, semi-annual, annual). No custom intervals.
- Q3 answered: month arithmetic **clamps to the month's last day** (31 Jan + 1 month = 28/29 Feb).
- Q4 answered: a frequency change **also re-dates the current open PM Task** (new due date = last Work Done Date + new interval). Whether that includes tasks already In Progress is still open.
- Q4 follow-up answered: re-dating applies **only to PM Tasks still in `Scheduled`**. Tasks In Progress, For Validation or Returned keep their due date.
- Q5 (preferred schedule / cycle start / effective date per Site): **not needed for the MVP**. The first due date comes from the installation date (issue 08).
