# 03 — Import master data from the source database into PSS tables

Status: ready-for-agent
Blocked by: 02
Type: task

## What to build

**Rewritten 2026-11-24 from the human's decisions.** Before this rewrite, the issue was "HTTP adapter for the external master data API". The master data (Sites, Equipment, Equipment Categories, Employees) lives in a **different database**. PSS **imports** it into its own tables on a schedule, and `MasterDataDirectory` (issue 02) serves from those tables. ADR 0001 is amended (docs/adr/0001-master-data-owned-by-external-api.md, "Amendment (2026-11-24)").

## Decisions (human, 2026-11-24)

- A second, **read-only DB connection** to the source database, configured by env vars. If it isn't configured, the import refuses with a clear message and changes nothing.
- An artisan command, `pss:import-master-data`, runs **nightly** from the scheduler and can also be run by hand. It **upserts** into PSS tables keyed by the **source ID**.
- **Equipment** goes into one `equipment` table. Its Equipment Category comes from the source table it was read from: `aircondition_units` → ACU, `gensets` → Genset, `ats` → ATS. The shared columns are source id, name, Site, location, `installed_on` and operating status (`operational` / `non-operational`). Type-specific fields go in a `details` JSON column. **Building is left out for now.**
- **Sites, Equipment Categories and Employees** are imported too. Each employee has `immediate_head_id` and a position title. `users.employee_id` links a PSS user to their employee, which gives the Immediate Head details.
- The **source schema is guessed**. Every source table and column name sits in **one mapping class**. Once the real schema is shared, only that class changes.
- A **sample seeder** fills the PSS master data tables, so local dev and demos work without the source DB.
- Rows that disappear from the source become **inactive, never deleted**. An inactive Equipment gets no new PM Tasks and no new E-Calls. The PSS user of an inactive employee is **not** deactivated automatically (issue 47).
- Tests may keep the in-memory fake (`InMemoryMasterDataDirectory`).

## Acceptance criteria

- [ ] Config: a `master_source` DB connection, read from env vars (the names are the dev-lead's choice; document them in `backend/README.md` and `.env.example`). PSS only reads from it.
- [ ] Migrations add PSS tables for sites, equipment categories, equipment (with `details` JSON and `is_active`) and employees (with `immediate_head_id`, position title and `is_active`). Each is unique on the source ID. Equipment Categories get stable codes `ACU`, `GENSET` and `ATS`, so issue 31 can bind its checklists to them.
- [ ] One mapping class (e.g. `App\MasterData\Import\SourceSchema`) holds every source table and column name and turns a source row into a PSS row. Unit tests cover each mapping: one row from each equipment table, plus a site and an employee.
- [ ] `php artisan pss:import-master-data`:
  - without the connection configured, it exits non-zero with a clear message and writes nothing;
  - it inserts new rows and updates changed ones (keyed by source ID) and is idempotent, so a second run with the same data changes nothing;
  - it marks rows missing from the source as inactive, and reactivates them if they come back;
  - it prints a summary (inserted / updated / deactivated per table).

  Feature tests run it against a second SQLite connection seeded with guessed source rows.
- [ ] The command is scheduled nightly (in `routes/console.php` or the scheduler the app uses). A test asserts the schedule entry.
- [ ] A database-backed `MasterDataDirectory` reads the PSS tables and implements every port method. Its `findEmployee` returns the Immediate Head and position title. It's bound in every environment except `testing`, which keeps the in-memory fake. Existing tests stay green.
- [ ] Inactive Equipment: the PM Task generation job (issue 08) creates no new PM Tasks for it, and filing an E-Call for it returns 422. Its existing records still read correctly (Snapshots). Tests for both.
- [ ] A sample seeder fills the master data tables with the same sample Sites, Equipment, Categories and Employees as `SampleMasterData` (issue 30), so local dev looks the same as before. It's called from `DatabaseSeeder`.
- [ ] No Building category and no Building equipment are imported.

## Comments

### Clarifications (product-owner, 2026-11-24)

- This replaces the earlier HTTP-adapter plan. The questions about URL, auth, endpoints and caching are moot. The 2026-11-22 answers still hold: the employee's Immediate Head comes from the master data, and so does the Equipment's operating status.
- Operating status: import it as given. Issue 28 already classifies `operational` as operating, empty as unknown and anything else as non-operational (`App\Dashboard\OperatingState`). Keep that, and map the source value to `operational` / `non-operational` in the mapping class.
- Where things live: the port is `App\MasterData\MasterDataDirectory`, the fake is `InMemoryMasterDataDirectory`, the sample data is `SampleMasterData`, and the binding is in `AppServiceProvider` (comment near line 60). `pss:setup-first-admin` looks up the employee through the port, so it keeps working.
- PSS still never edits master data. The imported tables are a read-only copy, refreshed by the import. Don't add CRUD endpoints for them.
- Keep Snapshots as they are: they still protect history when a source row is renamed.
- Record your own decisions (env var names, the guessed source table/column names, the category mapping) under a dated "Decisions (dev-lead, ...)" heading here.

### Decisions (dev-lead, 2026-11-24)

- **Env vars:** `MASTER_SOURCE_DB_DRIVER` (default `mysql`), `MASTER_SOURCE_DB_HOST`, `MASTER_SOURCE_DB_PORT`, `MASTER_SOURCE_DB_DATABASE`, `MASTER_SOURCE_DB_USERNAME`, `MASTER_SOURCE_DB_PASSWORD` (plus optional `_CHARSET` / `_COLLATION`). Connection name `master_source`. "Not configured" means `MASTER_SOURCE_DB_DATABASE` is empty.
- **Guessed source schema** (all in `App\MasterData\Import\SourceSchema`):
  - `sites(id, name)`
  - `employees(id, name, position_title, immediate_head_id)`, where `immediate_head_id` is another `employees.id`
  - `aircondition_units`, `gensets`, `ats`: shared `id, site_id, name, location, installed_on, status`; details `brand, model, capacity_tons, refrigerant` (ACU), `brand, model, capacity_kva, fuel_type` (Genset), `brand, model, rating_amperes` (ATS)
- **Category mapping:** `aircondition_units` → `ACU` "ACU", `gensets` → `GENSET` "Genset", `ats` → `ATS` "ATS". No Building table is read.

## Implementation decisions

1. **IDs.** The port's IDs are the source IDs as strings. Site and employee IDs are the source primary keys (`"3"`). Equipment IDs are prefixed with the category code (`ACU-17`, `GENSET-17`), because each equipment table numbers its rows on its own. Equipment Category IDs are now the codes `ACU` / `GENSET` / `ATS` (before: `CAT-ACU` etc. in the sample data). Issue 31 binds checklists to these codes.
2. **Tables:** `sites(source_id unique, name, is_active)`, `equipment_categories(code unique, name)`, `equipment(source_id unique, equipment_category_code, site_source_id, name, location, installed_on, operating_status, details json, is_active)`, `employees(source_id unique, name, position_title, immediate_head_id, is_active)`. References are source IDs, not foreign keys. The migration inserts the three categories, so they exist before any import. Categories have no `is_active`: they are fixed in `SourceSchema` and never deactivated.
3. **Operating status mapping:** source `status` trimmed and case-insensitive `operational` → `operational`; empty or null → null (unknown); anything else → `non-operational`.
4. **Dates:** `installed_on` keeps only the date part (`Y-m-d`) of a date or datetime source value.
5. **Import behavior:** every source table is read before anything is written. The writes run in one transaction, so a failed read or write changes nothing (exit non-zero, "changed nothing" message, exception reported). A row counts as "updated" only when a mapped field differs, or when it comes back after being inactive (reactivation counts as updated). The `details` JSON is compared decoded and independent of key order (MySQL reorders JSON keys). Inserts are chunked by 500. Duplicate source IDs within a source table: the last row wins.
6. **Summary:** a table with Table / Inserted / Updated / Deactivated rows for `equipment_categories`, `sites`, `equipment`, `employees`.
7. **Schedule:** `Schedule::command(ImportMasterData::class)->dailyAt('23:30')->withoutOverlapping()` in `routes/console.php`, in Asia/Manila, so the midnight PM Task generation sees fresh data. Production needs `schedule:run` on cron (already needed for issue 08).
8. **Port semantics for inactive rows:** `findSite` / `findEquipment` / `findEmployee` also return inactive rows (new `isActive` flag on `Site`, `Equipment`, `Employee`), so existing records and the Immediate Head chain still resolve. `listSites` and `listEquipmentAtSite` return active rows only. The in-memory fake follows the same rule. So inactive Equipment no longer appears in the Site equipment list, the dashboard counts or Needs Setup, and inactive Sites are left out of the Site list.
9. **Inactive Equipment:** PM Task generation skips it (no new task, no successor). An open PM Task of inactive Equipment is kept and flagged `equipment_removed` (the issue 25 flag), the same as Equipment missing from the directory. Filing an E-Call (`POST /api/e-calls` and `POST /api/pm-tasks/{task}/e-calls`) for it returns 422 "This Equipment is no longer active in the master data, so no new E-Call can be filed for it." Snapshots on existing records are unchanged.
10. **Inactive Sites:** no new rule. `findSite` still finds them, so an E-Call can still be filed at an inactive Site that has routing. **Human to confirm** whether that should also be refused.
11. **Employee:** `Employee` gets `positionTitle` and `isActive`. An inactive employee's PSS user is not touched (issue 47).
12. **Binding:** `testing` keeps `InMemoryMasterDataDirectory`. Every other environment, including `local`, gets `DatabaseMasterDataDirectory` (query builder over the PSS tables, no Eloquent models, no write endpoints). Local dev therefore needs `php artisan migrate --seed`.
13. **Sample seeder:** `MasterDataSampleSeeder` writes `SampleMasterData` into the tables (upsert by source ID, safe to run again). `DatabaseSeeder` calls it **only in local**, before `DemoAccountsSeeder`. The sample data lost its Building category and Building equipment, uses the category codes, maps the Cebu ATS status "under repair" to `non-operational`, and gives the head chain position titles. `SampleMasterData::EMPLOYEE_IDS` lists the sample employees (the port has no employee list).
14. **Read-only:** PSS issues only SELECTs on `master_source` (a test asserts it). Read-only is not enforced by the connection itself, so the deployment should use a SELECT-only database user (README).
15. **Tests changed:** `MasterDataBindingTest` (local and production now resolve the database directory; the "no master data tables" checks are gone). `SiteApiTest::test_pss_has_no_master_data_tables` is replaced by a check that only `App\MasterData` touches those tables.
16. **Risk, human to confirm:** if a source table comes back empty (e.g. a permissions problem), the import deactivates every row of that table, and PM generation stops for that Equipment until the next good import. There is no "refuse to deactivate everything" guard, because the issue doesn't ask for one.
17. **Performance:** `findEquipment` etc. run one indexed query per call. `GeneratePmTasks::flagRemovedEquipment` calls it once per open task. That's fine at MVP size; it could be batched later. The import loads each PSS table into memory once per run.
