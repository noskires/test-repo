# 36 — Make the backend test suite safe to run concurrently

Status: done
Merged: PR #20 (0b58fc3), 2026-11-22
Blocked by: none
Type: chore

## What to build

`Storage::fake()` puts its files under the shared folder `storage/framework/testing/disks/<disk>`, and **empties that folder** every time it's called. When two `php artisan test` runs happen at the same time (e.g. dev-lead and qa on the same checkout), they read, write and wipe each other's fake files. That gives false failures in `ECallApiTest`, `ECallProposalApiTest` and `PmReportApiTest` ("Failed asserting that an array is empty", `UnexpectedValueException`).

Give each test **process** its own fake-disk root, e.g. in `tests/TestCase.php`:
- The base TestCase points fake disks at a per-process folder, e.g. `storage/framework/testing/<pid or unique token>/disks/<disk>`, for every test that calls `Storage::fake()`, without changing each test file.
- Watch out: passing only `['root' => ...]` to `Storage::fake()` still lets it empty the **shared** default folder before applying the override, so that alone isn't enough. Either move the storage path per process (e.g. `$this->app->useStoragePath(...)` in `setUp`, checking that nothing else relying on `storage_path()` breaks), or wrap faking in a helper that never touches the shared folder. The approach is the developer's choice.
- Remove the per-process folder at the end of the run (or test), so runs don't leave folders behind.
- Laravel's built-in per-token paths only apply to `php artisan test --parallel`, which needs paratest. Don't add it: no new packages.

## Acceptance criteria

- [x] Fake disks in tests resolve to a per-process root, not `storage/framework/testing/disks/`. A test asserts that the faked disk's root path contains the process-specific part.
- [x] Faking a disk never deletes files under another process's root, and never under the shared `storage/framework/testing/disks/` folder. Test this with a file planted in a sibling root, which must survive.
- [x] Two full `php artisan test` runs started at the same time on the same checkout both pass. Record the command and both results in the PR or QA notes. A manual check is fine, since this can't be a PHPUnit assertion.
- [x] No per-process folders are left behind after a run.
- [x] Existing tests are unchanged apart from any mechanical cleanup. No new packages, and `php artisan test` passes.

## Comments

- 2026-11-22 (product-owner): Created at the team lead's request after false failures when dev-lead and qa ran the suite at the same time. The team lead decides when this is built relative to issue 17, so it hasn't been handed off yet.
- 2026-11-22 (qa): QA PASS. `php artisan test` from backend/ gives 530 passed (1897 assertions), 0 failed. `tests/Feature/ConcurrentSafeFakeStorageTest.php` gives 4 passed (8 assertions). Concurrency check: two full `php artisan test --compact` runs started together from backend/, in 3 rounds. All 6 runs gave 530 passed (1897 assertions), exit 0. Two `process-{pid}-{hex}` folders existed during each round and none afterwards. The sibling-root test plants files in a sibling process root and in the shared `disks` folder, and both survive `Storage::fake`.
