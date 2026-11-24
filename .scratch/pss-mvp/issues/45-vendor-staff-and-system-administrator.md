# 45 — Vendor-linked users and the System Administrator role

Status: done
Merged: PR #46 (c387d46), 2026-11-24
Blocked by: 46
Type: task

## What to build

Issue 04 (merged, PR #43) lets an admin grant any PSS Role to any user, including vendor staff (a user with a `vendor_id`). A vendor-linked System Administrator could then manage Vendors, Site settings, Price Lists and Billing Thresholds, including their own Vendor's Price List and assignments. The human decided to refuse it (2026-11-24). This changes merged behaviour.

Management / Viewer stays allowed for vendor staff (Q3); their Reports are limited to their own Vendor (issue 29).

## Acceptance criteria

- [x] `POST /api/admin/users/{user}/roles` with `system-administrator` for a vendor-linked user (`access: vendor`) returns 422 with a clear message, and no role is stored (Q2).
- [x] `PUT /api/admin/users/{user}/vendor` with a `vendor_id` for a current System Administrator returns 422 with the message "Revoke the System Administrator role first.", and nothing changes (Q11). Unlinking (`null`) is unaffected.
- [x] Granting `management-viewer` to a vendor-linked user still succeeds (Q3, regression test).
- [x] A new artisan command (e.g. `php artisan pss:check-vendor-admins`) lists every vendor-linked user who holds `system-administrator` (employee ID, name, Vendor) and **changes nothing** (Q12). It prints a clear "none found" line when there are none and exits 0 in both cases. Test both.

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
- 2026-11-24 (product-owner), re-checked after issue 46 merged (PR #45). Clarifications for dev-lead:
  - **Read issue 46's "Implementation decisions"** (`issues/46-admin-created-users-and-pending-access.md`), especially 2 (`User::linkToVendor` / `markInternal`, the `saving` hook), 8 (vendor create goes through `linkToVendor` in a transaction) and 11 (the pending-user role refusal lives in `GrantUserRoleRequest::after()`, and `User::grantRole` itself doesn't refuse).
  - **Where the refusals go:** the System Administrator grant refusal goes in `GrantUserRoleRequest::after()` next to the pending check (422 on `role`). The link refusal goes with the existing EIC check used by `PUT /api/admin/users/{user}/vendor` (422 on `vendor_id`). "Vendor-linked" means `access = vendor` (equivalently `vendor_id` set; the model hook keeps them in step).
  - **`POST /api/admin/users` needs nothing new:** it creates users without roles, so neither refusal can be bypassed there. One regression test (create with `vendor_id`, then grant `system-administrator` → 422) is enough.
  - **Unlinking a System Administrator** isn't affected by this issue. After 45 no System Administrator can be vendor-linked, so the "unlinked admin becomes pending" gap noted in 46 can only come from data created before this issue; the check command lists such users.
  - **Check command output:** one line per user (employee ID, name, Vendor name), sorted by employee ID; exit 0 whether or not any are found. Read-only: test that no row changes.
  - **Decisions to confirm** (product-owner defaults; put them in the PR's "Decisions to confirm"):
    1. `pss:setup-first-admin` stays unchanged (Q17) and doesn't apply the new refusal, because it only runs at install. If the configured employee is vendor-linked, it still grants the role but prints a warning naming the check command. The human may prefer it to refuse.
    2. The refusal applies only to `system-administrator`. Revoking it from a vendor-linked user found by the check is allowed as before (the last-admin rule still applies).
- 2026-11-24 (qa-45): QA PASS. Backend `php artisan test --compact`: 1220 passed (5643 assertions); `vendor/bin/pint --test` passed; `tests/Feature/VendorAdminRefusalTest.php` 8 passed. Concern: the `pss:setup-first-admin` warning has no test (not an acceptance criterion).

## Implementation decisions

1. **Grant refusal** is in `GrantUserRoleRequest::after()` beside the pending check: 422 on `role` ("A user linked to a Vendor cannot be a System Administrator. Unlink the Vendor first.") when the user has a `vendor_id` and the role is `system-administrator`. Nothing is stored. `User::grantRole` itself does not refuse, so `pss:setup-first-admin` still works.
2. **Link refusal** is in `User::linkToVendor`, inside its transaction next to the EIC check: 422 on `vendor_id`, "Revoke the System Administrator role first.". It therefore covers `PUT .../vendor` and the vendor create path. Unlinking (`null`) is untouched.
3. **`pss:check-vendor-admins`** (`App\Console\Commands\CheckVendorAdmins`): read-only; prints a count line then one `EMPLOYEE_ID | Name | Vendor name` line per user, sorted by employee ID; "No vendor-linked System Administrators found." when none; exit 0 both ways.
4. **PO defaults confirmed:** `pss:setup-first-admin` is unchanged except a warning naming the check command when the configured employee is vendor-linked (it still grants the role; the human may prefer it to refuse). Only `system-administrator` is refused; revoking it from a vendor-linked user is allowed as before (last-admin rule applies).
5. Tests: `backend/tests/Feature/VendorAdminRefusalTest.php`. No migration, no data change, no frontend change.
