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

- 2026-11-22 (product-owner): Re-checked before hand-off. Blocker 05 is merged. Clarifications, scope unchanged:
  - **User identity columns**: the spec says each user carries an external `employee_id`, and vendor staff also carry a `vendor_id`, but the `users` table doesn't have them yet. This slice adds both as **nullable** columns: `employee_id` is a string, `vendor_id` is a foreign key to `vendors`. Tests set them through the factory. How they get filled at login is still issue 04's job, so don't build any login or SSO logic.
  - The E-Call stores the Requestor as the user ID **and** their `employee_id` at filing time, because the Work Order's approval chain (issues 18, 19) starts from the Requestor's employee ID.
  - "Vendor staff" means a user whose `vendor_id` is set. They list and show only E-Calls whose captured Site Vendor is their Vendor. Other users (Requestors, EICs) aren't restricted to their own Sites yet, because that rule depends on issue 04 ("how a user's Sites are determined"). Don't invent a scope rule.
  - E-Call Status values follow PRD v2 §7: For Vendor Assessment, For EIC Review, Approved, Closed, Returned, Rejected, Cancelled. This slice only sets the initial `For Vendor Assessment`.
  - Attachments: accept images and PDF/Office documents, stored with `Storage::fake()` in tests. The PRD gives no size limit. Pick a sane configurable maximum (e.g. 10 MB per file) and state it in the PR, so it's visible rather than hidden.
- 2026-11-22 (product-owner): Picked as the next slice after issue 14 merged (main 20cde31). Its only blocker (05) is merged. Chosen over 06 because it unblocks 16 directly (16's other blocker, 14, is merged), and also 13 and 25. 06 only unblocks 08, which is still needs-info.
