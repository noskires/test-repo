# The application timezone is Asia/Manila, set before go-live

PSS was scaffolded with Laravel's default `UTC` timezone. The human decided (2026-11-22) that PSS runs on **Asia/Manila** (UTC+8, no daylight saving) as its application timezone, and that the switch happens **now, before go-live**.

Reasons:
- **The users are in the Philippines.** "Today" should mean today in Manila for every user: Requestors, EICs, vendor staff and approvers.
- **Date rules depend on what "today" is**:
  - the Work Done Date window ("not in the future" and "not before the task's start date", issues 10 and 34);
  - PM due dates and the sliding schedule (issues 06 and 08);
  - PM Timeliness, i.e. Upcoming / Due / Overdue (issue 09).

  On UTC, between 00:00 and 08:00 Manila time the server still thinks it's yesterday. A vendor could then be refused a Work Done Date of today, or a task could turn Overdue eight hours late.
- **The daily PM generation job** (issue 08) should run at Manila midnight, not 08:00 Manila.
- **Changing it after go-live would reinterpret stored timestamps.** Laravel stores timestamps as wall-clock values in the app timezone with no offset, so switching later would silently shift every stored `created_at`, `started_at`, `submitted_at` and so on by 8 hours. Before go-live there's no production data to shift.

## Considered Options

- **Keep UTC storage and convert to Manila only for date rules and display**: rejected. Every date comparison would need an explicit conversion, and one forgotten conversion gives an off-by-one-day bug. One user base in one timezone doesn't need it.
- **Per-user timezones**: rejected. Everyone is in the Philippines, and it adds complexity with no benefit for the MVP.

## Consequences

- `config/app.php` defaults to `Asia/Manila`, and `.env.example` sets it. Date-only rules (`today`, `before_or_equal:today`, comparing a timestamp's date) are evaluated in Manila time.
- Stored timestamps are Manila wall-clock time. Any future integration that exchanges timestamps (e.g. the external master data API) must convert explicitly at the boundary.
- If PSS ever serves users in other timezones, revisiting this needs a data migration. It isn't a config flip.
