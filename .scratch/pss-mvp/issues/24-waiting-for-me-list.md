# 24 — "Waiting for me" list

Status: ready-for-agent
Blocked by: 11
Type: task

## What to build

The MVP has no email notifications. Each user instead has an in-app list of the items waiting for their action (PRD §3.7 "Approvals", MVP decision in spec).

`GET /api/me/waiting` returns items for the acting user, each with type, ID, title, Site Snapshot name, current status and "waiting since". This slice covers the item types that exist once issue 11 is merged:
- PM Tasks in `For Validation` where I am the EIC.
- (Vendor staff) PM Tasks in `Scheduled`, `In Progress` or `Returned` assigned to my Vendor.

Build it as an extensible list of "waiting item providers", so that later issues (12–23) each add their own provider with a test (E-Calls for vendor assessment and EIC review, Work Orders for endorsement / implementation / validation / billing).

## Acceptance criteria

- [ ] An EIC sees only PM Tasks for validation where they are the EIC.
- [ ] Vendor staff see only their own Vendor's open PM Tasks.
- [ ] A Completed task disappears from the list.
- [ ] Items are sorted oldest-waiting first.
- [ ] Adding a new provider requires no change to the endpoint, shown by a test with a dummy provider.

## Comments
