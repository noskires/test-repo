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

## Alignment items to settle with this issue (added 2026-11-22, product-owner, from the issue 11 and 17 reviews)

These are inconsistencies in authorization rules already merged. Settle them together when real roles and Site scoping arrive. They're acceptance-criteria candidates and open notes, and they don't change this issue's `needs-info` status.

1. **Vendor staff can never act as EIC: RESOLVED by issue 37** (human, 2026-11-22: fix now, before issue 18, not with SSO). Kept below for history. E-Call review excludes vendor staff (`ECall::isReviewer` requires `vendor_id === null` plus the current Site EIC, issue 17). PM Task validation doesn't: issue 11's `PmTaskPolicy::validate` and `PmTask::validateReport` only check `SiteSetting::isCurrentEic`, so a user with a `vendor_id` whose `employee_id` matches the Site EIC could approve a PM Report. Candidate criterion: *"A user with a `vendor_id` is never treated as an EIC on any record (PM Task, E-Call, Work Order). Approve, return, reject and validate return 403, including inside the in-lock re-check."* The simplest way is one shared check, e.g. moving the vendor exclusion into `SiteSetting::isCurrentEic` or a single `isActingEic` helper used everywhere. Optionally, also reject assigning a vendor-staff employee as a Site EIC (issue 05). Note: this could be fixed sooner as a small chore if the human wants. It doesn't depend on SSO.
2. **One rule for who can read approval history (open question for the human).** Today the two rules differ:
   - E-Call history (`GET /api/e-calls/{eCall}/approvals`, issue 17) is readable by anyone who can view the E-Call (`ECallPolicy::view`). That's currently any non-vendor user, plus the E-Call's own Vendor's staff.
   - PM Task history (`GET /api/pm-tasks/{task}/approvals`, issue 11) is limited to the current Site EIC plus the task's Vendor's staff.

   Pick one rule when Site scoping arrives (the "visible Sites" question above). For example: "anyone who can view the record (Site-scoped) can read its history", or "only the parties involved (current EIC, Vendor staff, Requestor, approvers)". Then apply it to both, and later to Work Orders.
3. **Morph map needs a `user` alias (technical note, acceptance-criterion candidate).** `AppServiceProvider` *enforces* the morph map (issue 11), which today only has the approval subjects (`pm_task`, `e_call`). Sanctum personal access tokens (`tokenable`) and database notifications (`notifiable`) store a polymorphic reference to `User`. With an enforced map and no `user` alias, those would throw. Candidate criterion: *"The morph map has a `user` alias before Sanctum tokens or database notifications are introduced, with a test that a token or notification for a User is stored with type `user`."* (Adding Sanctum still needs the human's approval as a new package, Q5.)
4. **Data dependency from issue 37 (acceptance-criterion candidate).** `SiteSetting::isActingEic` refuses anyone with a `vendor_id`. So the login mapping built here must **never give an internal employee a `vendor_id`**. Only vendor staff get one. Otherwise that employee could never act as EIC (and couldn't be reached as one). Candidate criterion: *"After SSO login, internal employees have `vendor_id = null`, and only users identified as vendor staff have a `vendor_id`, with a test for each."* The open question Q3 (how a vendor identity maps to a PSS Vendor) must decide how vendor staff are told apart from employees.
