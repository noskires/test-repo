# 05 — Assign the Site Vendor and Site EIC

Status: done
Merged: PR #8 (ab0ff0d), 2026-11-22
Blocked by: 01, 02
Type: task

## What to build

PSS-owned settings per Site (the Site itself comes from `MasterDataDirectory`):
- **Site Vendor**: exactly one current Vendor per Site. It handles all PM and corrective work there. Only an administrator can set or replace it.
- **EIC**: the engineer (external `employee_id`) assigned to the Site. They review every PM Report, FSR/BOQ and completed Work Order at that Site.

Store a **Snapshot** of the Site (name and any location label the port gives) on the settings record.

Endpoints:
- `GET /api/sites/{siteId}/settings`: current Site Vendor and EIC.
- `PUT /api/sites/{siteId}/settings/vendor`: body `{vendor_id}`.
- `PUT /api/sites/{siteId}/settings/eic`: body `{employee_id}`.

Keep a history of replacements (who changed it, when, old and new value) so past jobs can still be traced to the vendor or EIC of that time.

## Acceptance criteria

- [x] Setting the Site Vendor for a Site known to the directory stores it with a Site Snapshot. An unknown Site returns 404.
- [x] Only an `active` Vendor can be assigned. An inactive or missing Vendor returns 422.
- [x] Replacing the Site Vendor keeps exactly one current vendor and records a history entry with the old vendor, new vendor, user and timestamp.
- [x] Setting the EIC to an employee unknown to the directory returns 422. A known one is stored and history is recorded.
- [x] Both PUTs return 403 when the `manage-site-settings` Gate denies.
- [x] `GET` returns the current vendor and EIC, or nulls if they aren't set.
- [x] Feature tests use the in-memory directory fake.

## Comments

- 2026-11-22 (product-owner): Re-checked before hand-off. Blockers 01 and 02 are merged. Two clarifications, scope unchanged:
  - The `Site` value object from issue 02 carries only `id` and `name`, so the Site Snapshot here is the Site name. Don't extend the port to invent a location field. Issue 03 may add one later.
  - PRD v2 §5: "jobs already started stay with the old vendor; new jobs and schedules go to the new one". This slice only has to keep the history that makes this possible. The rule itself is enforced when PM Tasks (08) and E-Calls (12) copy the Site Vendor at creation.

- 2026-11-22 (qa): QA PASS. Ran `php artisan test` from backend/: 127 passed (438 assertions), 0 failed, 0 skipped. Every acceptance criterion maps to a test that asserts it. site_id and eic_employee_id are external ID strings, and there are no sites or employees tables (ADR 0001).
