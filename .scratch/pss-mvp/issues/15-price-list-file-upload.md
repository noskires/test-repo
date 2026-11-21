# 15 — Upload a Price List from a file

Status: needs-info
Blocked by: 14
Type: task

## What to build

Glossary: administrators can maintain a Price List "by uploading a file", as well as by hand (issue 14).

## Open questions (for the human)

1. What file format (CSV, XLSX)? Is there an existing template the vendors already use? Please share a sample.
2. Does an upload **replace** the whole Price List (deactivating items that aren't in the file) or **merge** (add new items and update prices by code)?
3. If some rows are invalid, should the whole file be rejected, or valid rows imported with an error report?
4. Reading XLSX needs a package (e.g. `phpoffice/phpspreadsheet`). Is adding it approved?

## Acceptance criteria (draft)

- [ ] Uploading a valid file creates or updates items per the agreed rule, with a feature test using a fixture file.
- [ ] Invalid rows are handled per the agreed rule.

## Comments

## Decisions (grilling, 2026-11-22)
- Q2 answered: an upload **merges**. It adds new items and updates prices by item code. Items not in the file stay unchanged.
- Q3 answered: **all or nothing**. If any row is invalid, nothing is imported and the admin gets a per-row error list.
- Q4: XLSX support is implied by the user choosing XLSX export (issue 29). Confirm the package.
