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
