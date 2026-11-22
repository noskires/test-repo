# 19 — Work Order endorsement (2 levels) before implementation

Status: ready-for-agent
Blocked by: 18
Type: task

## What to build

PRD §8.2 and lifecycle §5: the creator submits an `Open` Work Order.
- Submit → `For LVL1 Endorsement`, routed to the Level 1 approver (Supervisor / Team Head = Immediate Head, 1 level up).
- L1 Approve → `For LVL2 Endorsement`, routed to the Division Head (2 levels up).
- L2 Approve → `For Implementation` (the vendor is authorised. The in-app "waiting for me" list replaces email in the MVP).
- **Return** goes back exactly one step (glossary): from L1 back to the creator (`Open`, editable again), from L2 back to L1.
- **Reject**: final, `Rejected`.
- Each action writes an Approval Record. Approvers are resolved through the Immediate Head chain helper of `MasterDataDirectory` (issue 02).

## Open questions (for the human)

1. **Whose Immediate Head chain?** The PRD says "routed to the **Requestor's** immediate Supervisor", but the Work Order can be created by the EIC. When the EIC creates it, should Level 1 be the E-Call Requestor's head, or the EIC's (the creator's) head?
2. Is the Immediate Head chain always exactly Supervisor/Team Head → Division Head → Center Head → Sector Head? Or can a level be missing or skipped (e.g. the Requestor reports directly to a Division Head)? If a level is missing, what should happen?
3. Are approvers fixed when the Work Order is submitted, or re-resolved at each step (in case someone's Immediate Head changes in between)?

## Acceptance criteria (draft)

- [ ] Status transitions exactly as above, with Approval Records.
- [ ] Only the resolved approver for the current level can act (403 otherwise).
- [ ] Return goes back one step, as above. Reject is final.

## Comments

## Decisions (grilling, 2026-11-22)
- Q1 answered: the **Work Order creator's chain**, and the creator is always the E-Call's Requestor (issue 18). Billing endorsement uses the same chain.
- Q2 answered (earlier grilling): if a required level is missing from the chain, the Work Order is **blocked** at that step until the chain is fixed. The Immediate Head now lives in the external system, so the fix happens there. Q3 is still open.
- Q3 answered: **approvers are fixed when the Work Order is submitted** (the chain is captured then). Later org-chart changes don't re-route a Work Order that's in flight.
- 2026-11-22 (product-owner): FYI. The human decided that the **current Site EIC** acts on pending items, and the EIC captured on a record is history only (issue 11 Decisions). Endorsers here come from the Immediate Head chain, not the EIC, so nothing changes unless the chain question (Q1) ends up starting from the EIC.
