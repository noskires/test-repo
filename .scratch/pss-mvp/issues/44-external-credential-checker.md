# 44 — Real external-system credential checker

Status: needs-info
Blocked by: 04, 46
Type: task

## What to build

Issue 04 adds a `CredentialChecker` port and a local version that works only in `local` and `testing`. This issue adds the real version, which checks the user's credentials against the existing external system, so PSS can sign people in outside local development (human, 2026-11-24, Q1, Q8, Q12).

## Open questions (for the human)

1. Which endpoint of the existing system checks a user's credentials (URL, method, request and response shape)? Is it part of the same API as the master data (issue 03)?
2. What does the user type to sign in: employee ID, username or email? If it's not the employee ID, how does PSS get the `employee_id` from the response?
3. How does PSS authenticate to that endpoint (API key, client credentials, none)?
4. What do the error responses look like (wrong password, locked or disabled account, system down)? Should "system down" show a different message than "wrong password"?

## Acceptance criteria (draft)

- [ ] An `ExternalCredentialChecker` implements the `CredentialChecker` port from issue 04 and is bound in every environment other than `local` and `testing`.
- [ ] Feature tests use `Http::fake()` for valid credentials, wrong credentials and the external system being down. Login returns a token, 401, and an error (status to decide) respectively.
- [ ] No new package (Laravel's HTTP client is enough).

## Decisions (human, 2026-11-24)

From the issue 45/46 grilling:
- Users are no longer created on sign-in (issue 46, Q13). A System Administrator adds each user and picks internal or a Vendor (Q14), so **this issue doesn't need to classify users** (vendor staff vs internal) from the external system's response. Superseded by 46.
- Sign-in order is fixed by issue 46 (Q16): the checker runs first; a refusal is 401 whether or not the person is registered; a correct password from someone not in PSS is 403. This issue only replaces the checker and must keep that order. Questions 1 to 4 above are still open.

## Comments

- 2026-11-24 (product-owner): now also blocked by 46, which changes the login flow this checker plugs into.
