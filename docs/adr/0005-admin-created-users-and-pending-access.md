# Users are added by an administrator, and each has an access state

The human decided (issue 45/46 grilling, 2026-11-24) to close a gap in ADR 0004: a user created on first sign-in had `vendor_id = null`, so unlinked vendor staff counted as internal employees and saw every Vendor's records.

- **No users on sign-in:** sign-in only signs in existing PSS users. A System Administrator adds each user (`POST /api/admin/users`) with an `employee_id` that exists in the master data (ADR 0001) and isn't already used, and chooses internal or a Vendor. `users.employee_id` is unique. The first System Administrator still comes from `pss:setup-first-admin`.
- **Sign-in responses:** the password is checked first. A wrong password is 401 whether or not the person is registered, so sign-in never reveals who is registered. A correct password from someone not in PSS is 403 "Your account isn't set up in PSS yet. Ask an administrator."
- **Access states:** internal, vendor, pending. Vendor exactly when `vendor_id` is set. Existing users were backfilled (vendor_id → vendor, others → internal). Unlinking a Vendor makes a user pending. Two ways out of pending, each recording who and when: link a Vendor, or set internal.
- **Pending users** can sign in but only use `auth/me`, `auth/refresh` and `auth/logout`; every other API route is 403, and they can't be granted roles. The Angular app shows a "waiting for an administrator" page.
- **System Administrator is never vendor-linked** (issue 45): granting it to vendor staff and linking an admin to a Vendor are both refused. Management / Viewer stays allowed for vendor staff, with Reports limited to their own Vendor.

This supersedes ADR 0004's "a PSS `User` is created on first sign-in".

## Considered Options

- **Keep creating users on sign-in, as pending:** rejected. Anyone with external credentials would get a PSS record, and admins would have to sort through them. Adding users by hand keeps the list to people who use PSS.
- **Unknown or unregistered employee gets 401 (issue 04 default):** replaced by checking the password first, so the 403 only reaches someone who proved their identity.
- **Unlinking a Vendor returns the user to internal:** rejected. Former vendor staff would silently see every Site; pending makes an admin decide.

## Consequences

- Issue 04's merged behaviour changes (issue 46, then 45). Test fixtures that shared an `employee_id` between two users must use distinct ids.
- Issue 44 (external credential checker) no longer has to tell vendor staff from internal employees.
- Removing or deactivating users is not decided yet (issue 47). Until then, revoking roles and unlinking a Vendor are the ways to limit a user.
