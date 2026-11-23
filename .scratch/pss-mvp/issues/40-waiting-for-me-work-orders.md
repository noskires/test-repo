# 40 — "Waiting for me": Work Order items

Status: ready-for-agent
Blocked by: 24
Type: task

## What to build

Add a Work Order provider to the "Waiting for me" list (issue 24). It plugs into 24's provider mechanism, with no endpoint change. Who acts in each status, as shipped in issues 18–23:

- **Open**: the Requestor who created the Work Order (they edit it and submit it for endorsement).
- **For LVL1 / LVL2 Endorsement** and **For Billing Endorsement LVL1–4**: the employee who holds that level **right now** in the Requestor's Immediate Head chain. Resolve it with the same code `WorkOrder::endorse` / billing endorsement use (`endorserAtLevel`), not a copy. A Work Order whose level doesn't resolve appears for nobody here. It appears on the Needs Setup list instead (issue 32).
- **For Implementation**: the staff of the Work Order's Vendor.
- **Implemented**: the Site's **current** EIC, who validates the completed work and sets the Billing Amount (issue 21).
- Closed and Rejected: nobody.

## Acceptance criteria

- [ ] Each status above shows the Work Order to exactly the right user and to nobody else (one test per status group).
- [ ] When the fake directory changes who holds a level's Immediate Head position, the item moves to the new holder.
- [ ] A Work Order with a broken chain appears for nobody, and the endpoint doesn't error.
- [ ] Closed and Rejected Work Orders appear for nobody.
- [ ] Item shape: `type` = `work_order`, `id`, `title`, `site` (from the Snapshot), `status`, and `waiting_since` = the latest of the Work Order's `created_at` and its latest Approval Record's `created_at`. Items sort together with the other types, oldest first.
- [ ] No change to the `GET /api/me/waiting` controller. Resolve the chain once per Requestor per request, not once per Work Order per level. `php artisan test` passes.

## Comments
- 2026-11-23 (product-owner): Split out of issue 24, because issues 18–23 shipped without adding providers. Each rule comes from shipped behaviour (the endorsers are the current holders, issue 19 decisions; a broken chain is blocked and flagged, issue 19/23 decisions); there are no open questions.
