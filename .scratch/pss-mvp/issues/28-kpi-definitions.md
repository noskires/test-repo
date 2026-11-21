# 28 — Define dashboard and report KPIs

Status: ready-for-agent
Blocked by: 09
Type: task

## What to build

The PRD asks for several indicators without saying how to calculate them (§3.1, §4.1, §6.9, Module 8).

## Open questions (for the human)

1. **PM compliance %**: is it completed-on-time ÷ due in the period? Or completed (any time) ÷ scheduled? Does the Work Done Date or the EIC approval date count for "on time"?
2. **Vendor performance**: which measures (PM on-time rate, E-Call response time from filing to FSR/BOQ, number of Returns, actual vs BOQ variance)? Any weighting?
3. **Equipment requiring attention**: what triggers it (overdue PM, failed checklist items, an open Emergency E-Call, an operational status from the external API)?
4. **Operational vs non-operational equipment**: does that status come from the external API (see issue 03 Q4), or is it maintained in PSS? If in PSS, who sets it?
5. **Recurring issues / equipment reliability**: how many E-Calls or failed findings in what period count as "recurring"? Is there a reliability metric (e.g. MTBF)?

## Acceptance criteria (draft)

- [ ] Each KPI is a pure, unit-tested calculation, then exposed on `/api/dashboard` and in reports.

## Comments

## Decisions (grilling, 2026-11-22)
- Q1 answered: **PM compliance % = PM Tasks done on time ÷ PM Tasks due in the period**. "On time" means Work Done Date ≤ due date (issue 09). Late and not-done tasks don't count as compliant.
- Q4 answered: **operational status comes from the external asset API** (see issue 03 Q4). PSS doesn't maintain it.
- Q3 answered: **Equipment requiring attention** = any of: overdue PM Task; a Fail/Defective item on its latest approved PM Report; an open Emergency-priority E-Call; not operating according to the external API.
- Q2 and Q5 answered: **vendor performance and recurring-issue/reliability metrics are out of the MVP**.
