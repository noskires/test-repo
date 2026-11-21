# 07 — PM Checklist templates with versions

Status: ready-for-agent
Blocked by: 02
Type: task

## What to build

Administrators configure **PM Checklist** templates per **Equipment Category** (category IDs come from `MasterDataDirectory`), PRD §6.5.

- A template has a name, an Equipment Category and an ordered list of **Checklist Items**.
- A Checklist Item has: label, response type (`pass_fail`, `good_defective`, `yes_no`, `numeric`, `text`, `photo`), `mandatory` flag, `remarks_required_on_fail` flag, `photo_required` flag, optional acceptable criteria (for numeric: min and/or max; free-text criteria for the others), and an active flag.
- **Versioning**: saving changes to a template creates a **new version**. Old versions are never changed, so past PM Reports stay consistent. An item is deactivated, never deleted.
- At most one active template per Equipment Category at a time.

Endpoints (writes need the `manage-checklists` Gate): list, create, show (with current version), update (creates a new version), and get by category: `GET /api/checklist-templates?equipment_category_id=`.

## Acceptance criteria

- [ ] Creating a template for a category known to the directory stores version 1 with its items. An unknown category returns 422.
- [ ] Invalid item definitions return 422 (unknown response type, or numeric min > max).
- [ ] Updating a template creates version N+1. Version N's items are unchanged and still readable by ID.
- [ ] Deactivating an item removes it from the new version but keeps it in older versions.
- [ ] Creating a second active template for the same category returns 422.
- [ ] Writes return 403 when the `manage-checklists` Gate denies.
- [ ] The lookup by category returns the current version.

## Comments

## Decisions (grilling, 2026-11-22)
- **MVP ships a fixed set of checklists** (Genset, ACU, ATS, Building), seeded from the approved PM standards. The admin create/edit endpoints and screen are **out of the MVP** (version 2). Keep the versioning model so the editor can be added later without a migration.
