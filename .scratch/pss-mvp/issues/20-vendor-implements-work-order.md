# 20 — Site Vendor implements the Work Order and declares completion

Status: ready-for-agent
Blocked by: 19
Type: task

## What to build

PRD §8.3: a Work Order in `For Implementation` is visible to its Site Vendor's staff. When the work is done, the vendor declares it complete: uploads the completion documents and photos, and reports the **actual quantity used** for each Work Order line. Status → `Implemented` (now waiting for EIC validation, issue 21).

Allowed from `For Implementation`, including after an EIC Return (issue 21 sends it back to `For Implementation`).

Endpoint: `POST /api/work-orders/{wo}/completion`. Listing: `GET /api/vendor/work-orders`.

Note: this issue only depends on 19 for a Work Order to reach `For Implementation`. Tests can put Work Orders in that status with factories.

## Acceptance criteria

- [ ] Only staff of the Work Order's Site Vendor can submit. Others get 403.
- [ ] It only works from `For Implementation`. Otherwise 422.
- [ ] Every Work Order line needs an actual quantity >= 0. A missing line returns 422. At least one completion document is required.
- [ ] Success sets `Implemented` and stores the actual quantities and files. A resubmission after Return keeps the earlier submission in history.
- [ ] `GET /api/vendor/work-orders` lists only the vendor's Work Orders.

## Comments
