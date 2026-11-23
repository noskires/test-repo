# 37 — Vendor staff can never act as EIC

Status: done
Merged: PR #22 (75de780), 2026-11-22
Blocked by: none
Type: chore

## What to build

The human decided (2026-11-22) to close this authorization gap now, before issue 18. It was found in the issue 11 and 17 reviews and recorded in issue 04 as alignment item 1.

Today the two workflows check the EIC differently:
- **E-Call review** (issue 17): `ECall::isReviewer` requires `vendor_id === null` **and** `SiteSetting::isCurrentEic(...)`. Vendor staff are excluded.
- **PM Task validation** (issue 11): `PmTaskPolicy::validate` and the in-lock re-check in `PmTask::validateReport` call only `SiteSetting::isCurrentEic(...)`. A user with a `vendor_id` whose `employee_id` matches the Site EIC could approve or return a PM Report.

Build **one shared check**, "is the Site's current EIC **and** not vendor staff", e.g. `SiteSetting::isActingEic(string $siteId, User $user, bool $lockForUpdate = false)` or a small dedicated class (the name and location are the developer's choice). Then:
- Use it in `PmTaskPolicy::validate` (and so in `viewApprovals`, which builds on it) and in the in-lock re-check in `PmTask::validateReport`.
- Use it for E-Call review, replacing `ECall::isReviewer`'s own logic. Either delete `isReviewer` and call the shared check, or make `isReviewer` a thin delegate with no logic of its own.
- Make it the check that Work Order EIC actions (issues 21 and 25) and issue 18 will use. Document it on the method.
- Keep `SiteSetting::isCurrentEic` only if something still needs the raw "matches the Site EIC" lookup. Otherwise fold it into the shared check, so there's one entry point.

Out of scope: rejecting a vendor-staff employee as the Site EIC at assignment time (issue 05). That can be raised separately.

## Acceptance criteria

- [x] There is exactly one shared "current Site EIC and not vendor staff" check. `PmTaskPolicy`, `PmTask::validateReport`, `ECallPolicy` and the E-Call review in-lock re-check all call it, and none of them repeats the `vendor_id` or EIC comparison itself. A unit test covers it: the current EIC passes; a vendor user with a matching `employee_id` fails; a user with a null `employee_id` fails; a Site with no EIC fails; a replaced EIC fails.
- [x] A user with a `vendor_id` whose `employee_id` matches the Site's current EIC gets **403** on `POST /api/pm-tasks/{task}/approve` and `/return`. The task's status is unchanged, and **no Approval Record** is written.
- [x] The in-lock re-check refuses the same vendor user. Test this by bypassing or passing the request-level check (e.g. calling `PmTask::approveReport` / `returnReport` directly, or as in issue 11's in-lock tests): an authorization error, no status change, no record.
- [x] E-Call review behaviour is unchanged. The existing `ECall` review tests pass without changing their expectations, including the vendor-user-as-EIC case that already gets 403.
- [x] Full `php artisan test` passes. No new packages.

## Comments

- 2026-11-22 (product-owner): Created from the human's decision to fix issue 04's alignment item 1 now. Issue 18 comes after this, and any EIC check it needs must use this shared check.
- 2026-11-22 (qa): QA PASS. `php artisan test` from backend/ gives 588 passed (2191 assertions), 0 failed. `tests/Unit/SiteSettings`, `tests/Feature/PmTaskValidationApiTest.php` and `tests/Feature/ECallReviewApiTest.php` give 96 passed (460 assertions). Every acceptance criterion maps to a test that asserts it. `SiteSetting::isActingEic` is the only EIC comparison in app/, and `isCurrentEic` and `isReviewer` are gone. Vendor staff carrying the EIC's employee_id get 403 on PM approve and return, and the in-lock re-check refuses them too, with no status change, record or event. `ECallReviewApiTest` is unchanged and passes.
