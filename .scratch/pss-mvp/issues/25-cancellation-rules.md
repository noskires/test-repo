# 25 — Cancelling PM Tasks, E-Calls and Work Orders

Status: ready-for-agent
Blocked by: 08, 12, 18
Type: task

## What to build

The PRD lists `CANCELLED` for PM Tasks ("cancelled with an authorized reason", §6.8) and for Work Orders and other requests ("withdrawn or cancelled", §5). But it doesn't say who can cancel or when.

## Open questions (for the human)

1. **PM Task**: who can cancel one (admin, EIC)? In which statuses? Does cancelling generate the next PM Task, and if so, from what date (there is no Work Done Date)?
2. **E-Call**: can the Requestor withdraw an E-Call? Up to which point (before the vendor submits the FSR/BOQ? before EIC approval)?
3. **Work Order**: who can cancel (creator, EIC, admin)? Up to which status? Can a Work Order already `For Implementation` be cancelled?
4. Is a reason always required?

## Acceptance criteria (draft)

- [ ] Each cancel action is allowed only for the agreed roles and statuses, requires a reason, and writes a workflow (Approval Record) entry.

## Comments

## Decisions (grilling, 2026-11-22)
- **Who**: the record's creator (the Requestor, for E-Calls and Work Orders) or the Site's EIC. A system-generated PM Task has no human creator, so for those it's the EIC.
- **When**: only before the vendor has started the work. After that, the only way to end it is Reject.
- **Reason**: always required.
- Still open: the cut-off point for an E-Call, and whether cancelling a PM Task creates the next one and from what date.
- E-Call cut-off answered: it can be cancelled **until the EIC approves the FSR/BOQ**.
- PM Task answered: cancelling does **not** automatically create the next task. The **EIC picks the next due date** when cancelling (or later). Until then, that Equipment has no scheduled PM.
- Work Order cut-off: cancellable while `Open`, `For Lvl1 Endorsement` or `For Lvl2 Endorsement` (before `For Implementation`).

- 2026-11-22 (product-owner): `Blocked by` corrected from `none` to `08, 12, 18`. Cancelling needs PM Tasks (08), E-Calls (12) and Work Orders (18) to exist. It can also be split per record type later if that helps delivery.
