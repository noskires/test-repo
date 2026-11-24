# 45 — Vendor-linked users and the System Administrator role

Status: ready-for-agent
Blocked by: 46
Type: task

## What to build

Issue 04 (merged, PR #43) lets an admin grant any PSS Role to any user, including vendor staff (a user with a `vendor_id`). A vendor-linked System Administrator could then manage Vendors, Site settings, Price Lists and Billing Thresholds, including their own Vendor's Price List and assignments. The human decided to refuse it (2026-11-24). This changes merged behaviour.

Management / Viewer stays allowed for vendor staff (Q3); their Reports are limited to their own Vendor (issue 29).

## Acceptance criteria

- [ ] `POST /api/admin/users/{user}/roles` with `system-administrator` for a vendor-linked user (`access: vendor`) returns 422 with a clear message, and no role is stored (Q2).
- [ ] `PUT /api/admin/users/{user}/vendor` with a `vendor_id` for a current System Administrator returns 422 with the message "Revoke the System Administrator role first.", and nothing changes (Q11). Unlinking (`null`) is unaffected.
- [ ] Granting `management-viewer` to a vendor-linked user still succeeds (Q3, regression test).
- [ ] A new artisan command (e.g. `php artisan pss:check-vendor-admins`) lists every vendor-linked user who holds `system-administrator` (employee ID, name, Vendor) and **changes nothing** (Q12). It prints a clear "none found" line when there are none and exits 0 in both cases. Test both.

## Decisions (human, 2026-11-24)

- Q2: granting System Administrator to a vendor-linked user gets 422.
- Q11: linking a current System Administrator to a Vendor gets 422 ("revoke the System Administrator role first").
- Q3: Management / Viewer stays allowed for vendor staff. Reports are limited to their own Vendor (issue 29).
- Q12: add a check (artisan command) that lists existing vendor-linked System Administrators and changes nothing.
- Blocked by 46 (admin-created users and the `access` state), which ships first.

## Comments

- 2026-11-24 (product-owner): raised by the team lead after issue 04 merged. Kept separate from 04 because it changes merged behaviour.
- 2026-11-24 (product-owner): the human answered the open questions (above). Now ready-for-agent, blocked by 46. Clarifications for dev-lead:
  - Read issue 04's "Implementation decisions" 10 to 12 and issue 46's criteria for the admin API shapes you are changing.
  - Put both refusals in the same place as the existing vendor-link rules (current Site EIC check), not in the controllers.
  - The check command is read-only. What to do with any admin it finds is the human's call; don't revoke or unlink anything.
  - Issue 29 must filter a vendor-linked Management / Viewer's Reports to their own Vendor. That belongs to 29, not this issue; the product-owner added it there.
