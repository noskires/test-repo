# 48 — Restyle the Angular app to the PSS design (lucide-angular)

Status: ready-for-agent
Blocked by: 46
Type: task

## What to build

The human wants the Angular app redesigned after issue 46, using `lucide-angular` (human, 2026-11-24). The reference is the "PSS Mock Screens" design canvas, saved in the repo:

- `docs/design/mock-screens/pss-design.css`: the shared stylesheet with the design tokens (light, dark, and dark following the system setting).
- `docs/design/mock-screens/project/*.dc.html`: 10 mock screens. Desktop: Main (Dashboard), Equipment, Checkups, ECall, WorkOrder. Mobile: one of each (`Mobile*.dc.html`; the Dashboard one is `MobileOverviewDS`).

This is a **restyle only**. It applies the design to the screens that already exist: the shell/menu, the login screen, the pending "waiting for an administrator" page from issue 46, and the Home/Dashboard, Admin and Reports placeholder pages (issue 43). It adds no features, no data, no new routes and no sample content from the mocks. Every role rule, guard, redirect and message stays exactly as it is.

Design summary (from the canvas note):
- Inter for text, Geist Mono for IDs and amounts.
- Slate neutrals, blue primary `#2563EB`, orange accent for the one main action per screen.
- 12px-radius cards with soft shadows, bordered status pills, uppercase table headers.
- Lucide icons at 16px.
- Light and dark follow the system setting. Phones use the mobile style.
- Layout: a left sidebar grouped **Monitor / Repairs / Admin**, and each page has a page head (title) and a sub line under it.

Out of scope: building the Equipment, Checkups, E-Call and Work Order screens shown in the mocks. Those come with their own issues and use the mocks as reference only.

## Acceptance criteria

Package
- [x] `lucide-angular` is the only package added (approved by the human, 2026-11-24). Choose the release that supports the project's Angular version (`^21.2`) and record the exact version in the issue. No other npm package.

Design tokens
- [x] The app has one global stylesheet with the design tokens from `docs/design/mock-screens/pss-design.css`: colour, radius, shadow and status-pill tokens, with the dark values. Components use the tokens (CSS custom properties), not hard-coded colours.
- [x] Dark mode follows the system setting (`prefers-color-scheme`). No theme switch is added (that would be a new feature).
- [x] Inter is the text font and Geist Mono is used for IDs (e.g. the employee ID). See "Decisions to confirm" 1 for how the fonts load.

Shell and menu
- [x] Signed-in pages use the design's layout: a left sidebar with the menu, and a main area with a page head (title) and a sub line.
- [x] The sidebar groups entries under the mock's headings. With today's entries: **Monitor**: Dashboard. **Admin**: Admin, Reports (the mock puts Reports under Admin). A group with no visible entries for the user, such as **Repairs** today, isn't shown.
- [x] Each menu entry has a lucide icon at 16px. The active route is highlighted with the sidebar-active tokens.
- [x] Which entries each role sees is unchanged (the existing menu tests from issue 43 keep passing, updated only for markup).
- [x] The user's name and the Log out button stay visible on every signed-in page.
- [x] On narrow screens (phone width) the layout follows the mobile mocks: the sidebar isn't shown permanently, the menu is still reachable, and there's no horizontal page scroll. Check at 375px wide.

Screens
- [x] The login screen, the pending page (issue 46) and the Home/Dashboard, Admin and Reports placeholders use the tokens, card style and page head. Their text, messages and behaviour are unchanged. The main action on each screen (e.g. "Sign in") uses the orange accent.

Tests
- [x] The existing Angular unit tests pass, changed only where markup selectors moved. Add a unit test that the menu renders a group heading only when the group has a visible entry (e.g. a Management / Viewer sees Monitor and Admin with Reports only; an ordinary employee doesn't see Admin).
- [x] The backend test suite is untouched.

## Decisions (human, 2026-11-24)

- Redesign the Angular app after issue 46, using `lucide-angular` ("redesign it later after this issue using the lucide angular"). The package is approved because the human named it. No other new package without asking.
- Reference: the "PSS Mock Screens" design canvas (saved under `docs/design/mock-screens/`).

## Comments

- 2026-11-24 (product-owner), clarifications for dev-lead:
  - **Read first:** issue 43's "Implementation decisions" (file layout, routes, guards, menu, SSR) and issue 46's Angular criteria (pending page, 403 login message). Then `docs/design/mock-screens/pss-design.css` and `project/Main.dc.html` / `MobileOverviewDS.dc.html` for the shell.
  - **The mocks don't run standalone.** They reference a `support.js` from the design tool that isn't in the repo. Read them as HTML/CSS for structure and class names; don't try to serve them or copy their scripts. Their shared stylesheet **is** in the repo: every `project/*.dc.html` links `../pss-design.css` (team lead, 2026-11-24). Build the global token stylesheet from `docs/design/mock-screens/pss-design.css`; it is the source of the design tokens, light and dark values included.
  - **Copy the tokens, not the whole file blindly.** `pss-design.css` also has the mock-only component classes. Bring over what the existing screens use (tokens, base type, card, button, pill, sidebar, page head, table header style if a table appears). Keep the dark-mode selectors (`html[data-theme="dark"]` and the `prefers-color-scheme` fallback) as they are, so a theme switch can be added later without restyling.
  - **No sample content.** The mocks show example Sites, counts, charts and tables. Don't add any of them to the placeholders. The page heads use each page's existing title, with a short plain sub line.
  - **Icons:** pick lucide icons that match the mocks' menu (e.g. a dashboard/gauge icon for Dashboard, a shield or settings icon for Admin, a chart or file icon for Reports). Import only the icons used (tree-shaken), not the whole set.
  - **SSR:** the app prerenders `/login` (issue 43 decision 9). Make sure the lucide icons and fonts don't break the prerender (`ng build` must pass).
  - **Ship:** the redesign changes only `frontend/`. The design reference files (`docs/design/mock-screens/**`) ship with this issue.
  - **Decisions to confirm** (product-owner defaults; put them in the PR's "Decisions to confirm"):
    1. Fonts load from Google Fonts with a `<link>` in `index.html` (the same URL the mocks use, no npm package), falling back to the system font stack. If PSS must work without internet access, the human may prefer self-hosted font files instead.
    2. Reports sits in the Admin group, as in the mock.
    3. No theme switch: dark follows the system setting only.
- 2026-11-24 (product-owner), re-checked after 46 (PR #45) and 45 (PR #46) merged: the pending page is `PendingPage` behind `pendingGuard` at `/pending`. For a pending user the top bar hides the menu but keeps the name and Log out (issue 46 decision 16). Keep that: a pending user sees the new shell **without the sidebar menu**, and doesn't see empty group headings either. The login screen's 403 message from 46 must stay. Issue 45 changed nothing in the frontend.
- 2026-11-24 (product-owner): mock screens with no Angular screen yet (Equipment, Checkups, E-Call, Work Order, the full Dashboard) are reference for the issues that build those screens. When such an issue is written or picked, add `docs/design/mock-screens/project/<Screen>.dc.html` to its clarifications.

- 2026-11-24 (qa-48b): QA PASS. `npx ng test --watch=false` = 8 files, 69 tests passed; `npx ng build` passed (prerendered 1 static route). Backend untouched. Every criterion maps to a real test except the 375px no-horizontal-scroll check, done by hand (no unit test).

## Implementation decisions

1. **Package:** human approved `@lucide/angular` (2026-11-24) because `lucide-angular` is deprecated (npm: "Package deprecated. Please use @lucide/angular instead."). `@lucide/angular@1.71.0` is pinned exact in `frontend/package.json` (peer `@angular/core >=17`, works with `^21.2`; standalone, signal-based components). It is the only new dependency; `lucide-angular` is gone from `package.json`, the lock file and the code. Version choice: 1.79.0 (latest) was published on 2026-11-24, the same day, so I pinned 1.71.0 (2026-11-10), the newest release at least two weeks old. Moving to a newer 1.x later is a one-line change.
2. **Lock file:** edited to add only the `@lucide/angular` entry (+ root dependency) to the lock file from `main`. `npm install` with the local npm (10.9.3) also stripped the `libc` fields from ~40 unrelated entries, so that rewrite was discarded. Its only dependency is `tslib`, already in the tree.
3. **Icons:** imported one by one as standalone components, so only these icons are bundled (checked in the production bundle). Shell: `<svg lucideBuildingComplex>` for the brand tile (the mock's building-2 icon, renamed `building-complex` in Lucide 1.x) and `<svg lucideLogOut>` on Log out. Menu: each `MenuItem.icon` is an icon component (`LucideLayoutDashboard` for Dashboard, `LucideShieldCheck` for Admin, `LucideChartColumn` for Reports) rendered with `<svg [lucideIcon]="item.icon" size="16">` (`LucideDynamicIcon`). The icons get class `lucide` and `aria-hidden="true"`.
4. **Tokens:** `frontend/src/styles.css` holds the token blocks from `docs/design/mock-screens/pss-design.css` as they are (light, `html[data-theme="dark"]`, and the `prefers-color-scheme` fallback), plus the base type, card (`.panel`), buttons (`.btn`, `.ghost`, `.accent`, `.sm`), inputs/fields, page head and focus rules. Added beside them: the two mobile dock tokens from the mobile mocks (`--m-dock`, `--m-dock-shadow`, light and dark) and `color-scheme` so native controls follow the theme. Mock-only classes (tables, chips, tabs, banners, steps, charts) were not copied; the status-pill tokens are there for later. Inputs: the mock's input rule was extended to `input[type=password]` and inputs without a `type`.
5. **Shell:** signed-in pages use `.app` = left `aside.sidebar` (brand "PSS / Passive Support Services" + the menu) and a content column with a top bar (name, employee ID in Geist Mono, Log out) and `main.main` (max 80rem, 24px padding). Shell styles live in the global stylesheet (keeps component styles under the 4 kB budget). Signed-out pages (login) render in a centred `main` with no shell. The two layouts use separate `<router-outlet>`s in an `@if/@else`.
6. **Menu:** `MENU_GROUPS = Monitor, Repairs, Admin`; each `MenuItem` now has a `group` and an `icon`. `menuGroupsFor(roles)` returns only groups with a visible entry; `menuFor` is the flat list in display order. Role rules unchanged. **Order changed** to follow the issue: Admin group = Admin, then Reports (flat order is now Dashboard, Admin, Reports; it was Dashboard, Reports, Admin). Active link: class `on` (sidebar-active tokens) plus `aria-current="page"`.
7. **Pending user:** the shell shows the brand, name, ID and Log out, but no `<app-menu>` (so no nav and no group headings), as in issue 46 decision 16.
8. **Phones (≤ 640px):** the sidebar isn't shown; the same menu becomes a fixed bottom dock (icon above label, headings hidden), as in the mobile mocks. The top bar shows a compact brand, the name (truncated with an ellipsis) and Log out; the employee ID is hidden there. `main` gets bottom padding so the dock doesn't cover content. Checked at 375px: no horizontal scroll (scrollWidth 375) on login and signed-in pages; also checked desktop light and dark (system setting).
9. **Fonts (PO decision to confirm 1):** Google Fonts `<link>` in `index.html` (same URL as the mocks, plus preconnects), system font fallback. Angular's build-time font inlining is turned off in the production config (`optimization.fonts: false` in `angular.json`, other optimisation options left at their defaults) so `ng build` doesn't need internet; the browser loads the fonts at run time.
10. **Screens:** login = centred card with page head ("Sign in to PSS" + sub line "Use your employee ID and password."), employee ID input in Geist Mono, `Sign in` is `.btn.accent` (orange), errors use `.err`. Pending = page head (sub "Your access isn't set up yet.") + card; `Log out` is the orange main action. Home/Admin/Reports = page head with their existing title + a short sub line ("Dashboard", "Administration", "Reports for management") + one card with the existing text. All messages, guards and redirects unchanged. One text change: Home said "Use the menu above..."; the menu is no longer above, so it now says "Use the menu to open the pages your role allows."
11. **No theme switch** (PO decision 3); the `data-theme` selectors are kept so one can be added later.
12. **Tests:** `cd frontend && npx ng test --watch=false` → 8 files, 69 tests (re-run after the switch to `@lucide/angular`). New: `menuGroupsFor` cases (employee: Monitor only; management viewer: Monitor + Admin with Reports only; admin: Admin, Reports; Repairs never shown), menu headings per role, 16px lucide icons (class `lucide`, `aria-hidden`), shell layout and employee ID in mono, pending user has no `.navsec`, login/pending accent button and card, new `placeholder-pages.spec.ts` (page head + sub line + card). `npx ng build` passes ("Prerendered 1 static route"). As in issue 43 decision 11, the `ng` process can stay alive after printing "Output location" on this Windows machine; the output is complete. Backend: no file changed.
13. **Machine note:** stale `ng build` processes from earlier issues and a running `ng serve` hold `node_modules/@esbuild/win32-x64/esbuild.exe`, so `npm ci` fails with EPERM on this machine until they stop; `npm install` worked.
