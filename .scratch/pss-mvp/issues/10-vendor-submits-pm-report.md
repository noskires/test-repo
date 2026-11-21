# 10 — Site Vendor starts a PM Task and submits the PM Report

Status: ready-for-agent
Blocked by: 07, 08
Type: task

## What to build

Vendor staff (users whose `vendor_id` = the task's vendor) work on their PM Tasks (PRD §6.6):
- `GET /api/vendor/pm-tasks`: lists only PM Tasks assigned to the user's Vendor.
- `POST /api/pm-tasks/{task}/start`: `Scheduled` → `In Progress`.
- `POST /api/pm-tasks/{task}/report`: submits the **PM Report**: a response for each Checklist Item of the task's checklist version, the **Work Done Date**, findings / observations, materials and consumables used (free-text lines with quantity), and file attachments (PM Service Report, photos). Status → `For Validation`. Allowed from `In Progress` or `Returned`.

Validation follows the checklist version the task was generated with:
- Every mandatory item needs a response of the right type.
- A failed item (`fail` / `defective` / `no`, or numeric out of range) with `remarks_required_on_fail` needs remarks.
- `photo_required` items need a photo.

Files are stored with Laravel Storage, with DB references.

Note: this issue only depends on 08 for the PM Task table and model. Tests can create PM Tasks directly with factories and don't need 08's generator job. If 08 is still needs-info, the dev-lead can pull the PM Task model/migration into this issue.

## Acceptance criteria

- [ ] Vendor staff see only their own Vendor's tasks. Another vendor's task returns 403 for start and report.
- [ ] Start works only from `Scheduled`. Any other status returns 422.
- [ ] A report missing a mandatory item, missing remarks on a failed item, or missing a required photo returns 422, naming the item.
- [ ] Numeric readings outside the acceptable range count as failed.
- [ ] A Work Done Date in the future returns 422.
- [ ] A valid submission stores responses against the checklist version, stores the attachments, and sets status `For Validation`.
- [ ] Resubmitting from `Returned` replaces the current responses and keeps the earlier submission in history.
- [ ] Feature tests use `Storage::fake()`.

## Comments
