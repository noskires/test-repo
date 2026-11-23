# 22 — Configure Billing Thresholds

Status: done
Merged: PR #6 (a612b50), 2026-11-22
Blocked by: none
Type: task

## What to build

Glossary "Billing Threshold": two amount boundaries decide how many billing endorsement levels a Work Order needs. The initial values are ₱200,000 and ₱1,000,000. Administrators can change them.

PRD §8.5 rules, with the boundaries exactly as written:
- Billing Amount **below** the lower threshold → 2 levels.
- **From** the lower **to** the upper threshold, **inclusive** → 3 levels.
- **Above** the upper threshold → 4 levels.

Build:
- Storage for the thresholds, seeded with 200,000.00 and 1,000,000.00.
- `GET /api/settings/billing-thresholds` and `PUT /api/settings/billing-thresholds` (PUT needs the `manage-billing-thresholds` Gate). Lower must be < upper, and both > 0.
- A pure function `requiredBillingLevels(amount, thresholds)` returning 2, 3 or 4.

## Acceptance criteria

- [x] Defaults are seeded at 200,000.00 and 1,000,000.00.
- [x] Unit tests: 199,999.99 → 2. 200,000.00 → 3. 1,000,000.00 → 3. 1,000,000.01 → 4.
- [x] PUT with lower >= upper, or with a non-positive value, returns 422. PUT returns 403 without the Gate.
- [x] Changes are recorded (who, when, old and new values).

## Open questions (non-blocking, for the human)

- If the thresholds change while a Work Order is already in billing endorsement, does it keep its original number of levels? This slice doesn't need the answer. Issue 23 does.

## Comments

- 2026-11-22 (qa): QA PASS after one fix round (strict parsing of "+5", "5." and ".5"). Ran `php artisan test` from backend/: 94 passed (285 assertions), 0 failed, 0 skipped. Every acceptance criterion maps to a test that asserts it.

## Decisions (grilling, 2026-11-22)
- Threshold changes **apply to Work Orders already in billing endorsement**: the required number of levels is recalculated against the current thresholds. If a Work Order has already completed the new number of levels, it Closes at once. (This affects issue 23.)
