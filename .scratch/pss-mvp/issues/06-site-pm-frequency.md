# 06 — Set a Site's PM Frequency

Status: needs-info
Blocked by: 05
Type: task

## What to build

One **PM Frequency** per Site (glossary). Every Equipment at that Site follows it. This was decided over the PRD's "per equipment or category" (see spec, conflicts table). An administrator sets it alongside the Site Vendor and EIC. It also needs a pure function that computes the next due date from a Work Done Date and a frequency (sliding schedule).

PRD-listed values: Monthly, Twice a month, Every two months (bi-monthly), Quarterly, Semi-annually, Annually, "other configurable intervals".

## Open questions (for the human)

1. **Twice a month**: since the schedule slides from the Work Done Date, is that "every 15 days" or "every half month"? What exact interval should be added?
2. **Other configurable intervals**: do admins need to enter any arbitrary interval (e.g. "every 45 days", "every 4 months")? Or is the fixed list enough for the MVP?
3. Month arithmetic edge case: if the Work Done Date is 31 Jan and the frequency is Monthly, is the next due date 28/29 Feb or 2/3 Mar?
4. When a Site's frequency changes, does it affect only tasks generated afterwards, or also re-date the current open PM Task?
5. PRD §6.2 also lists "preferred maintenance schedule", "start date of the maintenance cycle" and "effective date". Are any of these needed per Site? (See also issue 08 Q1.)

## Acceptance criteria (draft)

- [ ] Admin can set and change a Site's PM Frequency (`manage-site-settings` Gate). The change is recorded in the Site settings history.
- [ ] The next-due-date function has unit tests for each frequency and the month-end edge cases.

## Comments

## Decisions (grilling, 2026-11-22)
- Q1 answered: **Twice a month = every 15 days**, added to the Work Done Date.
- Q2 answered: the **fixed list is enough for the MVP** (monthly, twice a month = 15 days, every 2 months, quarterly, semi-annual, annual). No custom intervals.
- Q3 answered: month arithmetic **clamps to the month's last day** (31 Jan + 1 month = 28/29 Feb).
- Q4 answered: a frequency change **also re-dates the current open PM Task** (new due date = last Work Done Date + new interval). Whether that includes tasks already In Progress is still open.
- Q4 follow-up answered: re-dating applies **only to PM Tasks still in `Scheduled`**. Tasks In Progress, For Validation or Returned keep their due date.
