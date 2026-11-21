# Passive Support Services (PSS): Product Requirements, v2

**Status:** MVP scope agreed · **Date:** 22 November 2026
**Supersedes:** `prd/PASSIVE SUPPORT SERVICES.docx` (initial SRS) wherever the two disagree.
**Related:** [`GLOSSARY.md`](../GLOSSARY.md) (canonical terms) · [ADR 0001](../docs/adr/0001-master-data-owned-by-external-api.md) (external master data) · [`.scratch/pss-mvp/`](../.scratch/pss-mvp/spec.md) (spec and issues)

This version folds the decisions from the PRD grilling sessions into the original SRS. Terms in **bold** are defined in the glossary.

---

## 1. What PSS is

PSS is one web platform (Laravel API, Angular frontend, MySQL) for the two maintenance processes at every **Site**:

- **Preventive Maintenance (PM)**: scheduled check-ups. **PM Tasks** are created automatically, the **Site Vendor** does the work and submits a **PM Report**, and the **EIC** validates it.
- **Corrective Maintenance (CM)**: repairs and improvements. A **Requestor** files an **E-Call**, and the Site Vendor submits an **FSR** and **BOQ** for the EIC to review. The Requestor then turns it into a **Work Order**, which is endorsed, implemented, validated and billed up the **Immediate Head** chain.

## 2. MVP scope

| In the MVP | Later |
| --- | --- |
| Check-ups (PM): generation, vendor report, EIC validation | Admin checklist editor (the MVP ships a fixed set) |
| E-Calls: filing, FSR/BOQ, EIC review | Email notifications and reminders |
| Work Orders: endorsement, implementation, validation, billing | Vendor performance and recurring-issue/reliability metrics |
| Dashboard and four reports, exported to Excel | The PRD's other reports |
| In-app **"Waiting for me"** list | Custom PM intervals |

## 3. Master data and login: the external system

Equipment, Sites, Equipment Categories, employees and login all come from an **existing external system/API** ([ADR 0001](../docs/adr/0001-master-data-owned-by-external-api.md)). The external data includes each Equipment's installation date and operational status, and each employee's **Immediate Head**.

- PSS has **no CRUD screens** for those records. It references them by external ID.
- PSS stores a **Snapshot** of Site and Equipment details on its own records, so history still reads correctly after an item is renamed or removed upstream.
- **Login is SSO** through the same system, for employees **and vendor staff**.
- PSS stores only its own data:
  - per-Site settings: Site Vendor, Site EIC and PM Frequency
  - Vendors and **Price Lists**
  - **Billing Thresholds**
  - the Admin and Viewer roles
  - all workflow records

## 4. Roles

| Role | Where it comes from | Can |
| --- | --- | --- |
| Requestor | Any employee | File an E-Call. Create, submit and cancel its Work Order. |
| EIC | Assigned per Site in PSS | Review FSR/BOQ, validate completed work, validate PM Reports, cancel (see §9). Every E-Call and check-up at the Site, whoever filed it. |
| Approvers (Team Head → Division Head → Center Head → Sector Head) | The Requestor's Immediate Head chain in the external system | Endorse or approve Work Orders and billing |
| Vendor staff | External system (SSO), linked to a PSS Vendor | Work their own Sites' PM Tasks, E-Calls and Work Orders |
| System Administrator | Granted in PSS (first admin seeded at install) | Site settings, Vendors, Price Lists, Billing Thresholds, roles |
| Management / Viewer | Granted in PSS | View dashboards and reports |

Everyone sees the dashboard and reports **filtered to their own Sites**, and vendors see only their own Sites' jobs. *(Open: how a user's Sites are determined from the API.)*

## 5. Sites and vendors

- Each Site has exactly **one Site Vendor** (the contract holder), which does all PM and CM work there. Individual jobs can't be sent to another vendor.
- Only an administrator can replace a Site's Vendor. Jobs already started stay with the old vendor, and new jobs and schedules go to the new one.
- Each Site has **one EIC**.
- A Site with no Vendor or no EIC is **skipped** by PM generation and shown on a "needs setup" list.
- **Buildings are Equipment** (category *Building Facilities*). "Asset" and "Facility" aren't separate concepts.

## 6. Preventive Maintenance (check-ups)

### 6.1 Frequency and schedule
- **One PM Frequency per Site**, which every Equipment at that Site follows. MVP options: monthly, twice a month (= **every 15 days**), every 2 months, quarterly, semi-annual, annual. There are no custom intervals in the MVP.
- **Sliding schedule:** next due date = the previous task's **Work Done Date** + the interval. Month arithmetic clamps to the month's last day (31 Jan + 1 month = 28/29 Feb).
- **First task** for newly registered Equipment: due one interval after its installation date.
- **Frequency change:** re-dates only PM Tasks still `Scheduled` (new due date = last Work Done Date + new interval). Tasks already started keep their dates.
- Generation runs as a scheduled background job (assumed daily) and never creates duplicates.

### 6.2 Checklists
- The MVP ships a **fixed set** of PM Checklists (Genset, ACU, ATS, Building) taken from the approved PM standards. The PRD §6.5 items are the starting point.
- Checklists are versioned, and each PM Task keeps the version it was generated with.
- If a category has no checklist, the task is **still generated** and flagged "no checklist". The vendor submits a report without one.

### 6.3 Workflow
**PM Status:** Scheduled → In Progress → For Validation ⇄ Returned → Completed, plus Cancelled.

- The vendor submits the PM Report: checklist responses, Work Done Date, findings, materials, service report and photos.
- The EIC **Approves** or **Returns** it. **PM Reports can't be Rejected.**
- The EIC can raise an E-Call from a PM finding. The PM Task keeps its own status, and raising an E-Call doesn't complete it.
- If Equipment is removed upstream, its open PM Task is **kept and flagged**, and the EIC decides what to do.

### 6.4 Timeliness (shown next to status, never instead of it)
- **Upcoming:** from 7 days before the due date.
- **Due:** on the due date.
- **Overdue:** from the day after the due date, with no grace period.
- **On time vs late** is judged by the **Work Done Date**, not the approval date. A submitted task with a Work Done Date on or before the due date shows For Validation, not Overdue.
- **PM compliance % = PM Tasks done on time ÷ PM Tasks due in the period.**

## 7. E-Calls

- **Anyone** can file an E-Call. **Site is required, Equipment is optional** (e.g. "new fence at Cebu").
- **Priority:** Emergency, High, Normal or Low. Emergency is a priority, not a separate kind of request.
- The Site Vendor submits an **FSR + BOQ**, with BOQ lines taken from its **Price List**.
- **E-Call Status:** For Vendor Assessment → For EIC Review → Approved → Closed, plus Returned, Rejected and Cancelled.
- The EIC **Approves**, **Returns** (back to the vendor to revise; the BOQ gets a new revision) or **Rejects** (the E-Call ends for good; trying again needs a new E-Call).
- **One E-Call → exactly one Work Order.** Creating the Work Order closes the E-Call.

## 8. Work Orders

### 8.1 Creation
- **Only the E-Call's Requestor** creates the Work Order, from the approved BOQ.
- Line quantities must be > 0 and **≤ the approved BOQ quantity**.
- It starts as **Open** (a draft the creator can edit), and the creator submits it.

### 8.2 Endorsement and implementation
- The 2-level endorsement before implementation goes **up the creator's Immediate Head chain**: Team Head, then Division Head.
- **Approvers are locked in when the Work Order is submitted.** Later org-chart changes don't re-route it.
- If a required level is missing (e.g. a vacant Center Head), the Work Order is **blocked** at that step until the external system fills it.
- Once endorsed, the work is `For Implementation`. The vendor does the work and declares it done (`Implemented`).
- The EIC validates the completed work, checking actual quantities against the BOQ: Approve, Return to the vendor, or Reject.

### 8.3 Billing endorsement
- The **Billing Amount** is the **actual cost the EIC validated**, not the estimate.

| Billing Amount | Levels |
| --- | --- |
| Below the lower threshold (initially ₱200,000) | 2: Team Head, Division Head |
| From the lower to the upper threshold, inclusive (initially ₱1,000,000) | 3: + Center Head |
| Above the upper threshold | 4: + Sector Head |

- **Billing Thresholds can be changed by an admin.** A change **applies to Work Orders already in billing**: the levels are recalculated, and if the levels already done are enough, the Work Order closes immediately.
- The approvers are still the chain locked in at submission.
- The last billing level **Closes** the Work Order.

### 8.4 Status lifecycle
Open → For Lvl1 Endorsement → For Lvl2 Endorsement → For Implementation → Implemented → For Billing Endorsement Lvl1…4 → Closed, plus Rejected and Cancelled.

## 9. Approve / Return / Reject / Cancel: rules for every record

- **Return** goes back **exactly one step**, to the previous approver, or to the creator from the first approver. A Return from billing level 1 goes back to the **EIC** (`Implemented`).
- **Reject** permanently ends an E-Call or Work Order. PM Reports can't be rejected.
- **Cancel** (always with a reason) is allowed **only before the vendor starts the work**:
  - **Who:** the record's creator (the Requestor) or the Site EIC. For system-generated PM Tasks, the EIC.
  - **E-Call:** can be cancelled until the EIC approves the FSR/BOQ.
  - **PM Task:** cancelling doesn't create the next task automatically. The **EIC picks the next due date**.
- Every action writes an **Approval Record**: who, what, remarks, previous status, resulting status, and when.

## 10. Price Lists

- Each Vendor has a Price List covering **consumables/materials and scope-of-work (service) items**, each with a unit price.
- Admins maintain it by **uploading a file or adding items by hand**.
- An upload **merges**: it adds new items and updates prices by item code. Items not in the file stay unchanged.
- An upload is **all or nothing**: any invalid row rejects the whole file, with a per-row error list.
- BOQ lines that aren't on the Price List are allowed but flagged for the EIC.

## 11. Dashboard, reports and notifications

- **Dashboard:** equipment counts (operating vs not, from the API), check-ups due, overdue and compliance, open E-Calls, Work Orders by step, validated spend, and a "needs attention" list.
- **Equipment needs attention** when any of these is true: its PM is overdue; its latest approved PM Report has a Fail/Defective item; it has an open Emergency E-Call; or the API says it's not operating.
- **MVP reports:** Check-up compliance · Work Order status + costs · Equipment history · Spend by site/vendor.
- **Export:** Excel (.xlsx).
- **Notifications:** no email in the MVP. Each user has an in-app **"Waiting for me"** list.

## 12. Changes from the original SRS

| SRS said | v2 decision |
| --- | --- |
| PSS owns the Equipment Registry, sites and users | Master data comes from the external API, and PSS keeps Snapshots (ADR 0001) |
| PM frequency per equipment or category | One PM Frequency per Site |
| Vendor assigned per equipment / service category | One Site Vendor per Site |
| Next PM from a fixed configured schedule | Sliding schedule from the Work Done Date |
| UPCOMING / DUE / OVERDUE are PM statuses | They are PM Timeliness, shown next to the status |
| EIC can REJECT a PM report | Approve or Return only |
| Requestor **or EIC** creates the Work Order | The E-Call's Requestor only |
| Billing basis "to be confirmed" | Validated actual amount, thresholds editable by admin |
| Configurable checklists | A fixed set in the MVP, with the editor later |
| Automated email notifications | An in-app "Waiting for me" list in the MVP |

## 13. Still open (waiting on facts about the external system)

1. The external API's base URL, authentication, endpoints and sample responses (issue 03).
2. The SSO protocol, how vendor staff identities map to PSS Vendors, and token vs cookie auth (issue 04).
3. How a user's visible Sites are determined (dashboard and report filtering).
4. The field name and values for equipment operational status.
5. The vendors' Price List file template (issue 15).
6. How often the PM generation job runs (daily assumed).
7. The exact XLSX package for export and upload (e.g. `phpoffice/phpspreadsheet`); it needs approval before adding.
