# Glossary — Passive Support Services (PSS)

## Site
A physical place the organisation operates (e.g. "Makati Office", "Cebu Site"). Every piece of Equipment and every E-Call belongs to a Site. A Site has a designated Vendor.

## Location
A specific spot within a Site (e.g. "Basement, Genset Room").

## Equipment
Anything registered and maintained, from machines (genset, ACU, ATS, electrical panel) to buildings themselves (e.g. "Makati Office Building"). Each one belongs to a Site and can have its own PM schedule and maintenance history.
_Avoid:_ Asset, Facility (as separate concepts)

## Equipment Category
A configurable classification of Equipment (e.g. ACU, Genset, ATS, Building Facilities).

## Preventive Maintenance (PM)
The process of scheduled inspection and servicing of Equipment, done to prevent failures.

## PM Task
One scheduled check-up of one Equipment. The next PM Task's due date is counted from the previous task's Work Done Date (a sliding schedule), not from a fixed calendar. A newly registered Equipment's first PM Task is due one PM Frequency interval after its installation date. An Equipment with no installation date and no PM history gets no PM Task until a start date is set. Until then it's on the Needs Setup list.

## Work Done Date
The date a vendor actually did the work on a PM Task, as reported by the vendor and confirmed when the EIC approves the report. It is not the date of the approval itself.

## PM Status
Which workflow step a PM Task is on (e.g. Scheduled, In Progress, For Validation, Returned, Completed, Cancelled).

## PM Timeliness
Whether a PM Task is early or late compared with its due date (Upcoming, Due, Overdue). It's shown next to the PM Status, not instead of it, so a task can be both "Returned" and "Overdue".

## Corrective Maintenance (CM)
The process of fixing, replacing or improving things in response to a need: an E-Call followed by a Work Order. CM is the process, not a record.

## E-Call
A request for corrective work at a Site, whatever its urgency. It may name a specific Equipment or none at all (e.g. "build a new fence around the Cebu site"). Emergency is one of its Priority levels, not a separate kind of request.
_Avoid:_ Emergency Call (as a name for all requests), ticket, CM request

## Priority
How urgent an E-Call is (e.g. Emergency, High, Normal, Low).

## Vendor
An outside company that does PM and corrective work.

## Site Vendor
The single Vendor designated for (or awarded the contract for) a Site. It handles all PM and corrective work for every Equipment at that Site. Individual jobs cannot be sent to a different vendor. Only an administrator can replace a Site's Vendor.

## Requestor
Any user who files an E-Call. They don't need to be an engineer.

## EIC (Engineer-in-Charge)
The engineer assigned to a Site. They review every PM Report at that Site, and, for every E-Call there (whoever filed it), the vendor's field report and quote and the finished work.
If the Site's EIC is replaced, the new (current) EIC takes over every pending item at that Site. The EIC recorded on an item when it was created is kept only as history.
_Avoid:_ treating whoever filed the E-Call as its reviewer; treating the EIC recorded on an item as the one who must act on it

## PM Frequency
How often check-ups happen. One frequency is set per Site, and every Equipment at that Site follows it.
The MVP has a fixed list: monthly, twice a month (every 15 days), every 2 months, quarterly, semi-annual and annual. There are no custom intervals. Month-based intervals land on the same day of the month, or on the month's last day when that day doesn't exist (31 Jan + 1 month = 28 or 29 Feb).
_Avoid:_ reading "twice a month" as fixed calendar days (e.g. the 1st and 15th)

## Price List
A Vendor's contract-agreed list of priced items, covering both materials/consumables and scope-of-work (service) items, each with a unit price. A vendor's cost quote (BOQ) is built from it. Administrators maintain it by uploading a file or adding items by hand.

## Billing Threshold
An amount boundary that decides how many billing approval levels a Work Order needs (initially ₱200,000 and ₱1,000,000). Administrators can change them.

## Immediate Head
The person a given employee reports to. Approvals go up this chain: Requestor → Supervisor/Team Head → Division Head → Center Head → Sector Head.

## Work Order
The approved, priced job created from exactly one approved E-Call. The Requestor who filed that E-Call creates it, and its Endorsements and Billing Endorsements follow that Requestor's Immediate Head chain. Creating it closes that E-Call. Additional work needs a new E-Call.

## Cancel
Withdrawing an E-Call, PM Task or Work Order before the vendor has started the work, with a mandatory reason. Its creator (the Requestor, for E-Calls and Work Orders) or the Site's EIC can do it. Once work has started, the only way to end it is a Reject.

## Return
An approver's "fix it and resend" action. The record goes back exactly one step, to the previous approver (or to the creator if it was the first approver), and stays alive.

## Open (Work Order)
A Work Order that's still a draft. Its creator can keep editing it until they submit it for approval.

## Reject
An approver's "no" that permanently ends an E-Call or Work Order. To try again, file a new E-Call. PM reports cannot be Rejected, only Approved or Returned.

## Billing Amount
The actual cost the EIC validated for a Work Order (not its originally approved estimate). It decides how many billing approval levels are needed. It's rounded once to the centavo, half-up (ADR 0002).

## Field Service Report (FSR)
The Site Vendor's write-up of its assessment or site inspection for an E-Call. It is submitted together with the BOQ and reviewed by the EIC.

## Bill of Quantities (BOQ)
The Site Vendor's cost quote for an E-Call. It lists Price List items with quantities, and the unit prices are copied in when it's submitted. It is revised each time the EIC Returns it. The approved BOQ is the basis of the Work Order. Its lines keep exact amounts, and only its total is rounded to the centavo, half-up (ADR 0002). Items that aren't on the Price List aren't allowed, and each Price List item appears at most once per revision (quantities are combined into one line).
_Avoid:_ cost proposal, quotation (as separate concepts)

## PM Checklist
A configurable inspection template for one Equipment Category, made of Checklist Items. Every change creates a new version. A PM Task keeps the version it was generated with, so old PM Reports stay consistent.

## Checklist Item
One check in a PM Checklist (e.g. "Engine oil inspection"). It has a response type (Pass/Fail, Good/Defective, Yes/No, numeric reading, text, photo) and can be mandatory, require remarks when failed, or require a photo.

## PM Report
What the Site Vendor submits for a PM Task: the PM Checklist responses, the Work Done Date, findings, materials used, and the PM Service Report and photos. The EIC Approves or Returns it.

## E-Call Status
Which workflow step an E-Call is on: For Vendor Assessment → For EIC Review → Approved → Closed (when its Work Order is created), plus Returned and Rejected. The PRD only names CLOSED. The other names are proposed in `.scratch/pss-mvp/`.

## Endorsement
An approver's sign-off as a Work Order moves up the Immediate Head chain. There are two kinds: the 2-level endorsement before implementation, and the Billing Endorsement after the EIC validates the completed work.

## Billing Endorsement
The 2, 3 or 4 levels of Endorsement a Work Order goes through after completion, chosen by its Billing Amount against the Billing Thresholds. The last one Closes the Work Order.

## Approval Record
The permanent log entry for every Approve, Return or Reject (and cancellation): who acted, the action, remarks, the previous and resulting status, and when.

## Snapshot
A copy of an external Site's or Equipment's details (name, category, location) stored on a PSS record, so history still reads correctly after the item is renamed or removed in the external system.

## Needs Setup list
An administrator's list of what stops PM Tasks from being generated: Sites with a PM Frequency but no Site Vendor or EIC, and Equipment with no installation date and no PM history. It's worked out live, not stored.
