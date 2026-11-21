# 16 — Site Vendor submits the FSR and BOQ for an E-Call

Status: ready-for-agent
Blocked by: 12, 14
Type: task

## What to build

PRD §7.3: after assessing the E-Call, the Site Vendor submits:
- a **Field Service Report (FSR)**: an uploaded document plus assessment notes.
- a **Bill of Quantities (BOQ)**: lines picked from **their own active Price List items**, each with a quantity. The unit price is **copied** onto the line at submission. Line total = qty x unit price. BOQ total = sum of the lines.

Endpoint: `POST /api/e-calls/{eCall}/proposal`. Allowed when the E-Call Status is `For Vendor Assessment` or `Returned`. The E-Call Status then goes to `For EIC Review`. A resubmission after Return creates a new BOQ revision and keeps the previous FSR/BOQ.

## Acceptance criteria

- [ ] Only staff of the E-Call's Site Vendor can submit. Others get 403.
- [ ] Submitting in any other status returns 422.
- [ ] Using a Price List item from another Vendor, or an inactive one, returns 422.
- [ ] A quantity <= 0 returns 422. A missing FSR file returns 422.
- [ ] Line totals and the BOQ total are computed by the server exactly (test with decimal quantities and prices).
- [ ] Changing a Price List price after submission does not change the BOQ.
- [ ] Status becomes `For EIC Review`. Resubmitting after `Returned` creates a new BOQ revision and keeps the old one readable.

## Comments
