# 07 — PM Checklist templates with versions (fixed set, read-only API)

Status: done
Merged: PR #9 (ae7a3b8), 2026-11-22
Blocked by: 02
Type: task

## What to build

PRD v2 §6.2 and the grilling decision below: the MVP ships a **fixed set** of PM Checklists. There is **no** admin create/edit API or screen in the MVP; that editor is version 2. This slice builds the versioned data model, a seeding mechanism that loads checklist definitions from data, and read-only endpoints. The real checklist content (Genset, ACU, ATS, Building) and its mapping to external Equipment Category IDs is issue 31 (needs-info). This slice is tested with fixture definitions.

Model (PSS-owned. Equipment Category IDs are external, from `MasterDataDirectory`):
- A **PM Checklist** template has a name, an external `equipment_category_id`, and numbered **versions**. At most one active template per Equipment Category.
- A version holds an ordered list of **Checklist Items**: label, response type (`pass_fail`, `good_defective`, `yes_no`, `numeric`, `text`, `photo`), `mandatory`, `remarks_required_on_fail`, `photo_required`, optional acceptable criteria (numeric: min and/or max; free text for the others).
- Versions are immutable once written. Each item has a stable ID, so PM Tasks (issue 08) and PM Reports (issue 10) can point at a specific version and its items.

Seeding mechanism:
- One loader (seeder or artisan command, developer's choice) that takes checklist definitions as data (e.g. a PHP array or JSON file, in a single place) and writes them.
- It is **idempotent**. Re-running with an unchanged definition creates no new version. A changed definition (an item added, removed or edited) creates version N+1 and leaves version N untouched. That is how the fixed set gets updated before the editor exists. An item missing from the new definition is absent from N+1 but still present in N.
- A definition whose category is unknown to the directory is rejected with a clear error, and nothing is written for it.

Read endpoints (any authenticated user):
- `GET /api/checklist-templates`: list templates, each with its current version number. Optional `?equipment_category_id=` returns that category's current version with its items.
- `GET /api/checklist-templates/{template}`: the template with its current version and items.
- `GET /api/checklist-templates/{template}/versions/{version}`: a specific (possibly old) version with its items.

Out of scope: create/update/deactivate endpoints, the `manage-checklists` Gate (it comes with the v2 editor), and the real checklist content (issue 31).

## Acceptance criteria

- [x] Migrations and models exist for templates, versions and items. `composer.json` is unchanged.
- [x] Loading a fixture definition for a category known to the in-memory directory creates version 1 with its items in order.
- [x] Loading the same definition again creates no new version.
- [x] Loading a changed definition creates version 2. Version 1's items are unchanged and still readable through the versions endpoint.
- [x] An item removed from the definition is absent from version 2 and present in version 1.
- [x] A definition with an unknown category, an unknown response type, or numeric min > max is rejected and writes nothing.
- [x] Two definitions for the same category cannot both be active. The loader rejects the second one.
- [x] `GET /api/checklist-templates?equipment_category_id=` returns the current version with items. A category with no template returns an empty result, not an error (issue 08 flags "no checklist").
- [x] All read endpoints return 401 when unauthenticated, and 404 for an unknown template or version.
- [x] There are no POST, PUT, PATCH or DELETE routes for checklist templates.
- [x] PHPUnit unit and feature tests cover every criterion above, using the in-memory directory fake, and `php artisan test` passes.

## Comments

- 2026-11-22 (product-owner): Rewritten to match the grilling decision (fixed set in the MVP, editor in v2) and PRD v2 §6.2. The earlier admin create/update endpoints and the `manage-checklists` Gate are removed. The actual seeded content was split out into issue 31 (needs-info), because the PRD gives only example items for Genset, ACU and ATS, nothing for Building, no response types or flags per item, and the external Equipment Category IDs are unknown until issue 03.

- 2026-11-22 (qa): QA PASS. Ran `php artisan test` from backend/: 174 passed (589 assertions), 0 failed, 0 skipped. Every acceptance criterion maps to a test that asserts it. equipment_category_id is an external ID string, with no categories table (ADR 0001).

## Decisions (grilling, 2026-11-22)
- **MVP ships a fixed set of checklists** (Genset, ACU, ATS, Building), seeded from the approved PM standards. The admin create/edit endpoints and screen are **out of the MVP** (version 2). Keep the versioning model so the editor can be added later without a migration.
