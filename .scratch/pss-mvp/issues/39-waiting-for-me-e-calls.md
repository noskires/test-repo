# 39 — "Waiting for me": E-Call items

Status: ready-for-agent
Blocked by: 24
Type: task

## What to build

Add an E-Call provider to the "Waiting for me" list (issue 24). It plugs into 24's provider mechanism, with no endpoint change. Who acts on an E-Call in each status, as shipped in issues 12, 16, 17 and 18:

- **For Vendor Assessment** and **Returned**: the staff of the E-Call's Vendor (`e_calls.vendor_id`), who submit the FSR and BOQ (`ECallStatus::acceptsProposal`).
- **For EIC Review**: the Site's **current** EIC (`SiteSetting::isActingEic`), never vendor staff.
- **Approved**: the E-Call's Requestor (`requestor_user_id`), who creates the Work Order (issue 18).
- Closed, Rejected and Cancelled: nobody.

## Acceptance criteria

- [ ] Each of the four statuses above shows the E-Call to exactly the right user(s) and to nobody else (one test per status, including another Vendor's staff and a non-EIC employee).
- [ ] A replaced EIC no longer sees a For EIC Review E-Call, and the new EIC does.
- [ ] Closed, Rejected and Cancelled E-Calls appear for nobody.
- [ ] Item shape: `type` = `e_call`, `id`, `title` (nature of issue), `site` (from the Snapshot), `status`, `priority`, and `waiting_since` = the latest of the E-Call's `created_at`, its latest proposal's `submitted_at` and its latest Approval Record's `created_at`. Items sort together with PM Task items, oldest first.
- [ ] No change to the `GET /api/me/waiting` controller. `php artisan test` passes.

## Comments
- 2026-11-23 (product-owner): Split out of issue 24, because issues 12–23 shipped without adding providers. Each rule comes from shipped behaviour; there are no open questions.
