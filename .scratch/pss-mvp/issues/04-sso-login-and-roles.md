# 04 — Login (JWT) and PSS roles (backend)

Status: done
Merged: PR #43 (ac25f69), 2026-11-24
Blocked by: none
Type: task

## What to build

Backend sign-in for everyone (employees **and** vendor staff), the PSS role store and the admin API that manages it. It replaces the placeholder Gates that earlier issues test against. See ADR 0004 (`docs/adr/0004-jwt-login-and-pss-roles.md`).

- **JWT auth** with `tymon/jwt-auth` (approved by the human, 2026-11-24): login, refresh, logout, me.
- A swappable **credential checker** port (like `MasterDataDirectory`). This issue builds only the **local** version (hashed passwords on `users`, seeded demo accounts), allowed only in `local` and `testing`. The real external-system checker is issue 44.
- A PSS `User` record is created on first sign-in. The first System Administrator comes from `.env` via an artisan setup command.
- **PSS Roles** stored in PSS: `system-administrator` and `management-viewer`. Other PRD roles are not stored: Requestor = anyone, EIC = Site settings, Vendor = `vendor_id`, approvers = the Immediate Head chain.
- The five `manage-*` gates and a new `manage-roles` gate resolve from real roles.
- An admin API to grant/revoke roles and to link a user to a Vendor.

Out of scope: the Angular login screen and menu (issue 43), the real external credential checker (issue 44), the `view-reports` gate (issue 29).

PRD roles (Module 9): System Administrator, Requestor, Engineer-in-Charge, Vendor, Supervisor / Team Head, Division Head, Center Head, Sector Head, Management / Viewer.

## Acceptance criteria

Auth endpoints (JWT)
- [x] `tymon/jwt-auth` is the only package added. The `api` guard uses the `jwt` driver and the routes in `routes/api.php` authenticate with it. Existing feature tests that use `actingAs` keep passing.
- [x] `POST /api/auth/login` with `employee_id` and `password` returns `access_token`, `token_type: "bearer"` and `expires_in` (seconds) when the credential checker accepts them. Wrong credentials give 401 and no token. Missing fields give 422.
- [x] The access token lives **60 minutes** (`JWT_TTL=60`): a request with an expired token gets 401 (test with time travel).
- [x] `POST /api/auth/refresh` with a valid or expired token inside the **14-day** refresh window (`JWT_REFRESH_TTL=20160`) returns a new token. After the window it returns 401.
- [x] `POST /api/auth/logout` blacklists the token: using the same token afterwards gives 401.
- [x] `GET /api/auth/me` returns `employee_id`, `name`, `roles` (array of role keys) and `vendor_id` (null for internal employees). Without a token it returns 401.

Credential checker
- [x] A `CredentialChecker` interface takes `employee_id` and `password` and says whether they are valid. Login uses only this interface, and tests can bind a fake.
- [x] `LocalCredentialChecker` checks the password against the hashed `users.password`. It can be resolved only when the app environment is `local` or `testing`. In any other environment resolving it throws a clear exception (test: set the environment to `production` and assert the exception).
- [x] A local seeder creates demo accounts with known passwords: one System Administrator, one Management/Viewer, one ordinary internal employee and one vendor staff user linked to a seeded Vendor. Their `employee_id`s exist in the local dev master data fake (issue 30).

Users created on first sign-in
- [x] When the checker accepts an `employee_id` that has no PSS `User` yet, login creates one with that `employee_id`, the name from `MasterDataDirectory::findEmployee`, no roles and `vendor_id = null`. A second login reuses the same record (no duplicate).
- [x] If the checker accepts the credentials but `MasterDataDirectory` doesn't know the employee, login gives 401 and creates no user (see "Decisions to confirm").
- [x] Sign-in never sets `vendor_id`. Only the admin link action below does (alignment item 4). Test: a first sign-in user has `vendor_id = null`.

First admin
- [x] `php artisan pss:setup-first-admin` reads `PSS_FIRST_ADMIN_EMPLOYEE_ID` (added to `.env.example`), creates that `User` if missing (name from `MasterDataDirectory`) and grants it `system-administrator`. Running it twice changes nothing and still succeeds. If the variable is empty, or the employee isn't in `MasterDataDirectory`, it fails with a clear message and a non-zero exit code.

Roles and gates
- [x] Roles are stored in PSS (e.g. a `user_roles` table) with the two keys `system-administrator` and `management-viewer`. Any other key is refused with 422.
- [x] The gates `manage-vendors`, `manage-site-settings`, `manage-checklists`, `manage-price-lists`, `manage-billing-thresholds` and the new `manage-roles` are defined from real roles: allowed for a `system-administrator`, denied for everyone else (including `management-viewer` and vendor staff). One test per gate for each outcome.

Admin API (behind `manage-roles`; anyone else gets 403)
- [x] `GET /api/admin/users` lists PSS users with `employee_id`, `name`, `roles` and `vendor_id`.
- [x] `POST /api/admin/users/{user}/roles` with `role` grants it (granting a role the user already has is a no-op 200). `DELETE /api/admin/users/{user}/roles/{role}` revokes it.
- [x] Revoking `system-administrator` from its **last holder** is refused with 422 and a clear message, including when an admin removes it from themselves. With two admins, either can remove the other's or their own.
- [x] `PUT /api/admin/users/{user}/vendor` with `vendor_id` links the user to an existing Vendor (unknown id gives 422). `vendor_id: null` unlinks them, and they are an ordinary employee again.
- [x] Linking a user who is the **current EIC of any Site** is refused with 422 and a message saying to replace them as EIC first. Nothing changes (alignment item 4, issue 37).

Visibility and approval history
- [x] Internal employees (`vendor_id = null`) can view PM Tasks, E-Calls and Work Orders at every Site. Vendor staff can view only their own Vendor's records, and get 403 on another Vendor's. (Today's policies already do this; keep a test per record type.)
- [x] One rule for approval history (alignment item 2): anyone who can `view` the record can read its approvals. `GET /api/pm-tasks/{pmTask}/approvals` changes to authorize `view` (today `viewApprovals` limits it to the current EIC and the task's Vendor). E-Call and Work Order history already use `view`. Test: an internal employee who isn't the EIC can read a PM Task's history; another Vendor's staff get 403.

Morph map
- [x] The enforced morph map in `AppServiceProvider` has a `user` alias for `App\Models\User`, with a test that `(new User)->getMorphClass()` is `user` (alignment item 3).

## Decisions (human, 2026-11-24)

Grilling answers. The human said yes to every recommendation unless noted.
- Q1: login uses **JWT via `tymon/jwt-auth`** (package named by the human, so approved; the latest version supports Laravel 13). The real external-system login comes later (issue 44).
- Q2: the Angular app stays signed in with the JWT. No Sanctum.
- Q3: (b) a **PSS admin links each vendor staff account to a Vendor by hand**. Until linked, the user is an ordinary employee. Nobody gets a `vendor_id` any other way.
- Q4: one **System Administrator** role holds all five `manage-*` gates plus role granting.
- Q5: (a) **every internal employee sees every Site**. Vendor staff see only their own Vendor's records. This resolves the open "visible Sites" question.
- Q6: **one rule everywhere**: anyone who can view a record can read its approval history (E-Calls, PM Tasks, Work Orders). Resolves alignment item 2.
- Q7: a PSS user record is **created on first sign-in**. The first System Administrator comes from `.env` `PSS_FIRST_ADMIN_EMPLOYEE_ID` via a setup artisan command.
- Q8: a **swappable credential-checker interface**. The local version checks hashed passwords on `users`, with seeded demo accounts, and is allowed only in local/testing (refused elsewhere). A real external version comes later.
- Q9: access token **60 min**; `POST /api/auth/refresh` within a **14-day** refresh window; logout blacklists the token; `GET /api/auth/me` returns `employee_id`, `name`, `roles` and `vendor_id`.
- Q10: Angular keeps the token in localStorage, an interceptor attaches the Bearer token, and a 401 redirects to login (issue 43).
- Q11: (a) Reports & Export (issue 29) only for System Administrator and Management/Viewer. The dashboard (issue 27) stays open to all internal employees.
- Q12: split into three issues: 04 (this backend issue), 43 (Angular login, token handling, role-aware menu; blocked by 04), 44 (real external credential checker; needs-info).
- Q13: refuse removing the System Administrator role from the **last holder**, including self-removal.
- Q14: refuse linking a user to a Vendor while they're the **current EIC of any Site** (clear error; replace them as EIC first). Keeps alignment item 4.

Later changes (human, 2026-11-24, issue 45/46 grilling; follow-ups 46 and 45 change this merged behaviour):
- **Superseded by issue 46 (Q13, Q16):** users are no longer created on first sign-in (Q7 above); a System Administrator adds them. The product-owner default "an employee unknown to `MasterDataDirectory` gets 401" ("Decisions to confirm" 2) is replaced: a wrong password is 401 whether or not the person is registered, and a correct password from someone not in PSS is 403.
- **Superseded by issue 46 (Q8):** unlinking a Vendor makes the user **pending**, not "an ordinary employee again". `users.employee_id` becomes unique (Q15).
- **Changed by issue 45 (Q2, Q11):** a vendor-linked user can't be granted System Administrator, and a System Administrator can't be linked to a Vendor. Management / Viewer stays allowed (Q3).

Earlier decisions (grilling, 2026-11-22), still valid: vendor staff are in the existing system and log in the same way; **anyone** can file an E-Call; System Administrator and Management/Viewer are granted inside PSS (first admin seeded at install); approver roles come from the Immediate Head chain. The 2026-11-22 "same as the existing system (SSO)" login is now JWT with a swappable checker; the external SSO/credential details move to issue 44.

## Comments

- 2026-11-24 (product-owner), clarifications for dev-lead:
  - **Scope is large.** You may split it into internal steps (auth endpoints + checker → users + first admin → roles + gates → admin API → visibility/history + morph map), but deliver it as one change.
  - **Only one package**: `tymon/jwt-auth`. Pick the release that supports Laravel 13 and record the exact version in the issue. Publish its config, set `JWT_SECRET` in `.env.example` with a placeholder only, and generate the secret in `.env` locally (never commit a real secret). `JWT_TTL=60` and `JWT_REFRESH_TTL=20160` go in `.env.example`. The blacklist needs a cache store, and tests use the array store.
  - **`User` implements `JWTSubject`.** The token subject is the user's primary key. Don't put roles or `vendor_id` in the token claims: `me` and the gates read them from the database, so a revoke or link takes effect on the next request.
  - **Bind the checker** in `AppServiceProvider` next to `registerMasterDataDirectory`, following the same pattern. Issue 44 adds the external one.
  - **Stored roles are only the two above.** Don't add tables for EIC, Vendor or approver roles: those already come from Site settings, `vendor_id` and the Immediate Head chain.
  - **Vendor-linked users with a role** (e.g. an admin granting `management-viewer` to vendor staff) are not restricted by this issue. Visibility still follows `vendor_id`. Issue 29 decides reports for them.
  - **Morph map**: JWT doesn't store a polymorphic `tokenable`, so the `user` alias is about future database notifications. The test in the criteria is enough.
  - **Decisions to confirm** (defaults the product-owner chose; put them in the PR's "Decisions to confirm"):
    1. Login field is `employee_id` (not a username or email). Issue 44 may change it when the external API details arrive.
    2. A sign-in whose employee isn't in `MasterDataDirectory` is refused (401), so every PSS user has a real employee name.
    3. An admin can unlink a vendor staff user (`vendor_id: null`).
    4. Roles can be granted only to users who already exist in PSS (signed in once, or created by the setup command).
- 2026-11-24 (qa-04): QA PASS. `php artisan test --compact` (backend/): 1165 passed (5436 assertions). `vendor/bin/pint --test` passed. Issue-04 test files: 127 passed. Every criterion maps to a real test; only tymon/jwt-auth (and its transitive deps) added.

## Alignment items (history)

Added 2026-11-22 from the issue 11 and 17 reviews. All are now settled.

1. **Vendor staff can never act as EIC: RESOLVED by issue 37** (human, 2026-11-22).
2. **One rule for who can read approval history: RESOLVED** (human, 2026-11-24, Q6). Anyone who can view the record reads its history. Criterion above changes PM Task history to `view`.
3. **Morph map `user` alias: criterion above.**
4. **Internal employees never get a `vendor_id`: criteria above** (only the admin link sets it, and linking a current Site EIC is refused).

## Implementation decisions

1. **Package:** `tymon/jwt-auth` **2.3.1** (`^2.3`, supports Laravel 13). Its transitive deps came with it: `lcobucci/jwt` 4.0.4, `lcobucci/clock` 2.2.0, `stella-maris/clock` 0.1.7. Nothing else was added. Config published to `backend/config/jwt.php` with `ttl`/`refresh_ttl` cast to int. `.env.example` has `JWT_SECRET=change-me` (placeholder), `JWT_TTL=60`, `JWT_REFRESH_TTL=20160`, `PSS_FIRST_ADMIN_EMPLOYEE_ID=`. `phpunit.xml` sets a test-only `JWT_SECRET`, the two TTLs and an empty `PSS_FIRST_ADMIN_EMPLOYEE_ID`, so tests never read the local `.env`. Each deployment must run `php artisan jwt:secret` once.
2. **Guards:** the default guard is now `api` (driver `jwt`, `AUTH_GUARD` env still overrides). All existing routes use `auth:api`, so `actingAs($user)` keeps working. The `web` session guard stays, unused.
3. **Endpoints:** `POST /api/auth/login` (throttled to 10 requests per minute per IP), `POST /api/auth/refresh` (outside `auth:api`, so it accepts an expired token inside the 14-day window), `POST /api/auth/logout` (204, blacklists the token), `GET /api/auth/me` (unwrapped `{employee_id, name, roles, vendor_id}`). Login and refresh return `{access_token, token_type: "bearer", expires_in: 3600}`. Refresh blacklists the old token (package default). Wrong credentials and an employee unknown to `MasterDataDirectory` both give the same 401 message, "Invalid employee ID or password.", so the response doesn't reveal which check failed.
4. **Blacklist storage** uses the default cache store (`CACHE_STORE`, `database` in `.env.example`, `array` in tests). If production uses several app servers, the cache store must be shared (database or Redis), or logout won't take effect everywhere.
5. **Credential checker:** `App\Auth\CredentialChecker::isValid(employeeId, password)`. `LocalCredentialChecker` throws `LocalCredentialCheckerNotAllowed` from its constructor outside `local`/`testing`. Until issue 44, `AppServiceProvider::registerCredentialChecker` binds the local checker in **every** environment. In production, login therefore fails with that clear exception (500) and never silently checks local passwords. Issue 44 replaces that binding.
6. **Users table:** `email` and `password` are now nullable, because first-sign-in users have neither. `employee_id` keeps its plain index, **not a unique constraint**: about 60 existing tests deliberately create two users with the same `employee_id` (e.g. a vendor-staff user who shares the EIC's ID, issue 38). Login and the setup command use `firstOrCreate` keyed by `employee_id`. Two concurrent *first* sign-ins of the same employee could in theory create two rows. Adding the unique constraint later would need those fixtures cleaned up first.
7. **Sign-in never changes an existing user:** it doesn't refresh the stored name from master data, and it doesn't touch `vendor_id`.
8. **Roles:** `App\Enums\PssRole` (`system-administrator`, `management-viewer`) is stored in the new `user_roles` table (`user_id`, `role`, `granted_by_user_id`, unique `user_id`+`role`). `User::grantRole`, `revokeRole` and `roleKeys` return keys sorted alphabetically. The token carries only `sub` (the user id). `me` and the gates read roles from the database on each request.
9. **Gates:** defined in `AppServiceProvider::registerAdminGates` as `hasRole(SystemAdministrator)`. A vendor-linked user who is granted System Administrator would pass them (not restricted here, per the product-owner's note). Existing tests that `Gate::define` these gates still override them.
10. **Admin API** (route middleware `can:manage-roles`; the FormRequests also authorize): `GET /api/admin/users` returns `{data: [{id, employee_id, name, roles, vendor_id}]}` sorted by name, unpaginated. `POST /api/admin/users/{user}/roles {role}` and `DELETE /api/admin/users/{user}/roles/{role}` return the user. Revoking a role the user doesn't have is a no-op 200. An unknown role key in the DELETE path gives 422 (`role` error), not 404. An unknown `{user}` gives 404, and route binding runs before the gate, so a non-admin also gets 404 for an unknown user id. `PUT /api/admin/users/{user}/vendor {vendor_id}`: the key must be present, and `null` unlinks.
11. **Last admin:** revoking `system-administrator` locks all System Administrator rows (`lockForUpdate`) in a transaction and refuses with 422 when the target is the only holder, including self-removal.
12. **Vendor link:** linking refuses with 422 when the user's `employee_id` is the `eic_employee_id` of any `site_settings` row (rows locked during the check). The message names the Sites. Unlinking (`null`) is always allowed. Linking to an **inactive** Vendor is allowed, since the issue only requires the Vendor to exist. A user with no `employee_id` skips the EIC check.
13. **First admin:** `php artisan pss:setup-first-admin` reads `config('pss.first_admin_employee_id')`. On an existing user it adds the role and keeps any other roles. A second run is a no-op success.
14. **Demo accounts:** `Database\Seeders\DemoAccountsSeeder`, password `password` for all: `EMP-9001` Ada Administrator (System Administrator), `EMP-9002` Mario Management Viewer, `EMP-1001` Rina Requestor (ordinary), `EMP-8001` Victor Vendor Staff (linked to the "Demo Facilities Services Inc." Vendor). The three new employees were added to `SampleMasterData`. The seeder is idempotent. `DatabaseSeeder` calls it only in `local`, because it needs the sample master data. Local setup: `php artisan migrate --seed`.
15. **Approval history:** `PmTaskPolicy::viewApprovals` was removed, and `GET /api/pm-tasks/{pmTask}/approvals` now authorizes `view`. Two `PmTaskValidationApiTest` cases changed to match: a replaced EIC and any internal employee now read the history. Only another Vendor's staff get 403.
16. **Morph map:** `'user' => User::class` added.
