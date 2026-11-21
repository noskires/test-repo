# 14 — Vendor Price List (manual entry)

Status: ready-for-agent
Blocked by: 01
Type: task

## What to build

Administrators maintain each Vendor's **Price List** (glossary): contract-agreed items, each with code (optional), description, type (`material` or `service`), unit of measure and unit price in PHP. A BOQ (issue 16) is built from these items.

- `GET/POST /api/vendors/{vendor}/price-list-items`, `PATCH /api/price-list-items/{item}` (including deactivating). Writes need the `manage-price-lists` Gate.
- Items are deactivated, never deleted.
- Changing a unit price must not change any BOQ or Work Order already built. Those copy the price at the time they are made (enforced in issues 16 and 18. Here, just make sure nothing cascades).

File upload is issue 15 (needs-info).

## Acceptance criteria

- [x] Creating an item with description, type, unit and a unit price >= 0 returns 201. A missing field or negative price returns 422.
- [x] Money is stored exactly (decimal with 2 places, or integer centavos), with a test using a value like 1234.56.
- [x] Duplicate code within the same Vendor returns 422. The same code at a different Vendor is fine.
- [x] Listing supports `?type=` and `?active=` filters.
- [x] Writes return 403 when the Gate denies.

## Comments

- 2026-11-22 (qa): QA PASS. Ran `php artisan test` from backend/: 202 passed (707 assertions), 0 failed, 0 skipped. Every acceptance criterion maps to a test that asserts it. Money is stored as integer centavos (1234.56 is stored as 123456).
