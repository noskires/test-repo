# 45 — Vendor-linked users and the System Administrator role

Status: needs-info
Blocked by: 04
Type: task

## What to build

Issue 04 (merged, PR #43) lets an admin grant any PSS Role to any user, including vendor staff (a user with a `vendor_id`). A vendor-linked System Administrator could then manage Vendors, Site settings, Price Lists and Billing Thresholds, including their own Vendor's Price List and assignments. This follow-up changes merged behaviour if the human decides to refuse it.

## Open questions (for the human)

1. Should a vendor-linked user be **refused the System Administrator role**? The team lead recommends refusing. Issue 04 shipped allowing it (a product-owner default listed under "Decisions to confirm").
2. If yes: should linking an existing System Administrator to a Vendor also be refused (the mirror case), with a 422 telling the admin to revoke the role first?
3. Same question for **Management / Viewer**: may vendor staff see Reports (issue 29, filtered to their own Vendor), or should that role be refused too?

## Acceptance criteria (draft, if Q1 is yes)

- [ ] `POST /api/admin/users/{user}/roles` with `system-administrator` for a user with a `vendor_id` returns 422 with a clear message, and no role is stored.
- [ ] (If Q2 is yes) `PUT /api/admin/users/{user}/vendor` for a current System Administrator returns 422, and nothing changes.
- [ ] (If Q3 is yes) the same refusals for `management-viewer`.
- [ ] Existing data: a check (test or artisan command) shows whether any vendor-linked user already holds the role. How to handle any found is decided with the human.

## Comments

- 2026-11-24 (product-owner): raised by the team lead after issue 04 merged. Kept separate from 04 because it changes merged behaviour.
