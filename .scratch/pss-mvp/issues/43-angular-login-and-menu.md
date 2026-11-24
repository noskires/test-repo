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

- 2026-11-24 (product-owner), clarifications for dev-lead (issue 04 is merged, PR #43):
  - **Backend contract** (issue 04, see its "Implementation decisions"): `POST /api/auth/login` with `employee_id` + `password` returns `access_token`, `token_type: "bearer"`, `expires_in`; `POST /api/auth/refresh`, `POST /api/auth/logout`, `GET /api/auth/me` (`employee_id`, `name`, `roles`, `vendor_id`). Role keys: `system-administrator`, `management-viewer`.
  - **The frontend is a bare scaffold** (`frontend/src/app/app.routes.ts` is empty). Build the login page, a simple home page (signed-in user's name plus the menu) and placeholder pages for the Admin and Reports routes so the guards and menu have targets. The real admin and report screens come with later issues.
  - **SSR is on** (`outputMode: "server"`). `localStorage` doesn't exist on the server: read and write the token only in the browser (e.g. check `isPlatformBrowser`), and don't let the server render crash or redirect wrongly.
  - **API calls use relative `/api/...` URLs.** `frontend/proxy.container.json` already proxies `/api` to the backend, so no CORS change in Laravel is needed.
  - **Tests:** the project runs `ng test` with Vitest (already installed). No new npm package.
  - **Demo accounts** for a manual check are in `backend/README.md` ("PSS: sign-in setup").
  - The open question on vendor-linked System Administrators (issue 45) doesn't affect this issue: the menu just follows `roles`.

## Implementation decisions

1. **Files:** `frontend/src/app/auth/` (`auth.service.ts`, `auth.interceptor.ts`, `auth.guards.ts`), `frontend/src/app/menu/menu.ts`, `frontend/src/app/pages/` (`login.ts`, `placeholder-pages.ts` with Home, Admin and Reports placeholders), plus `app.ts/html/css`, `app.config.ts`, `app.routes.ts`, `app.routes.server.ts`. The Angular scaffold's welcome page was removed. No npm package added (`npm ci` from the existing lock file only).
2. **Token storage:** localStorage key `pss.access_token`. Only the token is stored; `expires_in` is ignored. The user (`employee_id`, `name`, `roles`, `vendor_id`) is kept in memory (a signal) and reloaded from `GET /api/auth/me` after a page reload, by the route guard.
3. **Interceptor scope:** the Bearer header is added only to same-origin URLs starting with `/api/`. Absolute URLs (other hosts) never get the token.
4. **Refresh rule:** the interceptor does not decode the JWT. Any 401 on an API call that was sent with a token triggers one `POST /api/auth/refresh` (the backend accepts an expired token there, issue 04 decision 3), then the request is retried once with the new token. If the refresh fails, or the retry gets 401 again, the token and user are cleared and the app goes to `/login`. A 401 from `login`, `refresh` or `logout` itself never triggers a refresh. Requests that fail together share one refresh call. Non-401 errors (403, 422, 500) pass through untouched.
5. **Routes:** `/login`, `/` (Dashboard/home, any signed-in user), `/reports` (`system-administrator` or `management-viewer`), `/admin` (`system-administrator`); unknown paths redirect to `/`. A signed-in user who lacks the role is sent to `/` (the dashboard), not to an error page. An unauthenticated user is sent to `/login` (no "return to" URL is kept; after sign-in the user always lands on `/`).
6. **Menu:** shown in a top bar on every page once signed in, together with the user's name and a "Log out" button. Order: Dashboard, Reports, Admin. The menu and the guards use the same role lists. Vendor staff get only the Dashboard unless they hold a role (the menu just follows `roles`, see issue 45).
7. **Logout:** always clears the local session and goes to `/login`, even if `POST /api/auth/logout` fails (e.g. the token was already invalid).
8. **Login screen errors:** 401 shows the backend's `message` ("Invalid employee ID or password."); 429 shows "Too many sign-in attempts. Wait a minute and try again."; anything else shows "Sign-in failed. Try again later." Empty fields are refused in the browser without calling the API. The password field is cleared after a failure. If `/api/auth/me` fails right after a successful login, the token is cleared too, so nothing is stored.
9. **SSR:** `app.routes.server.ts` now prerenders only `/login` and renders every other route in the browser (`RenderMode.Client`), because those pages depend on the token in localStorage. The server never reads localStorage; as an extra safety net the guards return `true` on the server instead of redirecting. `provideHttpClient(withFetch(), ...)` was added to the app config (fetch is recommended for SSR).
10. **Manual check:** `docker compose up` (the `frontend` service runs `ng serve --proxy-config proxy.container.json`, forwarding `/api` to `http://backend:8000`), then open `http://localhost:4200`. A plain `npm start` outside Docker has no proxy, so `/api` calls would not reach Laravel. Demo accounts: see `backend/README.md`.
11. **Build and tests:** `cd frontend && npx ng test --watch=false` (Vitest, 47 tests in 6 files). `npx ng build` succeeds ("Prerendered 1 static route", `/login`), but on this Windows machine the CLI process stays alive after printing "Output location"; the output in `frontend/dist/` is complete. Not investigated further (not caused by this change's code paths as far as known; the scaffold's build was not timed for comparison). Files were formatted with the project's existing Prettier config. `frontend/node_modules` was empty and was installed with `npm ci` (lock file unchanged).
