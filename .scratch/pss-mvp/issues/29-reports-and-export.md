# 29 — Reports and export

Status: ready-for-agent
Blocked by: 04, 26, 27, 28
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
- [ ] Results are filtered to the requesting user's Sites, using the rule decided in issue 04. A vendor user sees only their own Sites' jobs.
- [ ] No package other than `phpoffice/phpspreadsheet` is added.

## Comments

## Decisions (grilling, 2026-11-22)
- Q1 answered: the MVP reports are **Check-up compliance**, **Work Order status + costs**, **Equipment history** and **Spend by site/vendor**. The rest of the PRD's reports come later.
- Q2 answered: the export format is **Excel (.xlsx)**. The user chose it knowing it needs a spreadsheet package (e.g. `phpoffice/phpspreadsheet`). Confirm the exact package before adding it. The same package would cover XLSX price-list uploads (issue 15 Q4).
- Q3 answered: **everyone sees the dashboard and reports, filtered to their own sites**. Vendors see only their own Sites' jobs. How a user's sites are determined is still open (see issue 04).

- 2026-11-22 (product-owner): Human decision: **`phpoffice/phpspreadsheet` is approved** for XLSX export (closes the Q2 package confirmation). Q1, Q2 and Q3 are all answered, so this issue has no open questions of its own and is flipped to **ready-for-agent**. The one remaining dependency is how a user's Sites are determined, which is an open question in issue 04 (needs-info). Issue 04 is therefore added to `Blocked by`. Site filtering must use whatever 04 decides and must not invent its own rule.
