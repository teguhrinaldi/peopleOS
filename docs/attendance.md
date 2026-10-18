# Attendance

Attendance is the signature feature. This document is the source of truth for its rules.

## Concepts

| Concept | Table | Purpose |
|---|---|---|
| Attendance policy | `attendance_policies` | GPS required, geofence mode, selfie required, max GPS accuracy, mock-location rejection, offline window |
| Attendance schedule | `attendance_schedules` | **Immutable snapshot** of what was expected of one employee on one date |
| Attendance | `attendance` | One record per employee per scheduled day |
| Attendance event | `attendance_events` | Each CHECK_IN / CHECK_OUT with GPS, distance, selfie reference, idempotency key |
| Correction | `attendance_corrections` | Employee-requested fix, reviewed by manager/HR |
| Overtime request | `overtime_requests` | Request → review → verified → paid by payroll ([overtime.md](overtime.md)) |

### Schedule snapshot

On the first check-in (or correction) for a date, `ScheduleService` resolves and **freezes**: shift name, expected start/end (UTC), grace periods, break minutes, work location name/coordinates/radius, and the policy flags. Moving an employee to another shift or location tomorrow never changes yesterday's snapshot, so late/early/geofence results stay historically correct (covered by `test_changing_the_shift_tomorrow_does_not_rewrite_yesterdays_schedule`).

Resolution order:

1. **Shift** – `shift_assignments` (date-ranged override) → the shift on the `employee_assignments` row effective on that date
2. **Calendar** – `working_calendar_assignments` → company default calendar → Mon–Fri
3. **Day type** – holiday → `holiday`; non-working weekday → `rest_day`; else `workday`
4. **Policy** – work location's policy → company default policy → built-in defaults

Check-in on a rest day or holiday requires an **approved overtime request** for that date; otherwise `NOT_A_WORKING_DAY`.

## Overnight shifts

A shift whose end time is not after its start (22:00 → 06:00) is **one logical shift** belonging to the date it *starts* on. Its end is computed by rolling forward a day in the company timezone (`ShiftCalculator::window`), never by comparing times on the same calendar day.

* Check-out at 06:03 the next morning closes the *same* attendance.
* A check-in at 01:00 while yesterday's overnight shift is still running (and has no attendance yet) belongs to **yesterday's work date** and is measured late against 22:00.

## Server time

The server clock is authoritative. `occurred_at` (UTC) is what all maths uses.

* Normal requests: `occurred_at = server now`. `device_captured_at` is stored for diagnostics only and never used.
* **Offline syncs** (`is_offline_sync=true`) may use the device time because the tap happened earlier. Accepted only if: it is not in the future (300 s clock skew tolerated, then clamped to now), and not older than the policy's `offline_max_age_minutes` (default 720). Otherwise `INVALID_EVENT_TIME` / `OFFLINE_EVENT_TOO_OLD` and the employee uses a correction request.
* `received_at` always records the real server receive time, and `time_source` says `server` or `device_offline`.

Timezone is `companies.timezone` (default `Asia/Jakarta`), configurable in the web admin. All datetimes are stored in UTC.

## GPS & geofence

* Only captured at check-in / check-out. There is **no background tracking**.
* Distance is computed **server-side** with the haversine formula against the *snapshotted* site coordinates. The client cannot send or influence "inside".
* Geofence mode per policy: `enforce` (reject `OUTSIDE_GEOFENCE`), `review` (accept but flag `PENDING` for HR), `off`.
* Also enforced: coordinates valid, accuracy ≤ `max_gps_accuracy_meters`, mock location rejected, online location fix not older than 5 minutes.

## Selfie

Optional per policy (`require_selfie`). Multipart `selfie` (JPEG/PNG, ≤ 5 MB, validated by extension *and* MIME). Stored on the **private** `local` disk at `attendance-selfies/{employeeId}/{yyyy}/{mm}/{random}`; the database keeps only the path and metadata (mime, size, sha256). Files are never publicly served; `GET /api/v1/attendance-events/{id}/selfie` streams them after a policy check (owner, or someone permitted to view that attendance). The path never appears in JSON.

## State machine

Server `attendance.status`:

```
                       ┌────────────── review mode, outside geofence ──────────────┐
                       ▼                                                            │
 (no record) ──check-in──► CHECKED_IN ──check-out──► CHECKED_OUT                    │
                       │                    │                                       │
                       │                    │        HR approves flagged record      │
                       ▼                    ▼        (PENDING → CHECKED_IN/OUT)     │
                    PENDING ◄───────────────┴────────────────────────────────────────┘
                       │  HR rejects flagged record
                       ▼
                    REJECTED

 CHECKED_IN / CHECKED_OUT ──correction requested──► CORRECTION_REQUESTED
        CORRECTION_REQUESTED ──approved──► CORRECTED
        CORRECTION_REQUESTED ──rejected / cancelled──► (previous status restored)
        (no record) ──correction approved──► CORRECTED
```

* `PENDING` exists only in `review` geofence mode: the attendance is valid and can be checked out, but is flagged `needs_review`.
* Corrections/overtime: `PENDING → APPROVED | REJECTED | CANCELLED` (terminal). Deciding a decided request is `409 REQUEST_NOT_PENDING`.
* A terminated / inactive / pending employee cannot check in, check out or request corrections.

Client-side sync states (Flutter outbox, not stored on the server): `LOCAL → PENDING_SYNC → SYNCED | SYNC_FAILED`.

## Idempotency & concurrency

Each user action carries an `idempotency_key` generated **once** and re-sent on every retry.

1. `attendance_events.idempotency_key` is `UNIQUE`.
2. A known key returns the stored result (`200`, `replayed:true`); a key reused for another employee or action is `409 IDEMPOTENCY_KEY_CONFLICT`.
3. `attendance(employee_id, attendance_schedule_id)` is `UNIQUE`: one record per employee per scheduled day.
4. Writes run in a transaction with `SELECT … FOR UPDATE` on the day's schedule / attendance row.
5. A request that loses a race re-checks its own key before failing, so a retry never surfaces as a conflict.

`tests/Feature/ConcurrencyTest.php` proves this with **separate PHP processes** hitting MySQL at the same instant: two check-ins with different keys → exactly one attendance and one `ALREADY_CHECKED_IN`; three simultaneous retries of one key → one record, no errors; two simultaneous check-outs → one succeeds.

## Lateness rules

* `late_minutes` = 0 while inside `grace_late_minutes`; once exceeded, the **whole** delay from the scheduled start counts (grace is a threshold, not a discount).
* `early_leave_minutes` uses `grace_early_leave_minutes` the same way.
* `worked_minutes` = clock minutes minus the shift's unpaid break (not deducted when the span is shorter than the break).
* None of these apply on rest days/holidays (overtime days).

## Corrections

`POST /attendance-corrections` (last 30 days, at least one time, times near the shift and not in the future, one pending per day). Reviewers: `attendance.correct` (anyone) or `attendance.approve_team` (direct reports). **Nobody can review their own request**, even with the permission. Reject requires a note. Approve creates/updates the attendance, recalculates lateness from the frozen schedule, sets `CORRECTED` and writes the audit log.

## Overtime

Request → review (`overtime.approve` / `overtime.approve_team`) → recorded. Approved minutes may be lower than requested. Approval freezes the day type and records a first attendance verification (`verified_minutes`); pay is computed by the overtime rule engine during payroll — see [overtime.md](overtime.md).
