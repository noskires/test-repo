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

- [x] Only staff of the E-Call's Site Vendor can submit. Others get 403.
- [x] Submitting in any other status returns 422.
- [x] Using a Price List item from another Vendor, or an inactive one, returns 422.
- [x] A quantity <= 0 returns 422. A missing FSR file returns 422.
- [x] Line totals are computed by the server exactly, never rounded (test with decimal quantities and prices). The BOQ total is the exact sum of the lines, rounded once to the whole centavo, half-up, and stored as integer centavos (ADR 0002). E.g. two lines of qty 0.5 x 101 centavos give a total of 101 (not 102), and one such line gives 51.
- [x] Changing a Price List price after submission does not change the BOQ.
- [x] Status becomes `For EIC Review`. Resubmitting after `Returned` creates a new BOQ revision and keeps the old one readable.

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
- 2026-11-22 (qa): QA PASS. Ran `php artisan test` from backend/: 326 passed (1203 assertions), 0 failed. `tests/Feature/ECallProposalApiTest.php` and `tests/Unit/Billing/BoqQuantityTest.php`: 63 passed (214 assertions). Every acceptance criterion maps to a test that asserts it. Quantities allow up to 3 decimal places (`pss.boq.quantity_decimal_places`), a BOQ holds at most 200 lines (`pss.boq.max_lines`), and totals are stored exactly as integer millicentavos. Rounding to whole centavos is still open for issues 18 and 21.
- 2026-11-22 (product-owner): The human answered after QA PASS:
  - **Rounding**: line totals stay exact. **Only the BOQ total is rounded** to the centavo, half-up (docs/adr/0002-money-rounding.md). I've replaced the totals criterion and **un-ticked** it, so QA must re-verify it against dev-lead's rework. dev-lead was told during the build.
  - The **3-decimal quantity limit** is fine.
  - **Off-list BOQ lines are not wanted**: Price List items only. The glossary stands, and the spec conflict row is marked resolved. No follow-up issue.
- 2026-11-22 (qa): The first PASS above is withdrawn. It was replaced after the human decided that the BOQ total is rounded half-up to the centavo (ADR 0002). New QA PASS on commit 22694d2: `php artisan test` from backend/ gives 340 passed (1241 assertions), 0 failed. `tests/Feature/ECallProposalApiTest.php` and `tests/Unit/Billing/BoqQuantityTest.php` give 77 passed (252 assertions). Line totals stay exact, and only the BOQ total is rounded and stored as integer `total_centavos`. The API returns both `total` (exact) and `total_rounded`. One line of 0.5 x 101 centavos rounds 50.5 to 51. Two such lines give 101, not 102. 0.00500 rounds to 0.01, 0.00499 to 0.00, and 0.004 + 0.004 to 0.01. Unit boundary cases pass, and PHP_INT_MAX doesn't overflow. Whether the same Price List item may appear twice in one BOQ is still open with the human. It currently returns 422.

## Decisions (human, 2026-11-22)
- **No duplicate Price List items in one BOQ revision (confirmed)**: the same Price List item can't appear on two lines of the same revision. The submission returns 422, and the vendor combines the quantities into one line. This keeps the behaviour that QA passed and that was merged in PR #13. Nothing changes in the code.
