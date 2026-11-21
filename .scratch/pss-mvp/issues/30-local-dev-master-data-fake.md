# 30 — Local dev serves master data from a seeded in-memory fake

Status: ready-for-agent
Blocked by: 02
Type: task

## What to build

Human decision (2026-11-22): until issue 03 (the real HTTP adapter) lands, the **local** environment binds `MasterDataDirectory` to the in-memory fake too, so `GET /api/sites` works locally instead of failing with a 500. Production does **not** get the fake. Its binding remains issue 03's job.

Issue 02 (merged in PR #5) binds `InMemoryMasterDataDirectory` only when `app()->environment('testing')`, in `backend/app/Providers/AppServiceProvider.php`. This slice:

- Binds `MasterDataDirectory` to the in-memory fake when the environment is `local` as well. The fake is a singleton, **pre-loaded with sample data**.
- Keeps the testing binding **empty**, as it is today, so tests seed exactly what they need and the sample data never leaks into them.
- Leaves every other environment (e.g. `production`, `staging`) without a fake binding.
- Puts the sample data in one place, e.g. a class that fills an `InMemoryMasterDataDirectory` (the exact name is the developer's choice). It must contain:
  - at least 2 Sites
  - at least 2 Equipment at each Site, plus at least 1 Equipment Category per Equipment
  - employees forming a complete **Immediate Head** chain at least 4 levels deep above one employee: Requestor → Team Head → Division Head → Center Head → Sector Head. Issues 19 and 23 use this chain to route approvals.
- Writes **no** master data to the database. PSS has no sites, equipment or employees tables (ADR 0001).

## Acceptance criteria

- [ ] In the `local` environment, resolving `MasterDataDirectory` returns the fake loaded with the sample data. A test can switch the app's environment to `local` and re-run the provider registration to check this.
- [ ] In the `testing` environment, the resolved fake starts empty (`listSites()` returns `[]`).
- [ ] In the `production` environment, `MasterDataDirectory` has no in-memory fake binding.
- [ ] The sample data has at least 2 Sites, each with at least 2 Equipment, and every Equipment's category is in `listEquipmentCategories()`.
- [ ] The Immediate Head helper from issue 02 resolves levels 1 to 4 from the sample Requestor, and level 5 returns null.
- [ ] No migration is added and `composer.json` is unchanged.
- [ ] PHPUnit tests cover every criterion above, and `php artisan test` passes.

## Comments
