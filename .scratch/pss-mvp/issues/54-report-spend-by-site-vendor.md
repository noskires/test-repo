# 54 — Report: Spend by Site and Vendor

Status: needs-info
Blocked by: 29, 42
Type: task

## What to build

Split from issue 29 on 2026-11-25. This is the fourth MVP report ("Spend by site/vendor", human, 2026-11-22). Issue 42 already calculates `financials` (`estimated`, `approved`, `billing`, each with a total, `by_site` and `by_vendor`) on `GET /api/dashboard`. This report exports those figures with 29's `view-reports` gate, Vendor scoping and xlsx helper.

## Open questions (for the human)

1. **Which figure is "spend"?** The proposal is to show all three of 42's figures (estimated, approved, billing) as columns, with billing as the actual spend.
2. **Layout.** Two sheets/tables, one by Site and one by Vendor (the proposal, matching 42's `by_site` / `by_vendor`), or a single Site × Vendor matrix?

## Acceptance criteria (draft, final once the questions are answered)

- [ ] A JSON endpoint and an `.xlsx` export whose figures equal 42's `financials` for the same filters and period (reuse `DashboardFinancials`, don't copy it). The test compares the two.
- [ ] Same `view-reports` gate and Vendor scoping as 29, each with a test. The xlsx test checks the header and the data rows against fixtures.
