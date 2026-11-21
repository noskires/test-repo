# 27 — Dashboard: operational counts

Status: ready-for-agent
Blocked by: 11, 17, 21
Type: task

## What to build

PRD §4.1: one endpoint `GET /api/dashboard` returning the **counts and sums that are already well defined**, with filters for site, equipment category, vendor and date range:
- PM: open PM Tasks by PM Status, and Completed in the date range.
- CM: E-Calls by E-Call Status (open = not `Closed` / `Rejected`). Work Orders by status (awaiting endorsement, under implementation, awaiting EIC validation, awaiting billing endorsement, Closed).
- Financial: total estimated amount of Work Orders, the approved amount (Work Orders past LVL2 endorsement), and the sum of Billing Amounts. All of these by Site and by Vendor.

PM Timeliness counts (upcoming / due / overdue), PM compliance % and vendor performance are **not** in this slice. They depend on issues 09 and 28.

## Acceptance criteria

- [ ] With seeded fixtures, every count and sum matches a hand-calculated expectation.
- [ ] Each filter (site, category, vendor, date range) narrows the results, with a test each.
- [ ] Money sums are exact.

## Comments
