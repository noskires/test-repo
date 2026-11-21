# 29 — Reports and export

Status: needs-info
Blocked by: 26, 27, 28
Type: task

## What to build

PRD Module 8 and §6 "Exportable reports": inventory, PM accomplishment (monthly, quarterly, annual), PM compliance, overdue maintenance, E-Call and Work Order reports, approved vs actual material consumption, Work Order costs, billing endorsement status, expenditure, and vendor performance.

## Open questions (for the human)

1. Which reports are actually needed for the MVP go-live, and in what priority order?
2. What export format(s): CSV, XLSX, PDF? Is there a required layout or letterhead? (XLSX or PDF needs a new package, which needs approval.)
3. Who can see which reports? (Management / Viewer sees "authorized reports". Which ones are those?)

## Acceptance criteria (draft)

- [ ] Each agreed report has an endpoint with filters and an export in the agreed format, with feature tests against fixtures.

## Comments

## Decisions (grilling, 2026-11-22)
- Q1 answered: the MVP reports are **Check-up compliance**, **Work Order status + costs**, **Equipment history** and **Spend by site/vendor**. The rest of the PRD's reports come later.
- Q2 answered: the export format is **Excel (.xlsx)**. The user chose it knowing it needs a spreadsheet package (e.g. `phpoffice/phpspreadsheet`). Confirm the exact package before adding it. The same package would cover XLSX price-list uploads (issue 15 Q4).
- Q3 answered: **everyone sees the dashboard and reports, filtered to their own sites**. Vendors see only their own Sites' jobs. How a user's sites are determined is still open (see issue 04).
