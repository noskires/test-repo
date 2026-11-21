# 01 — Vendor registry

Status: ready-for-agent
Blocked by: none
Type: task

## What to build

The first PSS-owned master record: **Vendor** (PRD Module 6). Vendors are not in the external API, so PSS owns this table. This issue also sets up the API scaffolding that later issues build on: register `routes/api.php` in `bootstrap/app.php` **without** `php artisan install:api` or new packages.

Vendor fields for this slice: name (required), contact person, email (required, valid format), contact number, status (`active` / `inactive`, default `active`).

Endpoints:
- `GET /api/vendors`: list vendors. Optional `?status=` filter.
- `POST /api/vendors`: create.
- `GET /api/vendors/{vendor}`: show.
- `PATCH /api/vendors/{vendor}`: update, including deactivating.

There are no delete endpoints. A Vendor is deactivated so that history keeps working.

Authorization: create and update require the `manage-vendors` Gate (System Administrator, see spec). Read requires an authenticated user. Issue 04 wires the real roles. Until then, tests set the Gate outcome.

Out of scope here (later issues): accreditation document upload, Site assignment (05), Price List (14), linking vendor staff users to a Vendor (04).

## Acceptance criteria

- [x] `routes/api.php` exists and is registered. `composer.json` is unchanged.
- [x] `vendors` migration, `Vendor` model and factory exist.
- [x] Creating a vendor with a valid payload returns 201 and persists it with status `active` by default.
- [x] Creating without a name, or with an invalid email, returns 422 with validation errors for those fields.
- [x] Updating name, contact fields or status returns 200 and persists the change.
- [x] `GET /api/vendors?status=active` returns only active vendors.
- [x] Create and update return 403 when the `manage-vendors` Gate denies.
- [x] All endpoints return 401 for unauthenticated requests.
- [x] There is no route that deletes a vendor.
- [x] PHPUnit feature tests cover every criterion above, and `php artisan test` passes.

## Comments

- 2026-11-22 (product-owner): Most of this slice already landed on main with the scaffold PR #2 (Vendor model, VendorStatus enum, controller, Store/Update requests, VendorResource, migration, factory, `routes/api.php` registered in `bootstrap/app.php`, `tests/Feature/VendorApiTest.php`). Remaining work is to **verify every acceptance criterion above has a passing PHPUnit test and fill the gaps**, in particular: 403 from the `manage-vendors` Gate on create and update, the `?status=` filter, 401 on all endpoints, and the absence of any delete route. Scope is unchanged.
- 2026-11-22 (qa): QA PASS. Ran `php artisan test` from backend/: 21 passed (49 assertions), 0 failed, 0 skipped (19 of them in VendorApiTest). Every acceptance criterion maps to a real, asserting test. composer.json is unchanged.
