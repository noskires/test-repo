# 47 — Remove or deactivate users

Status: ready-for-agent
Blocked by: 46, 44
Type: task

## What to build

Issue 46 lets a System Administrator add users. Nothing yet removes or deactivates one, for example someone who has left the company or a vendor staff member whose contract ended (human, 2026-11-24, Q18: out of scope for 46). Meanwhile an admin can limit a user by revoking their roles and unlinking their Vendor (which makes them pending, issue 46). Deactivation needs its own decisions.

## Open questions (for the human)

1. Deactivate (keep the record, block sign-in) or delete? Records already point at users (approvals, filings, role grants), so the product-owner expects deactivate, but this is your call.
2. What happens to a deactivated user's open work: pending approvals, E-Calls they filed, Site EIC assignments? Block deactivation until they're replaced, or allow it and flag on the Needs Setup list?
3. Should an employee who disappears from the external master data be deactivated automatically, or only by an admin?
4. Can a deactivated user be reactivated, and with which access (their old one, or pending)?
5. Does deactivating sign them out at once (existing tokens refused on the next request)?

## Decisions (human, 2026-11-24)

- **Deactivate, never delete.**
- **Only a System Administrator** deactivates or reactivates. There's **no automatic deactivation** from the master data (an employee who goes inactive in issue 03's import keeps their PSS user).
- Deactivation is **allowed even with open work**:
  - a Site whose EIC is deactivated shows on the **Needs Setup** list;
  - pending approvals stay open for other holders of that role (endorsers are resolved at each step anyway);
  - E-Calls they filed are unchanged.
- Deactivating **revokes all their tokens at once**.
- A deactivated user can't be granted roles, assigned as Site EIC or linked to a Vendor: 422 "Reactivate the user first.". They're **hidden from the pick lists**.
- **Reactivating restores** their previous access and roles.
- Both actions write a **history entry**.

## Acceptance criteria

- [ ] `POST /api/admin/users/{user}/deactivate` and `.../reactivate`, System Administrator only (others 403). Deactivating an already-deactivated user (or the reverse) returns 422. Each writes a history entry (user, action, by whom, when).
- [ ] The last active System Administrator can't be deactivated (422), the same rule as losing the role (ADR 0004). An admin can't deactivate themselves (422).
- [ ] A deactivated user can't sign in (401 with the generic message from issue 44, so the response doesn't reveal the account exists). Their existing access and refresh tokens are refused on the next request (401). A test signs in, deactivates, then calls the API with the old token.
- [ ] Granting a role, assigning them as Site EIC or linking them to a Vendor returns 422 "Reactivate the user first.". The user lists used for picking (EIC, role grants, Vendor links) leave deactivated users out. The admin user list shows them with their state.
- [ ] Reactivating restores their access state (internal / vendor + Vendor / pending) and roles exactly as before. Deactivation doesn't wipe them. Test both access kinds.
- [ ] Needs Setup (`GET /api/needs-setup`) lists a Site whose current EIC is deactivated, with a reason like "EIC is deactivated". Reactivating or replacing the EIC removes it.
- [ ] Pending endorsements and billing endorsements whose current holder is deactivated stay pending. Another holder of that role can still act, and the deactivated user can't (they can't sign in). Filed E-Calls are unchanged.

## Comments

- 2026-11-24 (product-owner): created from the issue 46 grilling (Q18).

### Clarifications (product-owner, 2026-11-24)

- Blocked by 44 as well, because both change sign-in and the admin user endpoints. Build it after 44 so the deactivated check sits in the new email login.
- Token revocation: the token carries only the user id (ADR 0004), so checking an `is_active` flag (or `deactivated_at`) on each request is enough. The dev-lead picks the mechanism and records it.
- Where things live: the admin user endpoints and access states (issue 46, ADR 0005), role grants and Vendor links (issue 45), Site EIC assignment (issue 05), Needs Setup (`App\NeedsSetup`, issue 32).
- Record your decisions under a dated "Decisions (dev-lead, ...)" heading here.
