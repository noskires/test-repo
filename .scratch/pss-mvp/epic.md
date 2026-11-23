# Epic: PSS MVP — progress board

Spec: `spec.md`. Issues: `issues/NN-*.md`. **Start every session here.**

Owners: **releaser** updates this file in each issue's close-out commit (before merge). **product-owner** adds new issues and status changes (needs-info → ready-for-agent).

## Resume here

- Last merged: **24 — "Waiting for me" list** (PR #34, 2026-11-23)
- In flight: **39 — "Waiting for me": E-Call items** (handed off 2026-11-23)
- Ready now (`ready-for-agent`, every blocker done), in number order: **25, 26, 27, 28, 32, 39, 40**
- Waiting on the human (`needs-info`): 03, 04, 15, 31, 33. The open questions are in each issue file.
- Doc follow-ups: none open

## Board

| # | Title | Status | Blocked by | PR |
|---|---|---|---|---|
| 01 | Vendor registry | done | none | #4 |
| 02 | Master data directory port (in-memory fake) | done | none | #5 |
| 03 | HTTP adapter for the external master data API | needs-info | 02 | |
| 04 | Login via SSO and PSS roles | needs-info | none | |
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
| 25 | Cancelling PM Tasks, E-Calls and Work Orders | ready-for-agent | 08, 12, 18 | |
| 26 | Equipment maintenance history | ready-for-agent | 11, 13, 18 | |
| 27 | Dashboard: operational counts | ready-for-agent | 11, 17, 21 | |
| 28 | Define dashboard and report KPIs | ready-for-agent | 09 | |
| 29 | Reports and export | ready-for-agent | 04, 26, 27, 28 | |
| 30 | Local dev master data fake | done | 02 | #7 |
| 31 | Seed the fixed MVP PM Checklists | needs-info | 07 | |
| 32 | Admin "Needs Setup" list | ready-for-agent | 08, 19 | |
| 33 | PM start date for Equipment with no installation date | needs-info | 08, 32 | |
| 34 | PM Report: Service Report + Work Done Date | done | 10 | #17 |
| 35 | Application timezone Asia/Manila | done | none | #18 |
| 36 | Concurrent-safe backend test suite (chore) | done | none | #20 |
| 37 | Vendor staff can never act as EIC (chore) | done | none | #22 |
| 38 | Shared vendor-staff rule, endorsement hardening (chore) | done | none | #25 |
| 39 | "Waiting for me": E-Call items | ready-for-agent | 24 | |
| 40 | "Waiting for me": Work Order items | ready-for-agent | 24 | |

Other merged PRs: #1 PRD v2 docs, #2 project scaffold, #3 unblock answered issues, #15 no-duplicate-lines decision.

## Doc follow-ups

Doc gaps the releaser found at close-out (GLOSSARY, ADR, spec, READMEs). product-owner clears them before picking the next issue. Format: `- [ ] <file>: <what's missing> (found in issue NN)`.

- [x] GLOSSARY.md: PM Timeliness entry lacks the rules (Upcoming = 7 days before due, Due = due date, Overdue = after; For Validation judged by Work Done Date; Manila today) (found in issue 09)
- [x] GLOSSARY.md: the E-Call entry doesn't say an E-Call can be filed from a PM Task by the Site EIC (For Validation or Returned) and keeps a link to it (found in issue 13)

## Log

- 2026-11-23: board created and backfilled from merge history (PRs #4–#28).
- 2026-11-23: product-owner picked 09 (unblocks 28, then 29; used by calendar and dashboard). Acceptance criteria finalised.
- 2026-11-23: issue 09 merged as PR #30; unblocks 28.
- 2026-11-23: product-owner cleared the 09 GLOSSARY follow-up (PM Timeliness rules) and picked 13 (unblocks 26, then 29; closes the unchecked `pm_task_id` on POST /api/e-calls).
- 2026-11-23: issue 13 merged as PR #32; unblocks 26.
- 2026-11-23: product-owner cleared the 13 GLOSSARY follow-up (E-Call from a PM Task), picked 24 (no email in the MVP, so this list is how users find their work; unblocks the new 39 and 40), split the E-Call and Work Order items into 39 and 40, and added the billing entries to 32 because 23 merged first.
- 2026-11-23: issue 24 merged as PR #34; unblocks 39 and 40.
- 2026-11-23: product-owner picked 39 (finishes the main use of the "Waiting for me" list; smaller than 40, and adds the `priority` field that 40 doesn't need).
