# 23 — Multi-level billing endorsement and closing the Work Order

Status: ready-for-agent
Blocked by: 19, 21, 22
Type: task

## What to build

PRD §8.5: after the EIC's validation, the Work Order goes through 2, 3 or 4 billing endorsement levels, depending on the **Billing Amount** and the Billing Thresholds (issue 22):
- L1 Supervisor / Team Head → L2 Division Head → (L3 Center Head) → (L4 Sector Head), resolved up the Immediate Head chain.
- Statuses: `For Billing Endorsement LVL1` → `LVL2` → `LVL3` → `LVL4`, as needed. The last required approval → `Closed`.
- **Return** goes back exactly one step, to the previous approver (glossary). **Reject** is final.
- Each action writes an Approval Record.

## Open questions (for the human)

1. Same as issue 19 Q1: whose Immediate Head chain is used for billing (the E-Call Requestor's, the Work Order creator's, or the EIC's)?
2. Is the number of levels fixed when the EIC validates (issue 21)? Or recomputed if the Billing Thresholds change mid-way?
3. When the Level 1 billing approver **Returns**, where does it go: back to the EIC to revalidate the actual quantities (status `Implemented`), or somewhere else?

## Acceptance criteria (draft)

- [ ] For Billing Amounts of 150k / 200k / 1M / 1.2M, the Work Order needs 2 / 3 / 3 / 4 approvals and ends `Closed`.
- [ ] Only the current level's resolved approver can act.
- [ ] Return goes back one step. Reject is final. Approval Records are written.

## Comments

## Decisions (grilling, 2026-11-22)
- The required levels are **recalculated against the current Billing Thresholds** at each step, not frozen. If a threshold change means the levels already done are enough, the Work Order Closes immediately. The approvers themselves are still the chain fixed at submission (issue 19).
- Q1 and Q2 answered above (the creator's chain; levels recalculated).
- Q3 answered: a Return from billing level 1 goes **back to the EIC** (status `Implemented`) to re-validate the actual quantities. That is "back one step".
- 2026-11-22 (product-owner): Rounding decided by the human (docs/adr/0002-money-rounding.md). The number of levels is decided by comparing the **rounded** Billing Amount (integer centavos, from issue 21) with the Billing Thresholds. The status is unchanged (open questions 1 to 3 remain).
