<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## PSS: sign-in setup

PSS signs users in with JWT (`tymon/jwt-auth`). See `docs/adr/0004-jwt-login-and-pss-roles.md` at the repo root.

1. **JWT secret, once per deployment:** run `php artisan jwt:secret`. `.env.example` has only the placeholder `JWT_SECRET=change-me`. Never commit a real secret.
2. **Token lifetimes:** `JWT_TTL=60` (access token, minutes) and `JWT_REFRESH_TTL=20160` (refresh window, minutes = 14 days).
3. **Logout blacklist needs a shared cache store.** Logout blacklists the token in the cache. Use a store that every app server shares (e.g. `CACHE_STORE=database` or Redis). A per-server store such as `file` or `array` lets a logged-out token keep working on another server.
4. **First System Administrator:** run the master data import first (`php artisan pss:import-master-data`, see below), because the command looks the employee up in the imported `employees` table. Then set `PSS_FIRST_ADMIN_EMPLOYEE_ID` (an employee ID from the master data), `PSS_FIRST_ADMIN_EMAIL` (their sign-in email; `--email=` on the command wins over it) and `PSS_FIRST_ADMIN_TEMPORARY_PASSWORD` (at least 8 characters), and run `php artisan pss:setup-first-admin`. The admin signs in with that email and temporary password and must change the password first. It's safe to run again: a user who already has an email keeps their email and password, and then the email and password settings aren't needed. The user it creates is `internal`. After that, admins add users, grant roles and link vendor staff through `/api/admin/users`. Remove `PSS_FIRST_ADMIN_TEMPORARY_PASSWORD` from `.env` once the admin has signed in.
5. **Sign-in is email + password** (issue 44, ADR 0004 amendment 2026-11-24), checked against the `users` table in every environment. `POST /api/auth/login {email, password}`: a missing or invalid email is **422**; a wrong email or password is **401** "Invalid email or password." whether or not the person is a PSS user. Emails are stored trimmed and lower-case, so sign-in ignores case. A user row without an email can't sign in until an admin gives it one.
6. **Users are added by an administrator** (issue 46, `docs/adr/0005-admin-created-users-and-pending-access.md`). Sign-in never creates a PSS user. A System Administrator adds each one with `POST /api/admin/users` (`employee_id`, `email`, `temporary_password`, plus `access: "internal"` or a `vendor_id`). A **pending** user (e.g. vendor staff whose Vendor was unlinked) can sign in but gets 403 on every API route except `auth/me`, `auth/refresh`, `auth/password` and `auth/logout`, until an admin links a Vendor or sets `PUT /api/admin/users/{user}/access {access: "internal"}`.
7. **Temporary passwords** (issue 44). A new user, an admin reset and the first-admin command give a temporary password. Until it is changed, the login response and `auth/me` say `must_change_password: true`, and every API route except `auth/password`, `auth/me`, `auth/refresh` and `auth/logout` is **403** "Change your temporary password first." Users change their own password with `POST /api/auth/password {current_password, password, password_confirmation}` (422 for a wrong current password, a mismatch, a weak password or the same password). A System Administrator resets a forgotten one with `POST /api/admin/users/{user}/password {temporary_password}`. There is no email reset flow. Passwords use Laravel's default `Password` rule (at least 8 characters).
8. **Vendor staff can't be System Administrators** (issue 45). Granting `system-administrator` to a vendor-linked user, or linking a System Administrator to a Vendor, is refused with 422. After deploying issue 45, run `php artisan pss:check-vendor-admins` once. It lists any vendor-linked System Administrators left from earlier data (employee ID, name, Vendor) and changes nothing. Revoke the role or unlink the Vendor by hand for anyone it finds. `pss:setup-first-admin` still grants the role to a vendor-linked employee but prints a warning pointing to this check.
9. **Local demo accounts** (local environment only; `php artisan migrate:fresh --seed` or `php artisan db:seed` runs `MasterDataSampleSeeder` then `DemoAccountsSeeder`). Every account uses the password `password` and has no temporary password to change:

   | Email | Employee ID | Role / what it can test |
   |---|---|---|
   | `admin@pss.test` | `EMP-9001` | System Administrator |
   | `viewer@pss.test` | `EMP-9002` | Management / Viewer |
   | `requestor@pss.test` | `EMP-1001` | Requestor (ordinary employee; files E-Calls) |
   | `supervisor@pss.test` | `EMP-2001` | Supervisor / Team Head (the Requestor's Immediate Head) |
   | `divhead@pss.test` | `EMP-3001` | Division Head (next in the Immediate Head chain) |
   | `centerhead@pss.test` | `EMP-4001` | Center Head |
   | `sectorhead@pss.test` | `EMP-5001` | Sector Head (top of the chain) |
   | `eic@pss.test` | `EMP-6001` | EIC of both sample Sites (`SITE-MKT`, `SITE-CEB`) |
   | `vendor1@pss.test` | `EMP-8001` | Vendor staff of "Demo Facilities Services Inc.", the Site Vendor of `SITE-MKT` |
   | `vendor2@pss.test` | `EMP-8002` | Vendor staff of "Second Demo Maintenance Corp.", the Site Vendor of `SITE-CEB` |

   The approver accounts need no PSS role: the chain comes from the master data. Seeding again resets these accounts' emails and passwords and the two Sites' Vendor and EIC to the values above.

## PSS: master data import

Sites, Equipment, Equipment Categories and Employees belong to a separate source database (issue 03, `docs/adr/0001-master-data-owned-by-external-api.md`). PSS keeps a read-only copy in its own `sites`, `equipment`, `equipment_categories` and `employees` tables and never edits it.

1. **Connection:** set `MASTER_SOURCE_DB_DRIVER` (default `mysql`), `MASTER_SOURCE_DB_HOST`, `MASTER_SOURCE_DB_PORT`, `MASTER_SOURCE_DB_DATABASE`, `MASTER_SOURCE_DB_USERNAME` and `MASTER_SOURCE_DB_PASSWORD` (connection `master_source` in `config/database.php`). Use a database user that can only `SELECT`. With `MASTER_SOURCE_DB_DATABASE` empty the import exits non-zero and changes nothing.
2. **Import:** `php artisan pss:import-master-data`. It upserts by source ID, marks rows that left the source inactive (never deletes them), reactivates them when they return, and prints inserted / updated / deactivated per table. Safe to run again. The scheduler runs it nightly at 23:30 Asia/Manila, before the midnight PM Task generation, so `php artisan schedule:run` must be on cron.
3. **Source schema is a guess.** Every source table and column name lives in `App\MasterData\Import\SourceSchema`. When the real schema is known, change only that class (and its tests). Equipment comes from `aircondition_units` (ACU), `gensets` (Genset) and `ats` (ATS); Building is not imported.
4. **Inactive Equipment** gets no new PM Tasks and no new E-Calls (422). Its existing records still read from their Snapshots.
5. **Local dev:** `php artisan migrate --seed` fills the master data tables with the sample data (`MasterDataSampleSeeder`) before the demo accounts. A later real import deactivates those sample rows.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
