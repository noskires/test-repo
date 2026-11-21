# 02 — Master data directory port (with in-memory fake)

Status: ready-for-agent
Blocked by: none
Type: task

## What to build

PSS reads Sites, Equipment, Equipment Categories and employees from an existing external API (see spec, "master data is external"). Define one port that every other slice uses, so the rest of the app never talks HTTP directly.

- A `MasterDataDirectory` interface with read-only methods:
  - find a Site by ID, and list Sites
  - find an Equipment by ID, and list the Equipment at a Site
  - find an employee by ID, including their Immediate Head's employee ID
  - list Equipment Categories
- Plain value objects returned by the port (Site, Equipment, Employee, EquipmentCategory). These are not Eloquent models.
- An `InMemoryMasterDataDirectory` fake that can be seeded in tests, bound in the container for the testing environment.
- A helper to walk the **Immediate Head** chain N levels up from an employee. Approvals in issues 19 and 23 need it.
- A thin read endpoint so the slice is end-to-end: `GET /api/sites` and `GET /api/sites/{id}/equipment`, served from the port.

The real HTTP adapter is issue 03 (needs-info).

## Acceptance criteria

- [x] The interface and value objects exist. Nothing outside the adapter layer depends on HTTP.
- [x] The fake can be seeded with sites, equipment, categories and employees, and its methods return them.
- [x] Looking up an unknown Site, Equipment or employee returns null, and the endpoints return 404.
- [x] The Immediate Head helper returns the correct employee for levels 1 to 4, and returns null when the chain runs out.
- [x] `GET /api/sites` lists the seeded sites. `GET /api/sites/{id}/equipment` lists only that site's equipment.
- [x] Both endpoints require authentication (401 otherwise).
- [x] Unit tests cover the fake and the chain helper. Feature tests cover the endpoints.

## Comments

- 2026-11-22 (qa): QA PASS. Ran `php artisan test` from backend/: 46 passed (124 assertions), 0 failed, 0 skipped. Every acceptance criterion maps to a test that asserts it. No migrations or Eloquent models were added for external-API master data (ADR 0001).

- 2026-11-22 (product-owner): Human decision: **local dev uses the fake**. Until issue 03 lands, the `local` environment (not production) also binds `MasterDataDirectory` to the in-memory fake, seeded with sample Sites, Equipment and employees including an Immediate Head chain. This issue had already merged (PR #5), so the work is a follow-up in issue 30.
