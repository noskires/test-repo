# 47 — Remove or deactivate users

Status: needs-info
Blocked by: 46
Type: task

## What to build

Issue 46 lets a System Administrator add users. Nothing yet removes or deactivates one, for example someone who has left the company or a vendor staff member whose contract ended (human, 2026-11-24, Q18: out of scope for 46). Meanwhile an admin can limit a user by revoking their roles and unlinking their Vendor (which makes them pending, issue 46). Deactivation needs its own decisions.

## Open questions (for the human)

1. Deactivate (keep the record, block sign-in) or delete? Records already point at users (approvals, filings, role grants), so the product-owner expects deactivate, but this is your call.
2. What happens to a deactivated user's open work: pending approvals, E-Calls they filed, Site EIC assignments? Block deactivation until they're replaced, or allow it and flag on the Needs Setup list?
3. Should an employee who disappears from the external master data be deactivated automatically, or only by an admin?
4. Can a deactivated user be reactivated, and with which access (their old one, or pending)?
5. Does deactivating sign them out at once (existing tokens refused on the next request)?

## Acceptance criteria (draft)

To be written once the questions are answered.

## Comments

- 2026-11-24 (product-owner): created from the issue 46 grilling (Q18).
