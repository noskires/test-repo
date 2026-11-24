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
- [ ] `POST /api/auth/login` takes `email` + `password` (not `employee_id`). Missing or invalid email: 422. Wrong email or password: 401 with one generic message. The issue 46 order and responses stay the same otherwise. Update the existing auth tests.
- [ ] `LocalCredentialChecker` works in every environment. `LocalCredentialCheckerNotAllowed` and the environment guard are removed, and their tests are replaced by a test that the checker resolves in `production`.
- [ ] `users.email` is required and unique (migration). An existing row without an email can't sign in; it isn't silently given one.
- [ ] Admin user creation (issue 46 endpoint) requires `email` (unique, 422 on a duplicate) and `temporary_password` (Laravel's default `Password` rule). The password is stored hashed, and the user is marked `must_change_password`.
- [ ] While `must_change_password` is set, the login response says so (e.g. `must_change_password: true`), and every API call except change-password, me, refresh and logout returns 403 with "Change your temporary password first."
- [ ] `POST /api/auth/password`: the signed-in user gives `current_password` and `password` + `password_confirmation`. A wrong current password is 422. A new password equal to the current one is 422. Success clears `must_change_password`.
- [ ] Admin reset: `POST /api/admin/users/{user}/password` (behind the System Administrator gate) sets a new temporary password and `must_change_password`. Non-admins get 403. Feature tests for each case.
- [ ] `pss:setup-first-admin` also sets the first admin's email and a temporary password (as options or env vars; the dev-lead decides and documents it), with `must_change_password`.
- [ ] `DemoAccountsSeeder` gives each demo account an email. `backend/README.md` lists them.

**Frontend (Angular)**
- [ ] The login screen asks for email and password. Update the spec tests.
- [ ] After a login with `must_change_password`, the app shows a change-password screen and nothing else until it succeeds.
- [ ] Signed-in users can reach a change-password screen from the menu.
- [ ] If the Angular app already has an admin user form (issue 46), add the email and temporary-password fields and a "Reset password" action. If it doesn't, the API is enough.

## Comments

### Clarifications (product-owner, 2026-11-24)

- Where things live: `App\Auth\CredentialChecker`, `LocalCredentialChecker`, `LocalCredentialCheckerNotAllowed`, `Api\AuthController` (it currently looks users up by `employee_id`), the binding in `AppServiceProvider`, `SetupFirstAdmin` and `DemoAccountsSeeder`. Angular: `frontend/src/app/pages/login.ts`.
- `employee_id` stays on `users`: it links the user to their imported employee record (issue 03) for the Immediate Head chain. It's just no longer what they type to sign in.
- Deactivated users (issue 47) come after this issue. Don't build deactivation here.
- Record your decisions (column name, message texts, first-admin options) under a dated "Decisions (dev-lead, ...)" heading here.
