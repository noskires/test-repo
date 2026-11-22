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

- Slices are delivered **API-first**: migrations, models, endpoints under `/api`, and PHPUnit feature and unit tests (the project uses PHPUnit; Pest is not installed). The Angular screens follow once login (issue 04) is settled, because the frontend can't sign in until then.
- Register `routes/api.php` in `bootstrap/app.php`. Do **not** run `php artisan install:api` or add packages without approval.
- **Authorization** goes through named Laravel Gates or Policies (e.g. `manage-vendors`). Until issue 04 decides where roles come from, tests set the gate outcome. The acting user is Laravel's authenticated user, and tests use `actingAs`. Each user carries an external `employee_id`, and vendor staff also carry a `vendor_id`. Issue 04 decides how these get filled in at login.
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

## Decisions from the human (2026-11-22)

- **Money rounding** (docs/adr/0002-money-rounding.md): line totals stay exact. Only document totals (BOQ total, Work Order estimated amount, Billing Amount) are rounded, once, to the centavo, half-up. Billing Thresholds compare against the rounded Billing Amount. Quantities have up to 3 decimal places.
- **Equipment with no start date**: an Equipment with no installation date and no PM history in PSS gets **no PM Task**. It is listed on the admin **Needs Setup** list (issue 32) until a start date is set. Whether admins can enter that start date in PSS is open (issue 33).
- **No duplicate lines** (confirmed): the same Price List item can't appear twice in one BOQ revision (422). The vendor combines the quantities into one line. Likewise, a Work Order can't repeat a BOQ line (issue 18).

## Issues

See `issues/`. Each one has a `Status:` line (`ready-for-agent` / `needs-info`) and a `Blocked by:` line.
