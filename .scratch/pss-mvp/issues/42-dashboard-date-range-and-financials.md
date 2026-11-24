# 42 — Dashboard: date range and financial sums

Status: needs-info
Blocked by: 27
Type: task

## What to build

Split from issue 27 on 2026-11-24. PRD §4.1 asks the dashboard (`GET /api/dashboard`) for a date range filter and financial figures:
- PM: PM Tasks Completed in the date range.
- Financial: total estimated amount of Work Orders, the approved amount (Work Orders past LVL2 endorsement), and the sum of Billing Amounts. All of these by Site and by Vendor.

## Open questions (for the human)

1. **What does the date range apply to?** For "PM Tasks Completed in the range" we'd use the Work Done Date (like PM Timeliness). For the financial sums: the Work Order's creation date, the date LVL2 approved it, the date it was Closed, or something else? Should the date range also narrow the current status counts from issue 27, and if so by which date?
2. **Which Work Orders count in each sum?**
   - Total estimated amount: every Work Order, or only those not `Rejected` / `Cancelled`?
   - Approved amount ("past LVL2 endorsement"): a Work Order can be Rejected later, at completion validation (issue 21). Does a Work Order that was approved at LVL2 and then Rejected still count?
   - Billing Amounts: only `Closed` Work Orders (billing endorsement finished), or also those still in billing endorsement?
3. Should vendor staff see financial figures at all (limited to their own Vendor), or only internal users?

## Acceptance criteria (draft)

- [ ] `from` / `to` narrow the figures by the agreed dates, a test each.
- [ ] With seeded fixtures, each sum matches a hand-calculated expectation, by Site and by Vendor. Money sums are exact (ADR 0002).

## Comments
