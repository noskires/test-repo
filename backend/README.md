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
4. **First System Administrator:** set `PSS_FIRST_ADMIN_EMPLOYEE_ID` to an employee ID from the external master data, then run `php artisan pss:setup-first-admin`. It's safe to run again. The user it creates is `internal`. After that, admins add users, grant roles and link vendor staff through `/api/admin/users`.
5. **Users are added by an administrator** (issue 46, `docs/adr/0005-admin-created-users-and-pending-access.md`). Sign-in never creates a PSS user. A System Administrator adds each one with `POST /api/admin/users` (`employee_id` plus `access: "internal"` or a `vendor_id`). Sign-in checks the password first: a wrong password is **401** "Invalid employee ID or password." whether or not the person is a PSS user; a correct password from someone not in PSS is **403** "Your account isn't set up in PSS yet. Ask an administrator." A **pending** user (e.g. vendor staff whose Vendor was unlinked) can sign in but gets 403 on every API route except `auth/me`, `auth/refresh` and `auth/logout`, until an admin links a Vendor or sets `PUT /api/admin/users/{user}/access {access: "internal"}`. Admin-added users have no local password, so with the local credential checker they can't sign in (expected until issue 44).
6. **Vendor staff can't be System Administrators** (issue 45). Granting `system-administrator` to a vendor-linked user, or linking a System Administrator to a Vendor, is refused with 422. After deploying issue 45, run `php artisan pss:check-vendor-admins` once. It lists any vendor-linked System Administrators left from earlier data (employee ID, name, Vendor) and changes nothing. Revoke the role or unlink the Vendor by hand for anyone it finds. `pss:setup-first-admin` still grants the role to a vendor-linked employee but prints a warning pointing to this check.
7. **Local demo accounts** (local environment only; `php artisan db:seed` runs `DemoAccountsSeeder`). All use the password `password`:

   | Employee ID | Account |
   |---|---|
   | `EMP-9001` | System Administrator |
   | `EMP-9002` | Management / Viewer |
   | `EMP-1001` | Ordinary employee (Requestor) |
   | `EMP-8001` | Vendor staff, linked to the demo Vendor |

The local credential checker works only in `local` and `testing`. Sign-in in other environments needs the external credential checker (issue 44).

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
