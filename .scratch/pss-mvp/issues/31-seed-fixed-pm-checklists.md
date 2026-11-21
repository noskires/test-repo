# 31 — Seed the fixed MVP PM Checklists (Genset, ACU, ATS, Building)

Status: needs-info
Blocked by: 07
Type: task

## What to build

PRD v2 §6.2: the MVP ships a fixed set of PM Checklists for Genset, ACU, ATS and Building, "taken from the approved PM standards", with the PRD §6.5 items as the starting point. Using issue 07's loader, write the real checklist definitions and map each one to its external Equipment Category.

What the PRD §6.5 gives (examples only, "actual inspection requirements shall be configurable based on approved maintenance standards"):
- **Genset**: Engine oil inspection, Coolant level inspection, Battery condition, Fuel system inspection, Air filter inspection, Belt and hose inspection, Engine operation test, Generator output and voltage inspection, General physical condition.
- **ACU**: Air filter cleaning, Evaporator coil inspection, Condenser coil inspection, Refrigerant system inspection, Drainage system inspection, Electrical connection inspection, Thermostat functionality, Cooling performance test.
- **ATS**: Electrical connection inspection, Contact condition, Switching mechanism inspection, Automatic transfer functionality, Manual operation test, Control circuit inspection, General physical condition.
- **Building**: no items given.

## Open questions (for the human)

1. **The approved PM standards**: please share them. Are the PRD §6.5 example lists final for Genset, ACU and ATS, or do the standards add or change items?
2. **Building checklist**: what are its items?
3. **Per item**: what is the response type (Pass/Fail, Good/Defective, Yes/No, numeric reading, text, photo), and which items are mandatory, require remarks when failed, or require a photo? For numeric readings (e.g. generator output voltage), what are the acceptable min/max values and units?
4. **Category mapping**: which external Equipment Category ID (or code) does each checklist belong to? This depends on the external API (issue 03). Until then, should local dev map the checklists onto the in-memory fake's sample categories from issue 30?

## Acceptance criteria (draft, finalise once the questions are answered)

- [ ] The four checklist definitions exist in one data location and load through issue 07's loader without errors.
- [ ] A test asserts each checklist's item count and labels, and the response type and flags for each item, as agreed.
- [ ] Each checklist is bound to the agreed external Equipment Category.

## Comments
