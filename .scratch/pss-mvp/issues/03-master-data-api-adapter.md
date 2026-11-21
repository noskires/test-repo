# 03 — HTTP adapter for the external master data API

Status: needs-info
Blocked by: 02
Type: task

## What to build

The production implementation of `MasterDataDirectory` (issue 02), calling the existing external API for Sites, Equipment, Equipment Categories and employees (with Immediate Head).

## Open questions (for the human)

1. What is the base URL for each environment, and how does PSS authenticate to the API (API key, OAuth client credentials, service account)?
2. Which endpoints and response shapes exist for Sites, Equipment (including Location within the Site), Equipment Categories and employees? Please share a sample JSON for each.
3. Does the API expose each employee's **Immediate Head** directly? Does it give their role (Supervisor / Division Head / Center Head / Sector Head)?
4. Does the API hold Equipment **operational status / condition**? The PRD dashboard needs "operational vs non-operational" and "equipment requiring attention".
5. Rate limits or latency: should PSS cache responses, and for how long?

## Acceptance criteria (draft, finalise once the questions are answered)

- [ ] The adapter implements every `MasterDataDirectory` method against the real API, with tests using `Http::fake()` and recorded sample payloads.
- [ ] API errors or timeouts are surfaced as a clear domain exception, and never as a silent empty result.
- [ ] The adapter is bound in non-testing environments through config.

## Comments

## Decisions (grilling, 2026-11-22)
- Q3 partly answered: **yes, the API exposes each employee's Immediate Head**. PSS stores no org chart. (Whether it also gives the role title is still open.)
- Q4 answered: **yes, equipment operational status comes from the API** (the user confirmed). Its field name and values are still needed.
