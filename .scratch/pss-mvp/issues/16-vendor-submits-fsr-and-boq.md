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

- 2026-11-22 (product-owner): Re-checked before hand-off (main 0180d0e). Blockers 12 and 14 are merged. Still ready-for-agent. Clarifications from the merged code and the PRD, scope unchanged:
  - **Conflict recorded in spec.md**: PRD v2 §10 says off-list BOQ lines are "allowed but flagged for the EIC". GLOSSARY "Bill of Quantities" says a BOQ lists Price List items, and the glossary wins. This slice builds **only lines that reference the Site Vendor's own active Price List items**, as the criteria already say. If the human confirms that off-list lines are wanted, they'll be a separate follow-up issue, not part of this one.
  - **Money**: prices are stored as integer `unit_price_centavos` (issue 14). Copy that onto the line. Never use floats.
  - **Decimal quantities and exact totals**: the PRD sets no quantity precision or rounding rule. So that nothing is rounded silently, accept quantities with up to **3 decimal places** (configurable in `config/pss.php`, stated in the PR) and store them as a fixed-scale decimal or scaled integer. Store line totals and the BOQ total **exactly, without rounding** (e.g. in thousandths of a centavo, or a decimal column with enough scale). Do the arithmetic as integers or with bcmath, never with floats. Rounding to whole centavos is an open question for the human. It isn't needed until a Work Order or billing amount uses the total (issues 18, 21), so it doesn't block this slice.
  - **What a submission is**: each submission (first or after `Returned`) is one **revision** made of one FSR file, assessment notes and at least one BOQ line. Revision numbers start at 1 and increase. Earlier revisions stay readable. Expose them, e.g. through `GET /api/e-calls/{eCall}` or a proposal listing; the shape is the developer's choice. An empty line list returns 422.
  - **FSR file**: reuse the E-Call attachment rules in `config/pss.php` (extensions, max size). The FSR is required on every submission.
  - **Who counts as Site Vendor staff**: a user whose `vendor_id` equals the E-Call's captured `vendor_id` (as in `ECallPolicy`). Any other user, including the EIC and the Requestor, gets 403.
  - **`Returned` status**: only issue 17 sets it. Tests put the E-Call into `Returned` directly.
  - Write no Approval Record here. Approval Records come in issue 11, and 16 isn't blocked by it. Record the submitter and submission time on the revision itself.
