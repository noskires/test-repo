# 35 — Set the application timezone to Asia/Manila

Status: ready-for-agent
Blocked by: none
Type: task

## What to build

The human decided that PSS uses **Asia/Manila** as its application timezone, switching now, before go-live (docs/adr/0003-application-timezone-asia-manila.md). The scaffold still has `'timezone' => 'UTC'` in `backend/config/app.php`, and `.env.example` has no timezone entry.

- `config/app.php`: `'timezone' => env('APP_TIMEZONE', 'Asia/Manila')`.
- `backend/.env.example`: add `APP_TIMEZONE=Asia/Manila`. Check that `phpunit.xml` doesn't override it to another timezone.
- Every date rule is evaluated in Manila time, with no hard-coded `UTC` anywhere in `app/`:
  - the Work Done Date window (not in the future, and not before the `started_at` date; issues 10 and 34);
  - PM due-date computation and the daily generation job (issues 06 and 08), which runs at Manila midnight;
  - "today" generally.
- Pre-go-live, no data migration is needed. Don't rewrite stored timestamps.

Out of scope: per-user timezones, and the UI's display formatting (the frontend shows what the API returns).

## Acceptance criteria

- [ ] `config('app.timezone')` is `Asia/Manila` when `APP_TIMEZONE` isn't set, and `.env.example` contains `APP_TIMEZONE=Asia/Manila`. A test asserts the config value under the test environment.
- [ ] **Boundary test, "not in the future"**: with time frozen at 2026-11-23 01:00 Manila (= 2026-11-22 17:00 UTC), a Work Done Date of 2026-11-23 is accepted, and 2026-11-24 returns 422.
- [ ] **Boundary test, "not before start"**: a task started at 2026-11-23 07:30 Manila (= 2026-11-22 23:30 UTC) accepts a Work Done Date of 2026-11-23 and rejects 2026-11-22.
- [ ] **PM due dates**: generating a task with time frozen inside the 00:00–08:00 Manila window gives the same due date as computing it from the Manila calendar date (e.g. `installedOn` + frequency). The successor's due date counts from the Work Done Date as a Manila date.
- [ ] `grep` finds no hard-coded `'UTC'` timezone in `backend/app`, and the scheduler entry for the PM generation job runs in the app timezone.
- [ ] The full suite (`php artisan test`) passes. No new packages.

## Comments

- 2026-11-22 (product-owner): Created from the human's timezone decision (ADR 0003). Use `$this->travelTo(...)` or `Carbon::setTestNow` for the boundary tests. 2026-11-23 01:00 Asia/Manila equals 2026-11-22 17:00 UTC, which is inside the 16:00–24:00 UTC window where UTC is still on the previous date.
