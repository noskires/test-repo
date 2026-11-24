# Epic: PSS MVP — progress board

Spec: `spec.md`. Issues: `issues/NN-*.md`. **Start every session here.**

Owners: **releaser** updates this file in each issue's close-out commit (before merge). **product-owner** adds new issues and status changes (needs-info → ready-for-agent).

## Resume here

- Last merged: **48 — Restyle the Angular app to the PSS design (lucide-angular)** (PR #47, 2026-11-24)
- In flight: none
- Ready now (`ready-for-agent`, every blocker done), in number order: **28**.
- Waiting on the human (`needs-info`): 03, 15, 31, 33, 41, 42, 44, 47. The open questions are in each issue file. New: 47 (remove or deactivate users).
- Doc follow-ups: 1 open (frontend/README.md, from issue 48)

## Board

| # | Title | Status | Blocked by | PR |
|---|---|---|---|---|
| 01 | Vendor registry | done | none | #4 |
| 02 | Master data directory port (in-memory fake) | done | none | #5 |
| 03 | HTTP adapter for the external master data API | needs-info | 02 | |
| 04 | Login (JWT) and PSS roles (backend) | done | none | #43 |
| 05 | Assign the Site Vendor and Site EIC | done | 01, 02 | #8 |
| 06 | Site PM Frequency | done | 05 | #12 |
| 07 | PM Checklist templates | done | 02 | #9 |
| 08 | Automatic PM Task generation | done | 05, 06, 07 | #14 |
| 09 | PM Timeliness (Upcoming / Due / Overdue) | done | 08 | #30 |
| 10 | Site Vendor submits the PM Report | done | 07, 08 | #16 |
| 11 | EIC validates a PM Report (Approval Records) | done | 10, 34 | #19 |
| 12 | File an E-Call | done | 05 | #11 |
| 13 | EIC files an E-Call from a PM Task's findings | done | 11, 12 | #32 |
| 14 | Vendor Price List (manual entry) | done | 01 | #10 |
| 15 | Upload a Price List from a file | needs-info | 14 | |
| 16 | Site Vendor submits the FSR and BOQ | done | 12, 14 | #13 |
| 17 | EIC reviews the FSR and BOQ | done | 11, 16 | #21 |
| 18 | Create a Work Order from an approved E-Call | done | 17, 37 | #23 |
| 19 | Work Order endorsement (2 levels) | done | 18 | #24 |
| 20 | Site Vendor implements the Work Order | done | 19, 38 | #26 |
| 21 | EIC validates completed work, sets Billing Amount | done | 20, 38 | #27 |
| 22 | Configure Billing Thresholds | done | none | #6 |
| 23 | Multi-level billing endorsement, close Work Order | done | 21 | #28 |
| 24 | "Waiting for me" list | done | 11 | #34 |
| 25 | Cancelling E-Calls and Work Orders | done | 12, 18 | #40 |
| 26 | Equipment maintenance history | done | 11, 13, 18 | #41 |
| 27 | Dashboard: operational counts | done | 11, 17, 21 | #42 |
| 28 | Define dashboard and report KPIs | ready-for-agent | 09 | |
| 29 | Reports and export | ready-for-agent | 04, 26, 27, 28, 42 | |
| 30 | Local dev master data fake | done | 02 | #7 |
| 31 | Seed the fixed MVP PM Checklists | needs-info | 07 | |
| 32 | Admin "Needs Setup" list | done | 08, 19 | #38 |
| 33 | PM start date for Equipment with no installation date | needs-info | 08, 32 | |
| 34 | PM Report: Service Report + Work Done Date | done | 10 | #17 |
| 35 | Application timezone Asia/Manila | done | none | #18 |
| 36 | Concurrent-safe backend test suite (chore) | done | none | #20 |
| 37 | Vendor staff can never act as EIC (chore) | done | none | #22 |
| 38 | Shared vendor-staff rule, endorsement hardening (chore) | done | none | #25 |
| 39 | "Waiting for me": E-Call items | done | 24 | #36 |
| 40 | "Waiting for me": Work Order items | done | 24 | #37 |
| 41 | Cancel a PM Task | needs-info | 08, 25 | |
| 42 | Dashboard: date range and financial sums | needs-info | 27 | |
| 43 | Angular login screen, token handling, role-aware menu | done | 04 | #44 |
| 44 | Real external-system credential checker | needs-info | 04, 46 | |
| 45 | Vendor-linked users and the System Administrator role | done | 46 | #46 |
| 46 | Admin-created users and pending access | done | none | #45 |
| 47 | Remove or deactivate users | needs-info | 46 | |
| 48 | Restyle the Angular app to the PSS design (lucide-angular) | done | 46 | #47 |

Other merged PRs: #1 PRD v2 docs, #2 project scaffold, #3 unblock answered issues, #15 no-duplicate-lines decision.

## Doc follow-ups

Doc gaps the releaser found at close-out (GLOSSARY, ADR, spec, READMEs). product-owner clears them before picking the next issue. Format: `- [ ] <file>: <what's missing> (found in issue NN)`.

- [x] GLOSSARY.md: PM Timeliness entry lacks the rules (Upcoming = 7 days before due, Due = due date, Overdue = after; For Validation judged by Work Done Date; Manila today) (found in issue 09)
- [x] GLOSSARY.md: the E-Call entry doesn't say an E-Call can be filed from a PM Task by the Site EIC (For Validation or Returned) and keeps a link to it (found in issue 13)
- [x] GLOSSARY.md: the Needs Setup list entry says it lists what stops PM Tasks, but it now also lists Work Orders with a billing chain gap (Implemented / For Billing Endorsement LVLn), and the endpoint is `GET /api/needs-setup` behind `manage-site-settings` (found in issue 32)
- [x] GLOSSARY.md: no entry for Maintenance History (the Equipment timeline of PM Tasks, E-Calls and Work Orders, newest first; entry date = due date / filing date / creation date; `GET /api/equipment/{equipmentId}/history`) (found in issue 26)
- [x] GLOSSARY.md: no entry for Dashboard counts (`GET /api/dashboard`: PM Tasks by open PM Status, E-Calls by status plus open, Work Orders by status plus groups; current state; filters site, equipment category, vendor; vendor staff forced to own vendor; a Work Order's E-Call counts in both) (found in issue 27)
- [x] backend/README.md: no setup notes for sign-in: run `php artisan jwt:secret` per deployment, set `PSS_FIRST_ADMIN_EMPLOYEE_ID` and run `php artisan pss:setup-first-admin`, `DemoAccountsSeeder` demo accounts (EMP-9001/9002/1001/8001, password `password`), shared cache store needed for the logout blacklist, `JWT_TTL`/`JWT_REFRESH_TTL` (found in issue 04)
- [ ] frontend/README.md: no notes on the design setup (tokens in `src/styles.css` from `docs/design/mock-screens/pss-design.css`, `@lucide/angular` pinned at 1.71.0, fonts load from Google Fonts so need internet, `optimization.fonts: false` in production) (found in issue 48)
- [x] backend/README.md: no mention of `php artisan pss:check-vendor-admins` (read-only list of vendor-linked System Administrators; run after deploying issue 45) (found in issue 45)

## Log

- 2026-11-23: board created and backfilled from merge history (PRs #4–#28).
- 2026-11-23: product-owner picked 09 (unblocks 28, then 29; used by calendar and dashboard). Acceptance criteria finalised.
- 2026-11-23: issue 09 merged as PR #30; unblocks 28.
- 2026-11-23: product-owner cleared the 09 GLOSSARY follow-up (PM Timeliness rules) and picked 13 (unblocks 26, then 29; closes the unchecked `pm_task_id` on POST /api/e-calls).
- 2026-11-23: issue 13 merged as PR #32; unblocks 26.
- 2026-11-23: product-owner cleared the 13 GLOSSARY follow-up (E-Call from a PM Task), picked 24 (no email in the MVP, so this list is how users find their work; unblocks the new 39 and 40), split the E-Call and Work Order items into 39 and 40, and added the billing entries to 32 because 23 merged first.
- 2026-11-23: issue 24 merged as PR #34; unblocks 39 and 40.
- 2026-11-23: product-owner picked 39 (finishes the main use of the "Waiting for me" list; smaller than 40, and adds the `priority` field that 40 doesn't need).
- 2026-11-23: issue 39 merged as PR #36.
- 2026-11-23: product-owner picked 40 (completes the "Waiting for me" list; Work Order endorsers otherwise have no way to see their queue).
- 2026-11-23: issue 40 merged as PR #37.
- 2026-11-23: product-owner picked 32. It provides the "flag" half of the human's "block and flag" decisions on 08, 19 and 23: generation and endorsement already block, but nothing shows admins why. It also unblocks 33. Added the billing-gap acceptance criterion and the hand-off clarifications.
- 2026-11-23: issue 32 merged as PR #38; unblocks 33 (still needs-info, waiting on the human).
- 2026-11-24: product-owner split 25: PM Task cancel moved to the new 41 (needs-info, generation-job question still open). Picked 25 (E-Calls and Work Orders): without Cancel, a mistaken E-Call or Work Order can only be Rejected by an approver, and its creator can't withdraw it. Updated the GLOSSARY Cancel entry with the cut-offs.
- 2026-11-24: issue 25 merged as PR #40; 41 (PM Task cancel) stays needs-info.
- 2026-11-24: product-owner picked 26 (the per-Equipment timeline is a core PRD view, §5.3 / §7.1, and it is the best-defined of 26, 27 and 28; it is also one of 29's blockers). Added the hand-off clarifications (entry date, visibility, snapshots).
- 2026-11-24: issue 26 merged as PR #41.
- 2026-11-24: product-owner cleared the 26 GLOSSARY follow-up (Maintenance History entry). Split 27: the date range and financial sums moved to the new 42 (needs-info), and 29 is now also blocked by 42. Picked 27 (current status counts; first slice of the dashboard, PRD §4.1, which 28 and 42 then extend). 28 waits because "Equipment requiring attention" needs the operational status values from issue 03.
- 2026-11-24: issue 27 merged as PR #42; 28 is the only ready issue.
- 2026-11-24: product-owner cleared the 27 GLOSSARY follow-up (Dashboard entry). Recorded the human's issue 04 grilling decisions (JWT via `tymon/jwt-auth`, swappable credential checker, PSS roles, manual vendor staff link, all internal employees see every Site, one approval-history rule; ADR 0004). Split 04: Angular login and menu moved to the new 43 (ready-for-agent, blocked by 04), the external credential checker to the new 44 (needs-info). 04 is now ready-for-agent. Updated 29: reports only for System Administrator and Management / Viewer. Picked 04 over 28: it replaces every placeholder gate, unblocks 29 and 43, and lets the Angular screens start.
- 2026-11-24: issue 04 merged as PR #43; unblocks 43 (Angular login) and 29's login blocker. 44 stays needs-info.
- 2026-11-24: product-owner cleared the 04 README follow-up (sign-in setup in `backend/README.md`), added 45 (needs-info: may a vendor-linked user hold System Administrator?), and picked 43: it is the only ready issue that isn't waiting on 03 in practice (28's "Equipment requiring attention" needs 03's operational status values), and it lets every Angular screen start.
- 2026-11-24: issue 43 merged as PR #44; 28 is the only ready issue.
- 2026-11-24: product-owner recorded the human's issue 45/46 grilling decisions (ADR 0005, GLOSSARY Access entry, spec). Added 46 (ready-for-agent: admin-created users, access internal/vendor/pending, unique employee_id; closes the gap where an unlinked new user saw every Vendor's data) and 47 (needs-info: remove or deactivate users). 45 is now ready-for-agent, blocked by 46. 44 no longer classifies users and is also blocked by 46. Picked 46 over 28: it closes a data-visibility gap in merged code and unblocks 45.
- 2026-11-24: product-owner added 48 (ready-for-agent, blocked by 46): restyle the existing Angular screens to the "PSS Mock Screens" design (`docs/design/mock-screens/`) with `lucide-angular` (approved by the human). Restyle only; the other mock screens are reference for their later issues.
- 2026-11-24: issue 46 merged as PR #45; unblocks 45 and 48 (44 and 47 stay needs-info).
- 2026-11-24: product-owner picked 45 over 48 and 28: it stops vendor staff holding System Administrator (who could manage their own Vendor's Price List and assignments) and closes the "unlinked admin becomes pending" gap from 46. Small backend change. Added the post-46 clarifications.
- 2026-11-24: issue 45 merged as PR #46; 28 and 48 are ready.
- 2026-11-24: product-owner cleared the 45 README follow-up (`pss:check-vendor-admins` in `backend/README.md`) and picked 48 over 28: the human asked for it right after 46, and every later Angular screen builds on its shell and tokens. 28 still waits in practice on 03 ("Equipment requiring attention" needs the operational status values).
- 2026-11-24: issue 48 merged as PR #47; 28 is the only ready issue.
