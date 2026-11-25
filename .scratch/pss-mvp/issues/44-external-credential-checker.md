# 44 — Email and password sign-in for every environment

Status: ready-for-agent
Blocked by: 04, 46
Type: task

## What to build

**Rewritten 2026-11-24 from the human's decisions.** Before this rewrite, the issue was "Real external-system credential checker". There is no external login. Users sign in to the PSS backend itself with **email and password**, checked against the local `users` table, in **every** environment. ADR 0004 is amended (docs/adr/0004-jwt-login-and-pss-roles.md, "Amendment (2026-11-24)").

## Decisions (human, 2026-11-24)

- The login endpoint is the PSS backend (`localhost:8001` in dev). Users sign in with **email + password** against the local `users` table, in every environment for now. The production block on `LocalCredentialChecker` is lifted.
- **Email is required and unique.**
- The issue 46 sign-in order is kept: the credential check runs first, and a wrong email or password is 401 whether or not the person exists. The pending and not-set-up rules from 46 still apply after a correct password.
- An admin creating a user enters the email and a **temporary password**. The user **must change it on first sign-in**.
- Users can **change their own password**. An admin **resets** a forgotten one (by setting a new temporary password).
- No email reset flow and no mail server.

From the issue 45/46 grilling (still valid): users are never created on sign-in. A System Administrator adds each one.

## Acceptance criteria

**Backend**
- [x] `POST /api/auth/login` takes `email` + `password` (not `employee_id`). Missing or invalid email: 422. Wrong email or password: 401 with one generic message. The issue 46 order and responses stay the same otherwise. Update the existing auth tests.
- [x] `LocalCredentialChecker` works in every environment. `LocalCredentialCheckerNotAllowed` and the environment guard are removed, and their tests are replaced by a test that the checker resolves in `production`.
- [x] `users.email` is required and unique (migration). An existing row without an email can't sign in; it isn't silently given one.
- [x] Admin user creation (issue 46 endpoint) requires `email` (unique, 422 on a duplicate) and `temporary_password` (Laravel's default `Password` rule). The password is stored hashed, and the user is marked `must_change_password`.
- [x] While `must_change_password` is set, the login response says so (e.g. `must_change_password: true`), and every API call except change-password, me, refresh and logout returns 403 with "Change your temporary password first."
- [x] `POST /api/auth/password`: the signed-in user gives `current_password` and `password` + `password_confirmation`. A wrong current password is 422. A new password equal to the current one is 422. Success clears `must_change_password`.
- [x] Admin reset: `POST /api/admin/users/{user}/password` (behind the System Administrator gate) sets a new temporary password and `must_change_password`. Non-admins get 403. Feature tests for each case.
- [x] `pss:setup-first-admin` also sets the first admin's email and a temporary password (as options or env vars; the dev-lead decides and documents it), with `must_change_password`.
- [x] `DemoAccountsSeeder` gives each demo account an email. `backend/README.md` lists them.

**Frontend (Angular)**
- [x] The login screen asks for email and password. Update the spec tests.
- [x] After a login with `must_change_password`, the app shows a change-password screen and nothing else until it succeeds.
- [x] Signed-in users can reach a change-password screen from the menu.
- [x] If the Angular app already has an admin user form (issue 46), add the email and temporary-password fields and a "Reset password" action. If it doesn't, the API is enough.

**Added scope (human, 2026-11-25)**
- [x] "Generate sample accounts for the users (admin, eic, supervisor, div head, center head, sector head, vendors) so we can test some of the finished features." In `local`, `php artisan migrate:fresh --seed` gives one sign-in-able account per role (email + `DemoAccountsSeeder::PASSWORD`, `must_change_password` false) on the sample employees: System Administrator (EMP-9001), Management Viewer (EMP-9002), Requestor (EMP-1001), Supervisor / Team Head (EMP-2001), Division Head (EMP-3001), Center Head (EMP-4001), Sector Head (EMP-5001), EIC (EMP-6001, the Site EIC of the sample Sites through `SiteSetting::assignEic`) and Vendor staff (EMP-8001) linked to the demo Vendor, which is a Site Vendor (`SiteSetting::assignVendor`). A second Vendor with its own staff account shows vendor scoping. Readable `.test` emails, idempotent, covered in `DemoAccountsSeederTest`, and listed in `backend/README.md` (email, role, password).

## Comments

### Clarifications (product-owner, 2026-11-24)

- Where things live: `App\Auth\CredentialChecker`, `LocalCredentialChecker`, `LocalCredentialCheckerNotAllowed`, `Api\AuthController` (it currently looks users up by `employee_id`), the binding in `AppServiceProvider`, `SetupFirstAdmin` and `DemoAccountsSeeder`. Angular: `frontend/src/app/pages/login.ts`.
- `employee_id` stays on `users`: it links the user to their imported employee record (issue 03) for the Immediate Head chain. It's just no longer what they type to sign in.
- Deactivated users (issue 47) come after this issue. Don't build deactivation here.
- Record your decisions (column name, message texts, first-admin options) under a dated "Decisions (dev-lead, ...)" heading here.

### Clarifications (product-owner, 2026-11-24, after issue 03 merged)

- Issue 03 changed the master data binding: every environment except `testing` now uses `DatabaseMasterDataDirectory` over the imported tables. `DatabaseSeeder` runs `MasterDataSampleSeeder` (local only) before `DemoAccountsSeeder`, and the sample employee IDs are in `SampleMasterData::EMPLOYEE_IDS`. Keep the demo accounts on those employee IDs when you add their emails.
- `pss:setup-first-admin` still looks up the employee through the port, which in production now reads the imported `employees` table. So run the import before the setup command, and say so in `backend/README.md`.
- No other changes to the scope above.

## Implementation decisions

Dev-lead, 2026-11-25 (finishing the build a previous dev-lead started on 2026-11-24).

1. **Email column:** the existing `users.email` (unique index from the Laravel default migration) stays nullable in the database. "Required" is enforced on every path that creates a user (admin API, first-admin command, demo seeder). A row added before issue 44 keeps no email and can't sign in (the checker never matches a null email) until it gets one. It is never given one silently. New column: `users.must_change_password` (boolean, default false), migration `2026_11_24_221500_add_must_change_password_to_users_table`.
2. **Email normalisation:** emails are stored and compared trimmed and lower-case (`User::normalizeEmail`, a model mutator, plus `prepareForValidation` on the login and add-user requests). So `ADA@Example.test` signs in as, and collides with, `ada@example.test`.
3. **Timing:** an unknown email still costs one bcrypt check (a dummy hash), so response time doesn't reveal who is registered (Q16).
4. **Messages:** login 401 "Invalid email or password."; temporary-password block 403 "Change your temporary password first."; wrong current password 422 "The current password is incorrect."; same password 422 "The new password must be different from your current password."; duplicate email on add-user 422 "This email is already used by another PSS user."
5. **Password rule:** `Password::default()` with no app-wide override, so at least 8 characters, plus max 255. The same rule applies to `temporary_password` (add user, admin reset), the new password in `POST /api/auth/password`, and `PSS_FIRST_ADMIN_TEMPORARY_PASSWORD`.
6. **API shapes:** the login and refresh responses add `must_change_password`. `auth/me`, `POST /api/auth/password` and the admin `UserResource` add `email` and `must_change_password`. `POST /api/auth/password` returns the current user (same shape as `auth/me`). `POST /api/admin/users/{user}/password {temporary_password}` returns the `UserResource`.
7. **Middleware:** `RequirePasswordChange` runs on the whole `auth:api` group before `BlockPendingUsers`, with allowed routes `auth.password.update`, `auth.me` and `auth.logout`. `auth.refresh` sits outside the group. A test fails if any `auth:api` route lacks the middleware. A **pending** user may also change their password (`auth.password.update` added to `BlockPendingUsers::ALLOWED_ROUTES`). When a user is both pending and on a temporary password, the 403 says to change the password first.
8. **Tokens:** changing or resetting a password does not revoke tokens already issued. After a reset, the user's existing tokens keep working until they expire, but the temporary-password block stops them from doing anything except change the password, `me` and logout. Token revocation can come with deactivation (issue 47) if wanted.
9. **First admin (`pss:setup-first-admin`):** env vars `PSS_FIRST_ADMIN_EMAIL` and `PSS_FIRST_ADMIN_TEMPORARY_PASSWORD` (in `config/pss.php` and `.env.example`), plus a `--email=` option that wins over the env var. The temporary password is env-only, never an option, so it stays out of shell history. They are needed only when the user is new or has no email yet. An existing user with an email keeps their email and password, so running the command again never resets a password. It fails (and stores nothing) on a missing, invalid, weak or already-used email or password.
10. **Frontend:** the login form asks for email (`type=email`). After a login with `must_change_password` the app goes to `/change-password`. While the flag is set, the sidebar menu is hidden and every guarded route (including `/pending`) redirects there. `/change-password` is open to any signed-in user, pending ones included. Every signed-in user reaches it from a "Change password" link in the top bar, next to "Log out" (not in the sidebar, so pending users get it too). The Angular admin page is still a placeholder (no user form), so per the last criterion the add-user email/temporary-password fields and "Reset password" action are API-only for now.
11. **Demo accounts (added scope):** emails `admin@`, `viewer@`, `requestor@`, `supervisor@`, `divhead@`, `centerhead@`, `sectorhead@`, `eic@`, `vendor1@`, `vendor2@pss.test`, all with password `password` and `must_change_password` false. The approver accounts (Team Head up to Sector Head) get no PSS role, because the approver chain comes from the master data's Immediate Head links. Added sample employee `EMP-8002` "Wendy Second Vendor Staff" (`SampleMasterData::SECOND_VENDOR_STAFF_EMPLOYEE_ID`), plus constant `SampleMasterData::EIC_EMPLOYEE_ID` for EMP-6001.
12. **Site assignment for the demo (please confirm):** the EIC account is EIC of both sample Sites. The Site Vendors are split instead of putting the demo Vendor on both: "Demo Facilities Services Inc." (vendor1) serves `SITE-MKT`, and the second Vendor "Second Demo Maintenance Corp." (vendor2) serves `SITE-CEB`. So each vendor account sees only its own Site's work, which demonstrates vendor scoping. All changes go through `SiteSetting::assignEic` / `assignVendor`, recorded as changed by the demo admin.
13. **Seeder idempotency:** re-running the seeder matches accounts by employee ID and sets their email, name, password and `must_change_password = false` again, and re-assigns the two Sites' Vendor and EIC (a no-op when unchanged, so no extra history rows). So a re-seed undoes a tester's password change or Site settings change on these demo records. That is acceptable for local demo data. The seeder runs only in `local`, and hashing 10 passwords takes about 4 to 5 seconds.

### QA (qa-44b, 2026-11-25): PASS

- `php artisan test --compact` (backend): 1355 passed, 6338 assertions. `vendor/bin/pint --test`: pass.
- `npx ng test --watch=false` (frontend): 9 files, 85 tests passed.
- `migrate:fresh --seed` on a scratch SQLite DB seeded cleanly. All 10 demo accounts returned 200 on `POST /api/auth/login` with the demo password, and a wrong password returned 401.
- Every acceptance criterion maps to a real test. No skipped or empty tests.
