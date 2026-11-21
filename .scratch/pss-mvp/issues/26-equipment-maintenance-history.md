# 26 — Equipment maintenance history

Status: ready-for-agent
Blocked by: 11, 13, 18
Type: task

## What to build

PRD §5.3 / §7.1: one combined, read-only timeline per Equipment (external ID): its PM Tasks (with status, Work Done Date, findings), E-Calls (with priority, status), and Work Orders (with status, estimated amount, and Billing Amount when set). Each entry links to its record. Entries for Equipment that has since been removed upstream still show, using the stored **Snapshot**.

Endpoint: `GET /api/equipment/{equipmentId}/history`, newest first, with optional `from` / `to` date filters.

## Acceptance criteria

- [ ] PM Tasks, E-Calls and Work Orders for that Equipment are returned in one list, newest first. Records for other Equipment aren't included.
- [ ] An E-Call filed from a PM Task shows the link to that PM Task.
- [ ] Removing the Equipment from the directory fake still returns its history, with the snapshot name.
- [ ] Date filters work. Vendor staff see only records of their own Vendor.

## Comments
