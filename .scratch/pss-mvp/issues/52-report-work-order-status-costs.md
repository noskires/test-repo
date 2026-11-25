# 52 — Report: Work Order status and costs

Status: needs-info
Blocked by: 29, 42
Type: task

## What to build

Split from issue 29 on 2026-11-25. This is the second MVP report ("Work Order status + costs", human, 2026-11-22). It's a JSON endpoint plus an `.xlsx` export. It reuses 29's `view-reports` gate, Vendor scoping and xlsx helper, and 42's money rules (stored centavo amounts, decimal strings, ADR 0002).

## Open questions (for the human)

1. **Columns.** The product-owner proposes one row per Work Order with: Work Order number, E-Call reference, Site, Equipment, Vendor, current status, created date, estimated amount, billing amount (blank until set), and closed date (blank until Closed). Add or remove any?
2. **Which date does the `from`/`to` range filter on?** The proposal is the Work Order's creation date, matching 42's estimated amount. Should Rejected and Cancelled Work Orders appear (with their status), or be left out?
3. Should the report end with a totals row (sum of estimated and billing amounts)?

## Acceptance criteria (draft, final once the questions are answered)

- [ ] `GET /api/reports/work-orders` and `GET /api/reports/work-orders/export` (`.xlsx`) take the dashboard filters (`from`, `to`, `site_id`, `equipment_category_id`, `vendor_id`). The Work Order is reached through its E-Call, as in issue 27.
- [ ] Rows and columns as decided above. Amounts are exact decimal strings per ADR 0002, summed from the stored centavo columns.
- [ ] Same `view-reports` gate and Vendor scoping as 29, each with a test. The xlsx test checks the header and the data rows against fixtures.
- [ ] The query count doesn't grow with the number of Work Orders.
