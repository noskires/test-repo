# 43 — Angular login screen, token handling and role-aware menu

Status: ready-for-agent
Blocked by: 04
Type: task

## What to build

The frontend half of issue 04 (split, human, 2026-11-24, Q12). A login screen that calls `POST /api/auth/login`, keeps the JWT, attaches it to every API call, refreshes it, and a menu that shows only what the signed-in user can use. It lets the Angular screens for earlier issues start.

## Acceptance criteria

- [ ] A login screen posts `employee_id` and `password` to `POST /api/auth/login`. On success it stores the token and goes to the home page. On 401 it shows an error and stores nothing.
- [ ] The token is kept in `localStorage` (Q10). An HTTP interceptor adds `Authorization: Bearer <token>` to every request to the API.
- [ ] A 401 from the API clears the token and redirects to the login screen. The interceptor first tries `POST /api/auth/refresh` once when the token has expired, and redirects only if that fails.
- [ ] After sign-in the app loads `GET /api/auth/me` and keeps `employee_id`, `name`, `roles` and `vendor_id`.
- [ ] Logout calls `POST /api/auth/logout`, clears the token and goes to the login screen.
- [ ] Route guards: unauthenticated users are sent to login. Admin pages require `system-administrator`. Reports (issue 29) require `system-administrator` or `management-viewer`.
- [ ] The menu shows admin entries only to `system-administrator`, the Reports entry only to `system-administrator` and `management-viewer`, and the dashboard to every signed-in user.
- [ ] Unit tests (the Angular project's existing test runner) cover the interceptor (header added, refresh-then-redirect on 401), the guards and the menu for each role. No new npm package without approval.

## Decisions (human, 2026-11-24)

From the issue 04 grilling: Q2 (JWT, no Sanctum), Q10 (localStorage, interceptor, 401 redirects to login), Q11 (Reports only for System Administrator and Management/Viewer; dashboard for all internal employees), Q12 (this split). See issue 04.

## Comments
