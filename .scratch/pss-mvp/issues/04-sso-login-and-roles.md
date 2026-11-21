# 04 — Login via the existing system (SSO) and PSS roles

Status: needs-info
Blocked by: none
Type: task

## What to build

Users (employees **and** vendor staff) sign in through the existing system's login (SSO). PSS then needs to know, for the signed-in user: their external `employee_id`, which roles they hold, and, for vendor staff, which Vendor they belong to. This replaces the placeholder Gates that earlier issues test against, and unblocks the Angular screens.

PRD roles (Module 9): System Administrator, Requestor, Engineer-in-Charge, Vendor, Supervisor / Team Head, Division Head, Center Head, Sector Head, Management / Viewer.

## Open questions (for the human)

1. What SSO protocol does the existing system use (OIDC, SAML, CAS, token from the existing API)? What are the client registration details?
2. Where do **roles** come from: the external system, or does PSS assign them itself (e.g. an admin screen mapping employee to role)? Approver roles probably follow the Immediate Head chain. But who is a **System Administrator** or a **Management / Viewer**?
3. How is a vendor staff login linked to a PSS **Vendor**? Does the SSO identity carry a vendor/company ID, or does a PSS admin link the accounts by hand?
4. Is anyone allowed to file an E-Call (glossary: "any user"), or only a specific set of employees?
5. Should the Angular app use token auth (bearer token from SSO) or a cookie session? This decides whether Sanctum or another package is needed, and adding packages needs approval.

## Acceptance criteria (draft)

- [ ] A user can sign in through SSO, and PSS gets `employee_id` (and `vendor_id` for vendor staff).
- [ ] The Gates used by earlier issues (`manage-vendors`, `manage-site-settings`, `manage-checklists`, `manage-price-lists`, `manage-billing-thresholds`) resolve from the real role source.
- [ ] Vendor staff can only see records belonging to their Vendor.

## Comments

## Decisions (grilling, 2026-11-22)
- Login is the **same as the existing system** (SSO), for everyone.
- Q3 partly answered: **vendor staff are also in the existing system** and log in the same way. How the identity maps to a PSS Vendor is still open.
- Q4 answered: **anyone** can file an E-Call. The Site's EIC reviews it.
- Q2 answered: **System Administrator and Management/Viewer roles are granted inside PSS** (a first admin is seeded at install, then admins grant roles in a PSS screen). Approver roles come from the Immediate Head chain.
- Still open, waiting on API facts: how a user's **visible Sites** are determined (dashboard/report filtering, issue 29). The user wants to check what the API provides.
