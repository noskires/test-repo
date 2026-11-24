# Login uses JWT with a swappable credential checker, and roles are stored in PSS

The human decided (issue 04 grilling, 2026-11-24) how users sign in and how PSS knows what they may do.

- **Tokens:** JWT via `tymon/jwt-auth` (package approved by the human). The access token lives 60 minutes. `POST /api/auth/refresh` works within a 14-day refresh window, and logout blacklists the token. The Angular app keeps the token in localStorage and sends it as a Bearer header (issue 43). Sanctum is not used.
- **Credentials:** checked through a `CredentialChecker` port, the same pattern as `MasterDataDirectory` (ADR 0001). The local version checks hashed passwords on `users` and runs only in `local` and `testing`. The real external-system version is issue 44, and the login flow doesn't change when it arrives.
- **Users:** a PSS `User` is created on first sign-in, keyed by the external `employee_id`, with the name from `MasterDataDirectory`. The first System Administrator comes from `PSS_FIRST_ADMIN_EMPLOYEE_ID` via an artisan setup command.
- **Roles:** PSS stores only two granted roles, **System Administrator** (every `manage-*` gate plus granting roles) and **Management / Viewer** (Reports, issue 29). The other PRD roles are derived: Requestor is anyone, the EIC comes from Site settings, Vendor comes from `vendor_id`, and approvers come from the Immediate Head chain. The last System Administrator can't lose the role.
- **Vendor staff:** a System Administrator links each vendor staff account to a Vendor by hand. Sign-in never sets `vendor_id`. A current Site EIC can't be linked (issue 37: vendor staff never act as EIC).
- **Visibility:** every internal employee sees every Site. Vendor staff see only their own Vendor's records. Anyone who can view a record can read its approval history.

## Considered Options

- **Sanctum tokens or a cookie session:** rejected. The human chose JWT, and it gives a fixed expiry with a refresh window without a token table.
- **Roles from the external system:** rejected for now. The external API doesn't provide PSS roles, and the human wants them granted inside PSS (2026-11-22).
- **Telling vendor staff apart automatically from the identity:** rejected (Q3). It isn't known whether the external identity carries a vendor ID. A manual link is explicit and means an internal employee never gets a `vendor_id` by accident.
- **Per-Site visibility for internal employees:** rejected (Q5). No Site-scoping data is available from the API, and the MVP doesn't need it.

## Consequences

- Earlier issues' placeholder gates now resolve from real roles. Their tests that define gates directly keep working.
- PM Task approval history is widened from "current EIC and the task's Vendor" to "anyone who can view the task", matching E-Calls and Work Orders.
- Production sign-in needs issue 44. Until then, only local and testing environments can sign in.
- Changing a user's roles or Vendor link takes effect on their next request, because the token carries only the user's id.
