# Master data is owned by the external API; PSS stores only its own data plus Snapshots

The PRD describes PSS as owning an Equipment Registry, sites and users. However, an existing system already holds Sites, Equipment (including categories, installation dates and operating status) and employees (including each person's Immediate Head), and it provides login for employees and vendor staff. So PSS has **no master tables or CRUD screens** for any of these. It reads them through one directory port and keys its own records by the external IDs.

PSS stores only what the external system doesn't have:
- per-Site assignments: Site Vendor, EIC and PM Frequency
- Vendors and Price Lists
- Billing Thresholds and the Admin/Viewer roles
- all workflow records: PM Tasks, E-Calls, Work Orders and Approval Records

Whenever a PSS record points at a Site or Equipment, it also stores a **Snapshot** of that item's display details. Maintenance history then still reads correctly after the item is renamed or removed upstream. A removed item's open PM Task is flagged for the EIC, not cancelled automatically.

## Considered Options

- **Copy master data into PSS and own it there**: rejected because it creates two sources of truth that drift apart, and the org chart (which approval routing depends on) would go stale.
- **Read live with no Snapshots**: rejected because history would show bare IDs once an item disappears upstream.

## Consequences

- Approval routing depends on the external Immediate Head chain. Approvers are captured when a Work Order is submitted, and a missing level blocks the Work Order until the chain is fixed in the external system, not in PSS.
- Building the real integration is blocked until we have the API's URL, auth and response shapes. Until then, tests use an in-memory fake of the directory port.

## Amendment (2026-11-23)

The human decided (issue 19) that Work Order endorsers are **resolved from the E-Call Requestor's Immediate Head chain at each step**, at the time each endorser acts, **not captured when the Work Order is submitted**. This replaces the first sentence of the first Consequence above. It matches the decision that the **current** Site EIC acts on pending items (issue 11). The rest of that Consequence still holds: a short or missing chain **blocks** the Work Order (a 422 at submit or at the waiting level), and it is **flagged** on the admin Needs Setup list (issue 32) until the chain is fixed in the external system, not in PSS.

## Amendment (2026-11-24): master data is imported into PSS tables

The human decided (issue 03 rewrite) that the master data lives in a **different database**, not behind an HTTP API, and that PSS **imports** it:

- A second, read-only DB connection reads the source. `php artisan pss:import-master-data` runs nightly (and by hand) and **upserts** Sites, Equipment Categories, Equipment and Employees into PSS tables, keyed by the source ID. `MasterDataDirectory` now serves from those tables. Tests may keep the in-memory fake.
- Equipment goes into one `equipment` table. Its category comes from the source table (air-conditioning units → ACU, gensets → Genset, ATS → ATS), and type-specific fields go in a `details` JSON column. Building is left out for now.
- The source schema is guessed and kept behind **one mapping class**, so only that class changes when the real schema is known.
- Rows that disappear from the source become **inactive, never deleted**. Inactive Equipment gets no new PM Tasks or E-Calls. A PSS user is never deactivated automatically.

This replaces "PSS has no master tables" above. **PSS still doesn't own the master data**: the imported tables are a read-only copy refreshed from the source, with no CRUD screens. The source stays the single source of truth, so the "two sources of truth" objection still holds, and is answered by the one-way nightly import. The org chart can be up to a day stale; endorsers are still resolved at each step (2026-11-23 amendment). Snapshots stay: they still protect history when a source row is renamed. Login no longer comes from the existing system (ADR 0004 amendment, 2026-11-24).

The Consequence "building the real integration is blocked until we have the API's URL, auth and response shapes" no longer applies.
