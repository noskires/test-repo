# 46 — Admin-created users and pending access

Status: done
Merged: PR #45 (34382ce), 2026-11-24
Blocked by: none
Type: task

## What to build

Issue 04 (merged, PR #43) creates a PSS `User` on first sign-in with `vendor_id = null`. Such a user counts as an internal employee and sees every Vendor's PM Tasks, E-Calls and Work Orders, even if they are really vendor staff who haven't been linked yet. This issue closes that gap (human, 2026-11-24). Ships **before** issue 45.

- Sign-in no longer creates users. A System Administrator adds each user, choosing **internal** or a **Vendor**.
- Every user has an **access** state: `internal`, `vendor` or `pending`. A pending user can sign in but can't use PSS until an administrator classifies them.
- `users.employee_id` becomes unique.

Out of scope: an admin screen for users (later issue), removing or deactivating users (issue 47), the System Administrator / vendor rules (issue 45), the real credential checker (issue 44).

## Acceptance criteria

Sign-in (Q13, Q16)
- [x] `POST /api/auth/login` no longer creates a `User` (the `firstOrCreate` in `AuthController::login` is removed). Login no longer calls `MasterDataDirectory`.
- [x] The password is checked first. If the `CredentialChecker` refuses the credentials, the response is **401** with the existing message ("Invalid employee ID or password."), whether or not the `employee_id` belongs to a PSS user. Test both cases (registered and unregistered) and assert the same status and body.
- [x] If the checker accepts the credentials but no PSS user has that `employee_id`, the response is **403** with the message "Your account isn't set up in PSS yet. Ask an administrator." No token, no user created. (Test by binding a fake `CredentialChecker` that accepts.)
- [x] A registered user with a correct password gets a token, whatever their access state (pending included).

Access state (Q9, Q8, Q6)
- [x] Every user has an `access` value: `internal`, `vendor` or `pending`. A user is `vendor` exactly when `vendor_id` is set.
- [x] A migration backfills existing users: those with a `vendor_id` become `vendor`, all others `internal`. Test the backfill (a user with and one without a `vendor_id` before the migration's data step).
- [x] `PUT /api/admin/users/{user}/vendor` with a `vendor_id` sets `vendor` (the existing rules still apply: unknown Vendor 422, current Site EIC 422). With `null` on a vendor-linked user it clears `vendor_id` and sets **`pending`** (no longer "ordinary employee again").
- [x] New `PUT /api/admin/users/{user}/access` with `{access: "internal"}` sets `internal`. Behind `can:manage-roles` (others 403). Any other `access` value, or a missing one, gives 422.
- [x] Leaving `pending` (by linking a Vendor or by setting `internal`) records **who made the change and when** (the acting admin's user id and a timestamp, stored on the user). Test both ways out.

Pending users (Q5)
- [x] A pending user can call `GET /api/auth/me`, `POST /api/auth/refresh` and `POST /api/auth/logout`. **Every other `auth:api` route returns 403** for them, including the admin API. Test at least one route of each kind: a record list (e.g. PM Tasks or E-Calls), a write (e.g. filing an E-Call), the dashboard and an admin route.
- [x] `GET /api/auth/me` (`CurrentUserResource`) also returns `access` (`internal`, `vendor` or `pending`).

Adding a user (Q14, Q15)
- [x] `POST /api/admin/users` behind `can:manage-roles` (others 403). Body: `employee_id` plus **exactly one** of `access: "internal"` or `vendor_id`. Returns **201** with the user (`id`, `employee_id`, `name`, `roles`, `vendor_id`, `access`).
- [x] 422 when: `employee_id` is missing; `MasterDataDirectory::findEmployee` doesn't know it; a PSS user already has it; both or neither of `access` / `vendor_id` are given; `access` is anything but `internal`; `vendor_id` isn't an existing Vendor. Nothing is stored on any 422.
- [x] The name is copied from the master data. The new user has no roles (roles are granted separately).
- [x] A migration adds a **unique** constraint on `users.employee_id`. A test asserts inserting a second user with the same `employee_id` fails.

Roles (Q7)
- [x] `POST /api/admin/users/{user}/roles` for a pending user returns 422 with the message "Classify this user first (internal or a Vendor) before granting roles." and stores no role.

Admin list (Q10)
- [x] `GET /api/admin/users` returns each user's `access`. `?access=pending` returns only pending users (also accept `internal` and `vendor`). Any other value gives 422.

Unchanged (Q17)
- [x] `php artisan pss:setup-first-admin` keeps working as before (existing tests stay green).

Angular (Q5, Q16)
- [x] When `GET /api/auth/me` returns `access: "pending"`, the app shows a "waiting for an administrator" page (with the user's name and the Log out button) instead of the dashboard, for every route. No menu entries.
- [x] On a 403 from login, the login screen shows the backend's `message`. Nothing is stored.
- [x] Unit tests (existing Angular runner) cover the pending page guard/redirect and the 403 login message. No new npm package.

## Decisions (human, 2026-11-24)

Grilling on issue 04's open gap (Q16 and issue 45). The human confirmed each point.
- Q13: sign-in no longer creates users.
- Q16: check the password first. Wrong password = 401 whether or not the person is registered, so sign-in never reveals who is registered. Correct password but not in PSS = 403 "Your account isn't set up in PSS yet. Ask an administrator." Supersedes issue 04's "unknown employee gets 401" default.
- Q14: `POST /api/admin/users` behind `can:manage-roles`. `employee_id` required, must exist in master data and not already belong to a PSS user (else 422). Name copied from master data. Exactly one of `access: internal` or `vendor_id` (existing Vendor). Roles are granted separately.
- Q15: unique DB constraint on `users.employee_id`.
- Access states internal / vendor / pending. Q9: backfill (vendor_id → vendor, others → internal). Q8: unlinking a Vendor sets pending. Q6: two ways out of pending (link a Vendor → vendor; `PUT /api/admin/users/{user}/access {access: internal}` → internal), each recording who and when.
- Q5: a pending user can sign in, but only auth/me, auth/refresh and auth/logout work; every other `auth:api` route returns 403. `me` returns `access`. Angular shows a "waiting for an administrator" page.
- Q7: granting any role to a pending user gets 422 ("classify this user first").
- Q10: `GET /api/admin/users` returns `access` and takes `?access=pending`. No admin screen in this issue.
- Q17: `pss:setup-first-admin` is unchanged.
- Q18: removing or deactivating users is out of scope (issue 47).

## Comments

- 2026-11-24 (product-owner), clarifications for dev-lead:
  - **Read issue 04's "Implementation decisions"** first (`issues/04-sso-login-and-roles.md`), especially 3 (endpoints, 401 message), 6 (no unique constraint, firstOrCreate), 10 (admin API shapes) and 12 (vendor link rules).
  - **Unique constraint and test fixtures.** Issue 04 decision 6 says about 60 existing tests create two users with the same `employee_id` (e.g. a vendor-staff user sharing the EIC's id, issue 38). Those fixtures must be changed to use distinct ids without weakening what they test. If a test really needs "the same person", it should use one user. Record what you changed in the issue.
  - **Storage is yours to choose** (e.g. an `access` column plus `access_set_by_user_id` / `access_set_at`), but keep the invariant "vendor iff `vendor_id` set" in one place (the `User` model), not repeated per controller. Recording who/when on every admin access change is fine; the criterion only requires the two ways out of pending.
  - **Pending block:** one middleware (or equivalent) on the `auth:api` group, with `auth/me` and `auth/logout` exempt (`auth/refresh` is already outside `auth:api`). Don't add checks to each controller or policy.
  - **Creating with `vendor_id`** goes through the same rules as linking: a user who is the current EIC of any Site can't be created as vendor staff (422, issue 37). Reuse the existing link logic.
  - **First admin:** the user `pss:setup-first-admin` creates must be `internal` (a pending admin couldn't act). If an existing user is pending when the command runs, leave access as it is and say so in the issue (no behaviour change, Q17).
  - **Seeders and factories:** `DemoAccountsSeeder` sets `internal` for EMP-9001/9002/1001 and `vendor` for EMP-8001. The `User` factory defaults to `internal`, with a state for `pending`.
  - **Admin-created users have no password.** With the local checker they can't sign in until a password is set (local/testing only). That's expected; issue 44 brings the real checker. Don't add a password field to `POST /api/admin/users`.
  - **Docs to update in the same change:** `backend/README.md` sign-in notes (users are added by an admin; the 403 for unregistered users).
  - **Decisions to confirm** (product-owner defaults; put them in the PR's "Decisions to confirm"):
    1. `PUT .../vendor` with `null` on a user who has no `vendor_id` (internal or pending) is a no-op 200 and doesn't change access.
    2. `PUT .../access {access: internal}` on a vendor-linked user returns 422 ("unlink the Vendor first"), so the vendor/vendor_id invariant can't break. On an internal user it's a no-op 200.
    3. A pending user who still holds roles (e.g. vendor staff with Management / Viewer, then unlinked) keeps them; they're blocked anyway until classified.
    4. Until issue 45 ships, a vendor-linked System Administrator who is unlinked becomes pending and loses admin access. If that is the last admin, recovery is a database fix. Issue 45 (refuse linking a System Administrator) closes this.
- 2026-11-24 (qa-46): QA PASS. Backend `php artisan test --compact`: 1212 passed (5621 assertions); `vendor/bin/pint --test` passed. Frontend `npx ng test --watch=false`: 58 passed (7 files). Every criterion maps to a real assertion (401 vs 403 split with exact bodies, pending 403 on every route kind, who/when for both ways out of pending, unique constraint). Concerns: three tests removed because the state cannot exist once employee_id is unique; migration 150001 fails on a production DB with duplicate employee_ids; simultaneous creates of one employee give a 500 (not handled).

## Implementation decisions

1. **Storage:** `users.access` (string, `internal` / `vendor` / `pending`, indexed, DB default `pending`), `users.access_set_by_user_id` (nullable FK to `users`, null on delete) and `users.access_set_at` (nullable timestamp). Enum `App\Enums\UserAccess`. Migration `2026_11_24_150000_add_access_to_users_table` adds them and backfills (vendor_id set → `vendor`, others → `internal`); backfilled rows have no who/when.
2. **Invariant in one place:** a `saving` hook in `User::booted()` sets `vendor` whenever `vendor_id` is set, and turns a `vendor` user who lost their `vendor_id` into `pending`. A `new User` defaults to `pending` (fail closed). Controllers never set `access` directly; they call `User::linkToVendor($vendor, $by)` or `User::markInternal($by)`.
3. **Who/when** is recorded on every admin access change (create, link, unlink, set internal), not only on leaving pending. `pss:setup-first-admin` and the seeder record no admin (`access_set_by_user_id` null).
4. **Unique `employee_id`:** migration `2026_11_24_150001_make_users_employee_id_unique` replaces the plain index with a unique one. Several null `employee_id`s are still allowed. **Deployment:** the migration fails if the production table already has duplicate `employee_id`s; remove them first. `POST /api/admin/users` checks uniqueness in validation; two simultaneous creates of the same employee would hit the constraint and get a 500 (rare, not handled further).
5. **Pending block:** middleware `App\Http\Middleware\BlockPendingUsers` on the whole `auth:api` group, so any route added there later is blocked by default. It exempts the route names `auth.me` and `auth.logout` (`auth.refresh` is outside `auth:api`). Message: "Your account is waiting for an administrator to set up your access." A test asserts every `auth:api` route carries it. For the admin routes, `can:manage-roles` may answer first (also 403).
6. **`isInternalEmployee()` now also requires `access = internal`**, so a pending user can never act as EIC/approver even outside HTTP (defence in depth; the middleware already blocks them).
7. **Login:** `AuthController::login` no longer takes `MasterDataDirectory`. Password first (401, unchanged message); then lookup by `employee_id`; none → 403 "Your account isn't set up in PSS yet. Ask an administrator.". Login never changes the user.
8. **`POST /api/admin/users`** (`StoreUserRequest`): `employee_id` required string, unique, must be known to `MasterDataDirectory::findEmployee`; `access` must be `internal`, `prohibits` `vendor_id`; one of the two required. `null` for either counts as absent. A vendor create saves the user and calls `linkToVendor` inside one transaction, so the EIC refusal (422 on `vendor_id`) rolls everything back. Response 201 `{data: {id, employee_id, name, roles, vendor_id, access}}`. No password field.
9. **`PUT /api/admin/users/{user}/access`** (`UpdateUserAccessRequest`): `access` required, only `internal` (422 otherwise, including `vendor`/`pending`). PO defaults applied: internal user → no-op 200 (who/when unchanged); vendor-linked user → 422 on `access` "...unlink the Vendor first...".
10. **`PUT .../vendor` with `null`** on a user without a Vendor (internal or pending) is a no-op 200 (PO default 1). On a vendor-linked user it clears `vendor_id` and sets `pending`.
11. **Roles for pending users:** checked in `GrantUserRoleRequest::after()` (admin API only), 422 on `role` with the issue's message. `User::grantRole` itself doesn't refuse, so `pss:setup-first-admin` still works on a pending user. A pending user keeps roles they already had (PO default 3).
12. **`GET /api/admin/users?access=`** accepts `internal`, `vendor`, `pending`; anything else is 422 on `access`. `UserResource` and `CurrentUserResource` (`me`) both add `access`.
13. **First admin (Q17):** a user the command creates is `internal`. An existing user keeps their access state; if they are pending, the command still grants the role, succeeds, and prints a warning that they can't use PSS until classified (no behaviour change otherwise).
14. **Seeder/factory:** `DemoAccountsSeeder` makes EMP-9001/9002/1001 `internal` and EMP-8001 `vendor`. `UserFactory` defaults to `internal`; new `pending()` state; `vendorStaff()` gets `vendor` through the model hook.
15. **Test fixtures changed for the unique constraint:** new helpers in `tests/TestCase.php`: `employeeUser($id)` (the existing user with that id, or a new internal one) and `vendorStaffWithEmployeeId($id, $vendor)` (that same person, vendor-linked). Fixtures that created a second user with an existing `employee_id` now use them: `ECallCancellationApiTest`, `ECallReviewApiTest`, `WaitingListECallApiTest`, `WaitingListWorkOrderApiTest`, `WorkOrderApiTest`, `WorkOrderBillingEndorsementApiTest`, `WorkOrderEndorsementApiTest`, `WorkOrderEndorsementHardeningTest`. "Vendor staff sharing an approver's employee id" cases are now "that approver, linked to a Vendor" and still get 403. Three cases tested a state that can no longer exist (a second account with the Requestor's employee id) and were removed: `WorkOrderApiTest` "a user with the requestors employee id but another account", `WorkOrderEndorsementApiTest` "another account with the requestors employee id", and `ECallCancellationApiTest` "site vendor staff with the requestor employee id" (cancellation checks the Requestor by account, so the same person linked to a Vendor would be allowed; that is existing behaviour, not changed here). In `WorkOrderEndorsementApiTest::test_the_model_rechecks_the_endorser_inside_the_lock` the non-creator submitter is now `P-OTHER` instead of a second `P-REQ` account. `UserActsAsEmployeeTest` gained a pending case.
16. **Angular:** `CurrentUser.access` added. `requireRoles` (so `authGuard`, `adminGuard`, `reportsGuard`) sends a pending user to `/pending`; `pendingGuard` lets only a signed-in pending user see `PendingPage` (name + Log out). The top bar hides the menu for a pending user (name and Log out stay). The login screen shows the backend `message` on 403 (fallback text if missing); nothing is stored. No new npm package.
17. **Docs:** `backend/README.md` sign-in notes, new item 5 (admin-added users, 401/403, pending). Admin-added users have no local password, so they can't sign in with the local checker (expected until issue 44).
