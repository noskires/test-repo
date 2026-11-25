# 15 — Upload a Price List from a file

Status: ready-for-agent
Blocked by: 14
Type: task

## What to build

Glossary: administrators can maintain a Price List "by uploading a file", as well as by hand (issue 14).

## Open questions (for the human)

1. What file format (CSV, XLSX)? Is there an existing template the vendors already use? Please share a sample.
2. Does an upload **replace** the whole Price List (deactivating items that aren't in the file) or **merge** (add new items and update prices by code)?
3. If some rows are invalid, should the whole file be rejected, or valid rows imported with an error report?
4. Reading XLSX needs a package (e.g. `phpoffice/phpspreadsheet`). Is adding it approved?

## Decisions (human, 2026-11-24)

- A **System Administrator** uploads **.xlsx or .csv** through **one endpoint**. One file can cover **several Vendors**.
- Columns: `vendor_code`, `item_code`, `description`, `unit`, `unit_price`, `item_type` (`material` / `service`).
- Add a **`code` column to vendors** if it's missing (it is missing today).
- **Merge, all or nothing** (confirms 2026-11-22). An unknown `vendor_code` makes the row invalid.
- Items are already unique on (vendor_id, code), so the same `item_code` can have a different price for each Vendor.
- A seeder adds a **sample Price List for each sample Vendor**, plus a **sample template file** in the repo.
- `phpoffice/phpspreadsheet` is approved.

## Acceptance criteria

- [ ] Migration: `vendors.code`, unique. The Vendor create/update API accepts and validates it (unique, 422 on a duplicate). Existing Vendors keep working with no code, but rows can't match them until one is set.
- [ ] `POST /api/price-lists/upload` (multipart `file`, `.xlsx` or `.csv`), behind the System Administrator / `manage-price-lists` gate (others 403). Another file type is 422.
- [ ] A valid file **merges**: new (vendor, item_code) pairs are created, existing ones get the new description, unit, unit_price and item_type, and items not in the file are unchanged. The response returns counts (created / updated) per Vendor.
- [ ] **All or nothing**: if any row is invalid (unknown vendor_code, missing field, unit_price not a positive amount with at most 2 decimals, bad item_type, the same vendor_code + item_code twice in the file), nothing is written. The response is 422 with a list of `{row, column, message}`. The test checks the database is unchanged.
- [ ] Prices are stored exactly (ADR 0002: decimal, no float).
- [ ] Feature tests use fixture files for CSV and XLSX: a multi-Vendor valid file, an update of an existing item, and an invalid file.
- [ ] A sample template file is committed (e.g. `backend/resources/templates/price-list-template.xlsx` plus a `.csv`), and a test loads it successfully. A seeder gives each sample Vendor a code and a sample Price List. It's called from `DatabaseSeeder`.
- [ ] `composer require phpoffice/phpspreadsheet` (approved), with `composer.lock` committed.

## Comments

## Decisions (grilling, 2026-11-22)
- Q2 answered: an upload **merges**. It adds new items and updates prices by item code. Items not in the file stay unchanged.
- Q3 answered: **all or nothing**. If any row is invalid, nothing is imported and the admin gets a per-row error list.
- Q4: XLSX support is implied by the user choosing XLSX export (issue 29). Confirm the package.

- 2026-11-22 (product-owner): Human decision: **`phpoffice/phpspreadsheet` is approved** as a dependency for XLSX price-list upload (Q4 answered). Q2 and Q3 were already answered. This stays **needs-info** because Q1 is still open: the vendors' Price List template. We need a sample file showing its columns (item code, description, unit, unit price, item type material/service?) and whether CSV must also be accepted.

### Clarifications (product-owner, 2026-11-24)

- Uploads change only Price Lists. They never create Vendors.
- Reuse the issue 14 Price List model and validation rules for each row. Don't copy them.
- Record your decisions (endpoint name, header matching, size limit) under a dated "Decisions (dev-lead, ...)" heading here.

### On hold (product-owner, 2026-11-25)

- Held with issue 29: the team lead says adding `phpoffice/phpspreadsheet` still needs the human's approval, even though this file records it as approved on 2026-11-22. **Blocking question**: approve the package, or accept CSV only (then the product-owner drops the XLSX criteria and the `.xlsx` template)?

### Decisions (human, 2026-11-25)

- The human reconfirmed that **`phpoffice/phpspreadsheet` is approved** (relayed by the team lead). The hold is lifted, and this issue is back to **ready-for-agent**.
