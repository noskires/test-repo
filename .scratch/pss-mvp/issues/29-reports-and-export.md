# 29 — Reports: access, xlsx export, and the Check-up compliance report

Status: ready-for-agent
Blocked by: 04, 26, 27, 28, 42, 51
Type: task

## What to build

PRD Module 8 and §6 "Exportable reports": inventory, PM accomplishment (monthly, quarterly, annual), PM compliance, overdue maintenance, E-Call and Work Order reports, approved vs actual material consumption, Work Order costs, billing endorsement status, expenditure, and vendor performance. The MVP has four reports (decisions below). This issue builds the first one and the shared parts (see "Split").

## Open questions (for the human)

1. Which reports are actually needed for the MVP go-live, and in what priority order?
2. What export format(s): CSV, XLSX, PDF? Is there a required layout or letterhead? (XLSX or PDF needs a new package, which needs approval.)
3. Who can see which reports? (Management / Viewer sees "authorized reports". Which ones are those?)

## Split (product-owner, 2026-11-25)

The four MVP reports are too big for one slice. This issue is now the **first report plus the shared parts**: the `view-reports` gate, Vendor scoping, and `.xlsx` export, all delivered through the **Check-up compliance** report. The other three are separate issues, each blocked by this one:
- 52 — Report: Work Order status and costs
- 53 — Report: Equipment history
- 54 — Report: Spend by Site and Vendor

The original combined criteria are replaced by the ones below. The `view-reports` gate, Vendor scoping and the xlsx test pattern defined here are reused by 52–54.

## Acceptance criteria

- [x] `GET /api/reports/pm-compliance` returns JSON `{period: {from, to}, summary: {due, on_time, compliance}, rows: [...]}`. `summary` is exactly what the dashboard's `pm.compliance` gives for the same period and filters: reuse `PmCompliance` / `DashboardKpis`, don't copy the logic.
- [x] `rows`: one per PM Task counted as due in the period by the issue 28 PM Compliance rule, sorted by due date, then Site name, then Equipment name. Each row has `site_name`, `equipment_name`, `equipment_category`, `vendor_name`, `due_date`, `work_done_date` (null if none), `status` and `on_time` (true / false). Names come from the PM Task's snapshots and the PSS Vendor table. **These columns are provisional (product-owner); see Open questions.**
- [x] Filters: `from`, `to` (same validation and Manila current-month default as the dashboard, issue 42), `site_id`, `equipment_category_id` and `vendor_id`, which apply as they do on the dashboard.
- [x] `GET /api/reports/pm-compliance/export` takes the same filters and downloads `.xlsx` (`phpoffice/phpspreadsheet`, approved 2026-11-22; add it with composer). It has one sheet: a header row with the same columns in the same order, then the data rows. Dates are `Y-m-d` text, and `on_time` is `Yes` / `No`. A feature test opens the downloaded file and checks the header row and the data rows against fixtures. The filename includes the period, e.g. `pm-compliance-2026-11-01-to-2026-11-30.xlsx`.
- [x] A `view-reports` gate allows only users with the `system-administrator` or `management-viewer` role (issue 04). Everyone else, including other internal employees, gets 403 on both endpoints. A pending user gets 403 through the existing pending block. One test per outcome.
- [x] Vendor scoping follows issues 04/45/49: internal users see every Site. A vendor-linked Management / Viewer is forced to their own Vendor (their own `vendor_id`, ignoring any `vendor_id` they pass) and sees only their Vendor's PM Tasks. Test it with another Vendor's PM Task on the same Site.
- [x] No package other than `phpoffice/phpspreadsheet` is added. The query count doesn't grow with the number of rows.

## Comments

## Decisions (grilling, 2026-11-22)
- Q1 answered: the MVP reports are **Check-up compliance**, **Work Order status + costs**, **Equipment history** and **Spend by site/vendor**. The rest of the PRD's reports come later.
- Q2 answered: the export format is **Excel (.xlsx)**. The user chose it knowing it needs a spreadsheet package (e.g. `phpoffice/phpspreadsheet`). Confirm the exact package before adding it. The same package would cover XLSX price-list uploads (issue 15 Q4).
- Q3 answered (**superseded for reports on 2026-11-24**, see below): **everyone sees the dashboard and reports, filtered to their own sites**. Vendors see only their own Sites' jobs. How a user's sites are determined is still open (see issue 04).

- 2026-11-22 (product-owner): Human decision: **`phpoffice/phpspreadsheet` is approved** for XLSX export (closes the Q2 package confirmation). Q1, Q2 and Q3 are all answered, so this issue has no open questions of its own and is flipped to **ready-for-agent**. The one remaining dependency is how a user's Sites are determined, which is an open question in issue 04 (needs-info). Issue 04 is therefore added to `Blocked by`. Site filtering must use whatever 04 decides and must not invent its own rule.
- 2026-11-24 (product-owner): `Blocked by` now also lists 42. Issue 27 was split, and its financial sums and date range moved to 42; the "Work Order status + costs" and "Spend by site/vendor" reports need those definitions.

## Decisions (human, 2026-11-24)

From the issue 04 grilling:
- Q11: (a) **Reports & Export only for System Administrator and Management/Viewer.** This replaces the 2026-11-22 Q3 "everyone sees reports" for reports. The dashboard (issue 27) stays open to all internal employees.
- Q5: (a) every internal employee sees every Site. Vendor staff see only their own Vendor's records. This answers the "visible Sites" dependency.

- 2026-11-24 (product-owner): acceptance criteria updated for Q11 (`view-reports` gate) and Q5 (Site filtering). Still blocked by 04, 26, 27, 28, 42.

From the issue 45/46 grilling (second session, 2026-11-24):
- Q3 (issue 45): **Management / Viewer stays allowed for vendor staff**, and their Reports are limited to their own Vendor. This confirms the Site filtering criterion above. A System Administrator can't be vendor-linked (issue 45), so a vendor-linked user with report access always holds Management / Viewer.
- Q5 (issue 46): a pending user gets 403 on every report endpoint through the shared pending block; this issue adds nothing for it. Use `access` (`internal` / `vendor`) or `vendor_id` for the filter, whichever issue 46 leaves as the single source on `User`.

### Clarifications (product-owner, 2026-11-25)

- Split into 29 (this issue) and 52, 53, 54. See "Split". 29 is also blocked by 51 (the PM Compliance last-day fix) so that this report doesn't inherit that bug.
- **Provisional, to confirm with the human**: the row columns above. The PRD (§3.8, §6.9, Module 8) names the reports but gives no columns, so the product-owner chose them from the existing PM Compliance definition. Build them as written. The releaser lists them under "Decisions to confirm" in the PR. A different column set later is a small change.
- Put the report code next to the dashboard code (e.g. `App\Reports\PmComplianceReport`) and give the xlsx writing one small shared helper (header row + rows), so 52–54 reuse it. Define the `view-reports` gate in `AppServiceProvider` next to the other gates.
- "Forced to their own Vendor" is the same effective-Vendor rule the dashboard uses (issues 27/49). Reuse it.
- `phpoffice/phpspreadsheet` is approved. Check that it installs on this PHP version. If it needs extensions that aren't enabled (`zip`, `gd`, `xml`), stop and write that in this issue instead of working around it.
- Docs: don't edit GLOSSARY.md. If a "Report" entry is needed, the releaser's docs review flags it as a follow-up and the product-owner writes it.
- Record your decisions under a dated "Decisions (dev-lead, ...)" heading here.

### Clarifications (product-owner, 2026-11-25, at hand-off)

- Issue 51 merged (PR #53). `DashboardKpis::pmCompliance` now selects the due window as `due_date >= from` and `< windowEnd + 1 day`. Your row query must use the **same** window. Better still, share one query or scope so the `rows` count always equals `summary.due`, and test that equality with tasks due on the first and last day.
- No other changes since the split. The acceptance criteria above are final for this slice.

## On hold (team lead, 2026-11-25)

Held before any code was written. The team lead says adding `phpoffice/phpspreadsheet` still needs the human's approval. Note that this file records an approval on 2026-11-22 (see the Decisions above); the product-owner has asked the team lead to confirm it with the human.

**Resolved 2026-11-25**: the human reconfirmed the approval (see Decisions below).

### Decisions (human, 2026-11-25)

- The human reconfirmed that **`phpoffice/phpspreadsheet` is approved** (relayed by the team lead). The hold is lifted, and this issue is back to **ready-for-agent**.

## Open questions (for the human, not blocking)

1. Are these columns right for the Check-up compliance report: Site, Equipment, Equipment category, Vendor, Due date, Work Done Date, PM Status, On time? Should Cancelled PM Tasks or the EIC also appear?

## Implementation decisions

### Decisions (dev-lead, 2026-11-25)

1. **Package**: `phpoffice/phpspreadsheet` ^5.12 (5.12.0) added with `composer require`. Composer also pulled its own dependencies: `composer/pcre`, `maennchen/zipstream-php`, `markbaker/complex`, `markbaker/matrix` and `phpoffice/wmf`. No existing package changed version. The PHP extensions it needs (`zip`, `gd`, `xml`, `xmlreader`, `xmlwriter`, `zlib`, `mbstring`, `iconv`, `ctype`, `dom`, `fileinfo`, `SimpleXML`) are all enabled on PHP 8.4.11 here. **Deployment:** the production PHP must have the same extensions.
2. **One shared query**: the PM Compliance due-window query moved out of `DashboardKpis::pmCompliance` into a public `DashboardKpis::pmComplianceDueTasks()`, which the dashboard and the report both use. The report's `summary` is `PmCompliance::calculate()` over its own rows, so `rows` count always equals `summary.due` and equals the dashboard's `pm.compliance` (tested, including tasks due on the first and last day). The per-task on-time rule moved into `PmCompliance::isOnTime()` with no change in behaviour.
3. **API shape**: `GET /api/reports/pm-compliance` returns `{period: {from, to}, summary: {due, on_time, compliance}, rows: [...]}` with no `data` wrapper, exactly as the criterion states. `compliance` is the dashboard's `percent` (0-100, one decimal, half-up), or `null` when nothing is due.
4. **Row values**: `status` is the PM Status enum value (`scheduled`, `in_progress`, `for_validation`, `returned`, `completed`), in JSON and in the xlsx. `vendor_name` is the current name in the PSS `vendors` table (not a snapshot), so a renamed Vendor shows its new name on old tasks. `on_time` is true only for a Completed task with a Work Done Date on or before its due date (the existing PM Compliance rule). The tie-break after due date, Site name and Equipment name is the PM Task id.
5. **Export layout**: one sheet with the default name ("Worksheet"), no title rows and no styling. The header labels are `Site, Equipment, Equipment category, Vendor, Due date, Work done date, Status, On time`. Every cell is written as text, dates included; an empty Work Done Date is an empty cell. The filename is `pm-compliance-{from}-to-{to}.xlsx`, using the default period when none is given. Content-Type is `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`.
6. **Shared parts for 52-54**: `App\Http\Requests\ReportRequest` (filter and period validation, the same rules as the dashboard; `filters()` and `period($today)`), `App\Reports\XlsxExport::download($filename, $header, $rows)`, and the route group `reports.*` behind `can:view-reports` in `routes/api.php`. The `view-reports` gate is defined in `AppServiceProvider::registerReportGates()`.
7. **Gate order**: the pending block runs before the gate, so a pending user always gets the pending 403, even with a role. The gate runs before validation, so a user without access gets 403, not 422, for bad filters.
8. **Vendor scoping**: reuses `DashboardCounts::visibleFilters()`, so a vendor-linked user's `vendor_id` replaces any `vendor_id` they pass. Unknown `site_id`, category or vendor values give an empty report rather than an error, as on the dashboard.
9. **Performance**: two queries whatever the row count: the PM Tasks, then the Vendors loaded with them (`with('vendor:id,name')`). This is tested for both endpoints. There is no row limit or pagination; the export builds the whole sheet in memory before streaming. That is fine for a month of PM Tasks, but a multi-year period over many Sites could use a lot of memory. Add a limit later if needed.

### QA (qa-29, 2026-11-25): PASS

- `php artisan test --compact` (backend): 1393 passed, 6529 assertions. `vendor/bin/pint --test`: pass. No frontend change.
- `PmComplianceReportApiTest` has 16 tests: one per gate outcome, Vendor scoping with another Vendor's PM Task on the same Site, the xlsx file opened with PhpSpreadsheet (header row, data rows, one sheet, cell types, filename, content type), the query count for both endpoints, and rows == summary.due == dashboard pm.compliance on the first and last day.
- The only new package is `phpoffice/phpspreadsheet` (and its dependencies). No master tables were created.
