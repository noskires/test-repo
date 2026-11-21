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

- [ ] `routes/api.php` exists and is registered. `composer.json` is unchanged.
- [ ] `vendors` migration, `Vendor` model and factory exist.
- [ ] Creating a vendor with a valid payload returns 201 and persists it with status `active` by default.
- [ ] Creating without a name, or with an invalid email, returns 422 with validation errors for those fields.
- [ ] Updating name, contact fields or status returns 200 and persists the change.
- [ ] `GET /api/vendors?status=active` returns only active vendors.
- [ ] Create and update return 403 when the `manage-vendors` Gate denies.
- [ ] All endpoints return 401 for unauthenticated requests.
- [ ] There is no route that deletes a vendor.
- [ ] PHPUnit feature tests cover every criterion above, and `php artisan test` passes.

## Comments
