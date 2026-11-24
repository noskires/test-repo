# 31 — Seed the default MVP PM Checklists (Genset, ACU, ATS)

Status: ready-for-agent
Blocked by: 07, 03
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

## Decisions (human, 2026-11-24)

- **Reduced scope**: seed **one default checklist each for Genset, ACU and ATS**, from the PRD §6.5 examples above. **Building is left out** (issue 03 imports no Building equipment).
- Every item is **Pass/Fail**, with **remarks required on Fail**.
- Bind each checklist to the **seeded Equipment Category** from issue 03 (codes `GENSET`, `ACU`, `ATS`).
- They change **only in code**, using the existing versioning (issue 07).
- Admin create/edit of checklists through the API and UI is **post-MVP**: issue 50 (parked).

## Acceptance criteria

- [ ] The three checklist definitions live in one data location and load through issue 07's loader (the `LoadChecklists` command) without errors.
- [ ] A test asserts each checklist's items, in order, exactly as listed above (Genset 9, ACU 8, ATS 7), each `pass_fail` with "remarks required when failed".
- [ ] Each checklist is bound to its seeded Equipment Category by code. Loading twice creates no new version. Changing an item in code and loading again creates a new version (issue 07's rule).
- [ ] `DatabaseSeeder` (after issue 03's master data seeder) loads them, so a fresh local database generates PM Tasks with a checklist.

## Comments

### Clarifications (product-owner, 2026-11-24)

- Questions 1 to 4 are answered by the reduced scope above. Approved PM standards and numeric readings come later with issue 50.
- **Provisional (to confirm with the human)**: every item is mandatory and none requires a photo. The human only said Pass/Fail with remarks on Fail.
- Record your decisions under a dated "Decisions (dev-lead, ...)" heading here.
