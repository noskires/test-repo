# 53 — Report: Equipment history

Status: needs-info
Blocked by: 26, 29
Type: task

## What to build

Split from issue 29 on 2026-11-25. This is the third MVP report ("Equipment history", human, 2026-11-22). Issue 26 already has the per-Equipment timeline (`GET /api/equipment/{equipmentId}/history`). This report exports it with 29's `view-reports` gate, Vendor scoping and xlsx helper.

## Open questions (for the human)

1. **One Equipment or many?** Should the report export the timeline of a single chosen Equipment, which is the existing issue 26 data (the product-owner's proposal), or all Equipment at a Site / in a category for a date range?
2. **Columns.** The proposal is the issue 26 timeline fields: entry date, type (PM Task / E-Call / Work Order), reference, status, Vendor, and a short summary. Anything else, such as amounts on Work Orders?

## Acceptance criteria (draft, final once the questions are answered)

- [ ] A JSON endpoint and an `.xlsx` export that return exactly the issue 26 timeline entries for the same filters (reuse `EquipmentHistory`, don't copy it).
- [ ] Same `view-reports` gate and Vendor scoping as 29, each with a test. The xlsx test checks the header and the data rows against fixtures.
