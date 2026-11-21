# 12 — File an E-Call

Status: ready-for-agent
Blocked by: 05
Type: task

## What to build

Any signed-in user (**Requestor**) files an **E-Call** at a Site (PRD §7.1, glossary):
- Site (required, must exist in the directory), Equipment (optional, must belong to that Site), nature of the issue, description of the required service, **Priority** (`Emergency`, `High`, `Normal`, `Low`), attachments (photos and documents).
- Optional reference to a PM Task (used by issue 13).
- Automatically routed to the Site's **Site Vendor** and **EIC** at filing time. Filing fails if the Site has no Site Vendor or EIC.
- Stores Site and Equipment **Snapshots**.
- Initial **E-Call Status** `For Vendor Assessment` (see GLOSSARY "E-Call Status").

Endpoints: `POST /api/e-calls`, `GET /api/e-calls/{id}`, `GET /api/e-calls` (filters: site, status, priority). Vendor staff see only E-Calls routed to their Vendor.

## Acceptance criteria

- [ ] A valid E-Call with no Equipment is accepted (e.g. "build a new fence").
- [ ] An Equipment that isn't at the given Site returns 422. An unknown Site returns 422.
- [ ] A Priority outside the four values returns 422.
- [ ] The E-Call stores the Requestor, the Site Vendor and EIC at filing time, the snapshots and the attachments.
- [ ] A Site with no Site Vendor or EIC returns 422 with a clear message.
- [ ] Vendor staff listing E-Calls see only their Vendor's. Others' E-Calls return 403 on show.
- [ ] Feature tests use the directory fake and `Storage::fake()`.

## Comments
