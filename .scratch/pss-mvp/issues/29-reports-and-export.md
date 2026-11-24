# 29 — Reports and export

Status: ready-for-agent
Blocked by: 04, 26, 27, 28, 42
Type: task

## What to build

PRD Module 8 and §6 "Exportable reports": inventory, PM accomplishment (monthly, quarterly, annual), PM compliance, overdue maintenance, E-Call and Work Order reports, approved vs actual material consumption, Work Order costs, billing endorsement status, expenditure, and vendor performance.

## Open questions (for the human)

1. Which reports are actually needed for the MVP go-live, and in what priority order?
2. What export format(s): CSV, XLSX, PDF? Is there a required layout or letterhead? (XLSX or PDF needs a new package, which needs approval.)
3. Who can see which reports? (Management / Viewer sees "authorized reports". Which ones are those?)

## Acceptance criteria

- [ ] Each of the four MVP reports (Check-up compliance, Work Order status + costs, Equipment history, Spend by site/vendor) has a JSON endpoint with filters, using the KPI definitions from issue 28.
- [ ] Each report can be exported as `.xlsx` using `phpoffice/phpspreadsheet` (approved 2026-11-22). A feature test opens the downloaded file and checks its header row and data rows against fixtures.
- [ ] A `view-reports` gate allows only users with the `system-administrator` or `management-viewer` role (issue 04). Everyone else, including other internal employees, gets 403 on every report and export endpoint. One test per outcome.
- [ ] Site filtering follows issue 04: internal employees with access see every Site. A vendor-linked user (`vendor_id` set) who holds one of those roles sees only their own Vendor's records.
- [ ] No package other than `phpoffice/phpspreadsheet` is added.

## Comments

## Decisions (grilling, 2026-11-22)
- Q1 answered: the MVP reports are **Check-up compliance**, **Work Order status + costs**, **Equipment history** and **Spend by site/vendor**. The rest of the PRD's reports come later.
- Q2 answered: the export format is **Excel (.xlsx)**. The user chose it knowing it needs a spreadsheet package (e.g. `phpoffice/phpspreadsheet`). Confirm the exact package before adding it. The same package would cover XLSX price-list uploads (issue 15 Q4).
- Q3 answered (**superseded for reports on 2026-11-24**, see below): **everyone sees the dashboard and reports, filtered to their own sites**. Vendors see only their own Sites' jobs. How a user's sites are determined is still open (see issue 04).

- 2026-11-22 (product-owner): Human decision: **`phpoffice/phpspreadsheet` is approved** for XLSX export (closes the Q2 package confirmation). Q1, Q2 and Q3 are all answered, so this issue has no open questions of its own and is flipped to **ready-for-agent**. The one remaining dependency is how a user's Sites are determined, which is an open question in issue 04 (needs-info). Issue 04 is therefore added to `Blocked by`. Site filtering must use whatever 04 decides and must not invent its own rule.
- 2026-11-24 (product-owner): `Blocked by` now also lists 42. Issue 27 was split, and its financial sums and date range moved to 42; the "Work Order status + costs" and "Spend by site/vendor" reports need those definitions.

## Decisions (human, 2026-11-24)

From the issue 04 grilling:
- Q11: (a) **Reports & Export only for System Administrator and Management/Viewer.** This replaces the 2026-11-22 Q3 "everyone sees reports" for reports. The dashboard (issue 27) stays open to all internal employees.
- Q5: (a) every internal employee sees every Site. Vendor staff see only their own Vendor's records. This answers the "visible Sites" dependency.

- 2026-11-24 (product-owner): acceptance criteria updated for Q11 (`view-reports` gate) and Q5 (Site filtering). Still blocked by 04, 26, 27, 28, 42.
