# Spec: PSS MVP

Source: `prd/PASSIVE SUPPORT SERVICES.docx` (Initial SRS). Vocabulary follows `GLOSSARY.md`.

## Goal

One web platform (Laravel API + Angular) for the two maintenance processes at every Site:

- **Preventive Maintenance (PM)**: PM Tasks are generated automatically, the Site Vendor does the check-up and fills in the PM Checklist, and the EIC validates the PM Report.
- **Corrective Maintenance (CM)**: a Requestor files an E-Call, the Site Vendor submits an FSR and BOQ, and the EIC reviews them. A Work Order is then created, endorsed, implemented, validated and billed through the Immediate Head chain.

## MVP scope (decided with the human, 2026-11-22)

In: PM, E-Calls, Work Orders and approvals, Dashboard and reports.
Out of the MVP: email notifications. People see their pending items in an in-app "waiting for me" list instead (issue 24).

## Constraint: master data is external

Equipment, Sites, Equipment Categories, employees (with each one's Immediate Head) and login all come from an **existing external API**. PSS does **not** own master tables or CRUD screens for them.

- PSS stores only its own data, keyed by the external IDs: Site Vendor, Site EIC, Site PM Frequency, Vendors, Price Lists, PM Tasks, E-Calls, Work Orders, approvals.
- Whenever a PSS record refers to a Site or Equipment, PSS also stores a **Snapshot** of the relevant details (name, category, location) so history still reads correctly if the item is renamed or removed upstream.
- All reads go through one `MasterDataDirectory` port (issue 02). Tests use an in-memory fake. The real HTTP adapter (issue 03) is blocked until we get the API's URL, auth and response shape.

## Cross-cutting technical rules for every issue

- Slices are delivered **API-first**: migrations, models, endpoints under `/api`, and PHPUnit feature and unit tests (the project uses PHPUnit; Pest is not installed). The Angular screens follow once login is built: backend issue 04, then the Angular login and menu in issue 43.
- Register `routes/api.php` in `bootstrap/app.php`. Do **not** run `php artisan install:api` or add packages without approval.
- **Authorization** goes through named Laravel Gates or Policies (e.g. `manage-vendors`). From issue 04 the gates resolve from PSS Roles (ADR 0004). The acting user is Laravel's authenticated user (JWT `api` guard), and tests use `actingAs`. Each user carries an external `employee_id`. Vendor staff also carry a `vendor_id`, set only when a PSS admin links them to a Vendor.
- Every approval action (Approve / Return / Reject) writes an **Approval Record** with the approver, action, timestamp, remarks, previous status and resulting status (PRD Module 7). The shared mechanism is built in issue 11 and reused afterwards.
- Money is in PHP (₱), stored as integer centavos or a decimal with 2 places. Never use floats.

## Where the PRD conflicts with GLOSSARY.md, the glossary wins (earlier grilling decisions)

| PRD says | Decided (GLOSSARY.md) |
| --- | --- |
| PM frequency per equipment or category (§6.1) | One **PM Frequency per Site**. All Equipment at that Site follows it. |
| Vendor assigned per equipment / service category (§5.1, Module 6) | One **Site Vendor** per Site handles every job there. Only an administrator can replace it. |
| EIC can REJECT a PM report (§6.7) | PM Reports can only be **Approved or Returned**. |
| UPCOMING / DUE / OVERDUE listed as PM statuses (§6.8) | Those are **PM Timeliness**, shown next to PM Status, not as statuses. |
| Billing basis "to be confirmed" (§8.5) | **Billing Amount** = the validated actual cost. |
| Next PM date from configured frequency | **Sliding schedule**: the next due date counts from the previous task's **Work Done Date**. |
| BOQ lines that aren't on the Price List are allowed but flagged for the EIC (PRD v2 §10) | **Resolved: glossary** (human, 2026-11-22). A **BOQ** lists only the Site Vendor's own active **Price List** items. Off-list lines are not wanted. |
| Approvers are locked in when the Work Order is submitted; billing uses the chain locked in at submission (PRD v2 §8.2, §8.3) | **Resolved: current at each step** (human, 2026-11-23; GLOSSARY "Immediate Head", "Endorsement", ADR 0001 amendment). Endorsers and billing approvers are whoever holds the position on the Requestor's chain when it's their turn. |
| A Work Order is visible to its Site Vendor's staff from `For Implementation` (PRD v2 §8.2) | **Resolved: visible from `Open`** (human, 2026-11-23, issue 20). Vendor staff see their Vendor's Work Orders at every status, following the E-Call's visibility. |

## Decisions from the human (2026-11-22)

- **Money rounding** (docs/adr/0002-money-rounding.md): line totals stay exact. Only document totals (BOQ total, Work Order estimated amount, Billing Amount) are rounded, once, to the centavo, half-up. Billing Thresholds compare against the rounded Billing Amount. Quantities have up to 3 decimal places.
- **Equipment with no start date**: an Equipment with no installation date and no PM history in PSS gets **no PM Task**. It is listed on the admin **Needs Setup** list (issue 32) until a start date is set. Whether admins can enter that start date in PSS is open (issue 33).
- **No duplicate lines** (confirmed): the same Price List item can't appear twice in one BOQ revision (422). The vendor combines the quantities into one line. Likewise, a Work Order can't repeat a BOQ line (issue 18).
- **The current Site EIC acts on pending items**: if a Site's EIC is replaced, the current Site EIC (from Site settings at the time of the action) approves, returns, validates or cancels pending PM Tasks, E-Calls and Work Orders. The EIC captured on a record is history only (issue 11).
- **Work Order endorsement** (issue 19, 2026-11-23): endorsers come from the **E-Call Requestor's** Immediate Head chain (L1 = their Immediate Head, L2 = that person's head), are **resolved current at each step** (amends ADR 0001), and a short or missing chain **blocks and flags**: a 422, plus the Work Order on the Needs Setup list (issue 32). **Billing endorsement (issue 23) uses the same rules** (human, 2026-11-23), with levels recalculated against the current thresholds at each step and an L1 Return going to the EIC.
- **Endorsement hardening** (issue 38, 2026-11-23): **separation of duties**. The endorsers at each level (Work Order and billing) must be distinct people, none may be the Requestor, the same person can't approve two different levels in one submission cycle (re-approving the same level after a return is fine, and Return/Reject aren't blocked), and a cycle in the chain counts as broken. Any of these gives 422, no record, and the Work Order on Needs Setup. **No status oracle**: endorsement endpoints authorize `view` first, and outsiders get a plain 403.
- **Work Order quantities** (issue 20, 2026-11-23): the vendor may report an actual quantity **above** the approved quantity, subject to the EIC's review. Each line's discrepancy against the approved quantity (over / under / equal, exact per ADR 0002) and a Work Order-level "has discrepancies" flag are shown to the EIC before validation (issue 21). Vendor staff see their Work Orders from `Open` (overrides PRD v2 §8.2).
- **Application timezone is Asia/Manila** (docs/adr/0003-application-timezone-asia-manila.md), switched before go-live (issue 35). "Today", the Work Done Date window, PM due dates, PM Timeliness and the daily generation job all use Manila time.

- **Login and roles** (issue 04 grilling, 2026-11-24; docs/adr/0004-jwt-login-and-pss-roles.md): JWT via `tymon/jwt-auth` (approved), 60-minute access token, 14-day refresh window, logout blacklists. Credentials go through a swappable `CredentialChecker` port; the local version (hashed passwords, seeded demo accounts) runs only in local/testing, and the external one is issue 44. A PSS user is created on first sign-in; the first System Administrator comes from `PSS_FIRST_ADMIN_EMPLOYEE_ID` via an artisan command. PSS stores two roles: **System Administrator** (all `manage-*` gates plus granting roles) and **Management / Viewer**. A PSS admin links vendor staff to a Vendor by hand; linking a current Site EIC is refused, and the last System Administrator can't lose the role. Angular keeps the token in localStorage (issue 43).
- **Visibility** (2026-11-24): every internal employee sees every Site; vendor staff see only their own Vendor's records. Anyone who can view a record can read its approval history (PM Tasks, E-Calls, Work Orders).
- **Dashboard KPIs** (issue 28 grilling, 2026-11-22): **PM Compliance** = PM Tasks done on time ÷ PM Tasks due in the period, where on time means Work Done Date ≤ due date. **Equipment Requiring Attention** = any of: an overdue PM Task, a Fail/Defective item on its latest approved PM Report, an open Emergency E-Call, or not operating according to the external API. Operational status comes from the external API, and PSS doesn't keep it. Vendor performance and recurring-issue/reliability metrics are out of the MVP. Details still to confirm (Completed-only, mid-month cut-off, current-month default, status values) are in issue 28.
- **Reports access** (2026-11-24): Reports & Export (issue 29) only for System Administrator and Management / Viewer. The dashboard (issue 27) stays open to all internal employees. This replaces the 2026-11-22 "everyone sees reports" answer.
- **Admin-created users and access** (issue 45/46 grilling, 2026-11-24; docs/adr/0005-admin-created-users-and-pending-access.md): sign-in no longer creates users; a System Administrator adds each one (`POST /api/admin/users`, employee must be in the master data, unique `employee_id`) and picks internal or a Vendor. Each user has an access state, **internal / vendor / pending**. Unlinking a Vendor makes a user pending; a pending user can sign in but only use `me`, `refresh` and `logout` until an admin links a Vendor or sets internal (who and when recorded), and can't be granted roles. Wrong password = 401 whether or not registered; correct password but not in PSS = 403 (issue 46). Vendor-linked users can't hold System Administrator, and an admin can't be linked to a Vendor; Management / Viewer stays allowed, with Reports limited to their own Vendor (issue 45). Removing or deactivating users is open (issue 47). This replaces the 2026-11-24 "created on first sign-in" part of the login decision above.

## Issues

See `issues/`. Each one has a `Status:` line (`ready-for-agent` / `needs-info` / `done`) and a `Blocked by:` line. Progress and what to do next: `epic.md`.
