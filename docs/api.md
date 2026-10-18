# PeopleOS REST API v1

Base path: `/api/v1` · Auth: Laravel Sanctum bearer token · Format: JSON (`Accept: application/json`).

The API is **employee-self-service first**. Administrative work (creating employees, shifts, locations…) happens in the Web Admin, not here. Permission-gated read/review endpoints exist so managers and HR can use the same API.

## Envelope

```jsonc
// success
{ "success": true, "data": <object|array|null>, "meta": { "page": 1, "per_page": 20, "total": 84, "last_page": 5 } }   // meta only on paginated lists
// failure
{ "success": false, "error": { "code": "OUTSIDE_GEOFENCE", "message": "Human readable", "details": { } } }
```

Clients must branch on `error.code`, never on `message`.

## Time

* All instants are returned as ISO‑8601 **with the company UTC offset** (`2026-09-30T08:42:00+07:00`).
* Every instant also has a ready-to-render local `HH:mm` twin (`check_in_time`, `start_time` …).
* The **server clock is authoritative**. A device timestamp (`device_captured_at`) is only honoured for events flagged `is_offline_sync=true`, inside the policy's `offline_max_age_minutes` window (see [attendance.md](attendance.md)).
* Default timezone is `Asia/Jakarta`; the real value is `company.timezone` from `/auth/me`.

## Status codes and error codes

| HTTP | `error.code` | Meaning |
|---|---|---|
| 401 | `UNAUTHENTICATED` / `INVALID_CREDENTIALS` | missing/expired token · wrong email or password |
| 403 | `FORBIDDEN` | authenticated but not allowed (permission or ownership) |
| 403 | `ACCOUNT_DISABLED` `EMPLOYEE_TERMINATED` `EMPLOYEE_NOT_ACTIVE` `NO_EMPLOYEE_PROFILE` | account/employee state blocks the action |
| 404 | `NOT_FOUND` | unknown id (also used for ids outside the caller's scope on some lists) |
| 409 | `ALREADY_CHECKED_IN` `ALREADY_CHECKED_OUT` `NOT_CHECKED_IN` `CHECK_OUT_TOO_LATE` `IDEMPOTENCY_KEY_CONFLICT` `CORRECTION_ALREADY_PENDING` `REQUEST_NOT_PENDING` `OVERTIME_OVERLAP` `EMPLOYEE_DELETE_RESTRICTED` | state conflict |
| 422 | `VALIDATION_ERROR` (with `details.<field>[]`) | invalid input |
| 422 | `OUTSIDE_GEOFENCE` (`details.distance_meters`, `radius_meters`) | server-computed distance exceeds the radius |
| 422 | `GPS_REQUIRED` `GPS_ACCURACY_TOO_LOW` `MOCK_LOCATION_DETECTED` `LOCATION_STALE` `INVALID_COORDINATES` | location policy |
| 422 | `SELFIE_REQUIRED` | policy demands a selfie |
| 422 | `NO_SCHEDULE` `NOT_A_WORKING_DAY` | nothing scheduled for that day |
| 422 | `OFFLINE_EVENT_TOO_OLD` `INVALID_EVENT_TIME` | offline sync outside the allowed window / impossible time |
| 422 | `CORRECTION_WINDOW_EXPIRED` `CHECK_IN_REQUIRED` `REJECTION_REASON_REQUIRED` `OVERTIME_TOO_LONG` `INVALID_WORK_DATE` | request rules |
| 429 | `TOO_MANY_REQUESTS` / `TOO_MANY_ATTEMPTS` (`details.retry_after_seconds`) | rate limited |
| 5xx | `SERVER_ERROR` | unexpected |

## Auth

### `POST /auth/login`
Body: `{ "email", "password", "device_name?" }` → `data`:
```json
{
  "user": { "id": 4, "name": "Michael Carter", "email": "employee@peopleos.test" },
  "employee": { "...see Employee..." },
  "permissions": ["attendance.self", "correction.request", "..."],
  "company": { "id": 1, "name": "PeopleOS Demo Company", "timezone": "Asia/Jakarta" },
  "token": "1|xxxxxxxx", "token_type": "Bearer", "expires_at": "2026-10-30T07:35:04+00:00"
}
```
Throttled: 5 failed attempts per email+IP per minute → `429 TOO_MANY_ATTEMPTS`.

### Language preference
`PUT /auth/preferences` with `{ "locale": "en" | "id" | null }` saves the user's language (`null` = follow the company default). Returns the `/auth/me` object. Login and `/auth/me` include `data.preferences`: `{ locale, effective_locale, default_locale, supported_locales, currency }`. Language is chosen per request in this order: the signed-in user's saved preference, the `X-Locale` or `Accept-Language` header (use it before login), the company default (`general.locale` setting), then English. Translation only affects text and formatting: stored amounts, timestamps and finalized snapshots never change. Clients should map `error.code` to their own localized messages.

### `POST /auth/logout` · `GET /auth/me`
`/auth/me` returns the same object as login without the token fields.

## Employee object
```json
{ "id": 6, "employee_number": "EMP-0012", "full_name": "Michael Carter", "photo_url": null,
  "gender": "male", "date_of_birth": "1990-11-18", "phone": "…", "email": "…", "address": "…",
  "join_date": "2022-01-15", "employment_type": "permanent", "status": "ACTIVE", "terminated_at": null,
  "department": { "id": 4, "name": "Marketing" }, "position": { "id": 5, "name": "Marketing Staff" },
  "branch": { "id": 1, "name": "Bandung Head Office" }, "manager": { "id": 4, "full_name": "Amanda Clark" },
  "work_location": { "id": 1, "name": "Head Office" },
  "shift": { "id": 1, "name": "Regular Shift", "start_time": "08:00", "end_time": "17:00" } }
```
`status`: `PENDING | ACTIVE | INACTIVE | TERMINATED`.

## Employees (read-only)
| Route | Permission / scope |
|---|---|
| `GET /employees/me` | any employee |
| `GET /employees?q=&status=&department_id=&page=&per_page=` | `employee.view` (all) or `employee.view_team` (direct reports + self) |
| `GET /employees/{id}` | policy: same scope as above, otherwise `403 FORBIDDEN` |

## Organization reference data (`organization.view`; shifts need `attendance.view`)
`GET /organization/company` · `/organization/branches` · `/organization/departments` · `/organization/positions` · `/organization/work-locations` · `GET /shifts`

## Attendance

### `GET /attendance/today`
Home-screen read model, computed on the server. Writes nothing.
```jsonc
{
  "server_time": "2026-09-30T14:35:11+07:00", "timezone": "Asia/Jakarta",
  "today": "2026-09-30", "work_date": "2026-09-30",     // work_date differs from today during an overnight shift
  "state": "NOT_CHECKED_IN",
  // NOT_CHECKED_IN | CHECKED_IN | CHECKED_OUT | PENDING_REVIEW_OPEN | PENDING_REVIEW | CORRECTION_PENDING | REJECTED
  // | NOT_WORKING_DAY | NO_SCHEDULE | BLOCKED
  "blocked_reason": null,          // EMPLOYEE_TERMINATED | EMPLOYEE_NOT_ACTIVE | NO_SCHEDULE | NOT_A_WORKING_DAY
  "can_check_in": true, "can_check_out": false,
  "elapsed_minutes": null,         // minutes since check-in while open
  "schedule": {
    "id": null, "work_date": "2026-09-30", "day_type": "workday",   // workday | rest_day | holiday
    "is_working_day": true, "shift_name": "Regular Shift",
    "start_time": "08:00", "end_time": "17:00",
    "expected_start_at": "…", "expected_end_at": "…", "crosses_midnight": false,
    "grace_late_minutes": 10, "break_minutes": 60, "expected_working_minutes": 480,
    "location": { "name": "Head Office", "latitude": -6.9218, "longitude": 107.6071, "radius_meters": 150 },
    "policy": { "require_gps": true, "geofence_mode": "enforce", "require_selfie": false,
                "max_gps_accuracy_meters": 100, "offline_max_age_minutes": 720 }
  },
  "attendance": null,              // Attendance object once a record exists
  "holiday": null, "pending_correction": null,
  "stale_attendance": null         // an unclosed check-in older than 24 h (can no longer be checked out); the app should offer a correction
}
```

### `POST /attendance/check-in` and `POST /attendance/check-out`
JSON, or `multipart/form-data` when a selfie is attached.

| Field | Notes |
|---|---|
| `idempotency_key` | **required**, 8–100 chars `[A-Za-z0-9._:-]`. Generate one UUID per user action and **re-send the same key on every retry** |
| `latitude`, `longitude`, `accuracy_meters` | required when the policy requires GPS |
| `location_captured_at` | ISO time of the GPS fix (online requests are refused when older than 5 min) |
| `device_captured_at` | ISO device time of the tap; only used with `is_offline_sync` |
| `is_offline_sync` | `true` when replaying a queued action |
| `is_mock_location` | pass the OS "mocked location" flag |
| `selfie` | JPEG/PNG ≤ 5 MB; required when `policy.require_selfie` |
| `device_platform`, `device_model`, `app_version` | diagnostics |

Success `201` (`200` when the key was already processed → `"replayed": true`):
```json
{ "attendance": { "...Attendance..." }, "event": { "id": 448, "type": "CHECK_IN", "occurred_at": "…", "distance_meters": 15.67, "inside_geofence": true }, "replayed": false }
```
The server recomputes the distance; the client's own "inside" opinion is never sent or trusted.

### Attendance object
```jsonc
{ "id": 228, "employee_id": 6, "work_date": "2026-09-30",
  "status": "CHECKED_IN",          // PENDING | CHECKED_IN | CHECKED_OUT | CORRECTION_REQUESTED | CORRECTED | REJECTED
  "needs_review": false,
  "check_in_at": "…", "check_out_at": null, "check_in_time": "08:04", "check_out_time": null,
  "worked_minutes": 0, "late_minutes": 0, "early_leave_minutes": 0, "is_late": false, "is_early_leave": false,
  "review_note": null, "shift_name": "Regular Shift", "location_name": "Head Office", "day_type": "workday",
  "expected_start_time": "08:00", "expected_end_time": "17:00",
  "check_in":  { "event_id": 448, "occurred_at": "…", "latitude": 0, "longitude": 0, "accuracy_meters": 12, "distance_meters": 15.67,
                 "inside_geofence": true, "is_offline_sync": false, "time_source": "server", "has_selfie": false, "selfie_url": null },
  "check_out": null }
```

### History
`GET /attendance?scope=mine|team|all&from=YYYY-MM-DD&to=YYYY-MM-DD&status=&employee_id=&page=&per_page=`
`mine` (default) is always the caller. `team` needs `attendance.view_team`; `all` needs `attendance.view`. `employee_id` only narrows within what the caller may see.

`GET /attendance/{id}` – own record, or team/all with permission (`403` otherwise).

`GET /attendance-events/{id}/selfie` – streams the private image if the caller may view that attendance (send the bearer header). Never guessable, never public.

### Review of flagged attendance (HR)
`POST /attendance/{id}/approve { note? }` · `POST /attendance/{id}/reject { note }` (**required**). Only for records in `PENDING` (created by the `review` geofence mode); needs `attendance.review`. Otherwise `409 REQUEST_NOT_PENDING`.

## Attendance corrections
| Route | Notes |
|---|---|
| `POST /attendance-corrections` | `{ work_date, requested_check_in_at?, requested_check_out_at?, reason }` (one time at least). Creates `PENDING`, attendance becomes `CORRECTION_REQUESTED` |
| `GET /attendance-corrections?scope=mine\|review&status=` | `review` = requests the caller can decide (`attendance.correct` all, `attendance.approve_team` direct reports); own requests are excluded |
| `GET /attendance-corrections/{id}` | owner or reviewer |
| `POST …/{id}/approve` `{ note? }` · `POST …/{id}/reject` `{ note }` (**required**) | reviewer only; **never the requester** |
| `POST …/{id}/cancel` | requester, only while `PENDING` |

Correction object: `{ id, employee{id,full_name,employee_number}, attendance_id, work_date, requested_check_in_at, requested_check_out_at, requested_check_in_time, requested_check_out_time, reason, status (PENDING|APPROVED|REJECTED|CANCELLED), reviewed_at, review_note, created_at }`

## Overtime requests
`POST /overtime-requests { work_date, start_at, end_at, reason }` · `GET /overtime-requests?scope=mine|review&status=` · `GET /{id}` · `POST /{id}/approve { approved_minutes?, note? }` · `POST /{id}/reject { note }` · `POST /{id}/cancel`

Overtime object: `{ id, employee{…}, work_date, start_at, end_at, start_time, end_time, requested_minutes, approved_minutes, reason, status, reviewed_at, review_note, created_at }`. Approved overtime is a **record only**; it is not converted to pay.

## Leave

Rules: [leave.md](leave.md). Days and balances are decimal strings with one decimal (`"2.5"`).

| Method & URL | Permission / scope | Notes |
|---|---|---|
| `GET /leave-types` | any signed-in employee | Active types with the policy that applies to the caller (`policy` may be `null`) |
| `GET /leave-balances?year=&employee_id=` | self; `employee_id` only within `leave.view` / `leave.view_team` scope, else `403` | `meta.year`, one row per type |
| `GET /leave-requests?scope=mine\|review\|all&status=&from=&to=&leave_type_id=&employee_id=` | `review` needs `leave.approve`/`leave.approve_team` (own excluded); `all` needs `leave.view`/`leave.view_team` | Drafts are only listed for their author |
| `POST /leave-requests/preview` | `leave.request` | Same body as create; returns `{days, working_dates, balance, available_after}` without writing |
| `POST /leave-requests` | `leave.request` | `{leave_type_id, start_date, end_date, half_day?: AM\|PM, reason?, submit?: true}` → `201`, status `PENDING` (or `DRAFT` with `submit:false`) |
| `GET /leave-requests/{id}` | owner, viewer in scope, reviewer | adds `working_dates` |
| `PATCH /leave-requests/{id}` | owner (draft only) | |
| `POST /leave-requests/{id}/submit` | owner | DRAFT → PENDING |
| `POST /leave-requests/{id}/approve` `{note?}` | reviewer, never own | PENDING → APPROVED |
| `POST /leave-requests/{id}/reject` `{note}` (**required**) | reviewer, never own | PENDING → REJECTED |
| `POST /leave-requests/{id}/cancel` `{reason?}` | owner or reviewer | approved leave only before it starts |
| `GET/POST /leave-setup/types`, `PATCH /leave-setup/types/{id}`, `PUT /leave-setup/types/{id}/policy`, `POST /leave-setup/balances/{id}/adjust {delta, reason}` | `leave.manage` | configuration |

Leave request object:
```json
{ "id": 12, "employee": {"id": 6, "full_name": "Michael Carter", "employee_number": "EMP-0012"},
  "leave_type": {"id": 1, "code": "ANNUAL", "name": "Annual Leave", "color": "blue", "is_paid": true},
  "start_date": "2026-10-26", "end_date": "2026-10-28", "half_day": null, "days": "3.0",
  "reason": "Sister's wedding in Medan", "status": "PENDING", "submitted_at": "…", "reviewed_at": null,
  "reviewer": null, "review_note": null, "cancelled_at": null, "cancel_reason": null, "created_at": "…" }
```
Balance object: `{ id, employee_id, leave_type{…}, year, unlimited, allowance, carried_over, adjustment, entitlement, used, reserved, available }` (`allowance`, `entitlement`, `available` are `null` when unlimited).

Errors: `LEAVE_OVERLAP` (409, `details.conflicting_request_id`), `LEAVE_INSUFFICIENT_BALANCE` (422, `details.available`, `requested`), `LEAVE_NOTICE_TOO_SHORT`, `LEAVE_TOO_FAR_IN_PAST`, `LEAVE_NO_WORKING_DAYS`, `LEAVE_SPANS_YEARS`, `LEAVE_TOO_LONG`, `HALF_DAY_SINGLE_DATE`, `HALF_DAY_NOT_ALLOWED`, `INVALID_DATE_RANGE`, `LEAVE_TYPE_UNAVAILABLE`, `LEAVE_POLICY_MISSING`, `LEAVE_ALREADY_STARTED` (409), `REQUEST_NOT_PENDING` (409), `REJECTION_REASON_REQUIRED`, `EMPLOYEE_TERMINATED`/`EMPLOYEE_NOT_ACTIVE` (403).

Attendance additions: `GET /attendance/today` can return `state: "ON_LEAVE"` (`blocked_reason: "ON_LEAVE"`), and check-in on a full day of approved leave returns `409 ON_LEAVE`.

## Notifications

In-app inbox of the signed-in user (other users' ids return `404`).

| Method & URL | Notes |
|---|---|
| `GET /notifications?unread=1&page=` | paginated, newest first; `meta.unread_count` |
| `GET /notifications/unread-count` | `{unread_count}` |
| `POST /notifications/{id}/read` | marks one read (idempotent) |
| `POST /notifications/read-all` | `{marked}` |

Notification object: `{ id, type, title, body, entity_type, entity_id, data, is_read, read_at, created_at }`. Types so far: `leave.pending_review`, `leave.approved`, `leave.rejected`, `attendance_correction.approved|rejected`, `overtime.approved|rejected`, plus payroll/document types listed in their sections.

## Payroll, payslips, salary and documents

Documented in their own files, same conventions: [api-payroll.md](api-payroll.md) (payslips, salary setup, payroll periods and workflow, adjustments) and [api-documents.md](api-documents.md) (document types, upload, download, archive).

## Reports

Real-data reports for screens and export. Each report authorizes itself: the viewer needs `report.view` plus one domain permission; scope comes from `AccessScope` (a manager only ever sees direct reports). A forbidden or unknown key returns the same `404`.

| Method & URL | Notes |
|---|---|
| `GET /reports` | Catalogue the caller may run: `{key, title, group, description, filters[], columns{key:label}, numeric_columns[]}` |
| `GET /reports/{key}?from=&to=&department_id=&employee_id=&status=&period_id=&days=&leave_type_id=&page=&per_page=` | `data.rows` (scalar values keyed by column), `data.totals`, `data.truncated`; `meta` pagination. Default window = this month to today; max 366 days |
| `GET /reports/{key}/export/csv` and `/export/pdf` | Same filters; contains **all** rows (cap 20,000, noted in the PDF). CSV is UTF-8 with BOM and neutralizes spreadsheet formulas. Exports are audited (`report.exported`) |

Keys: `attendance.daily` · `attendance.by_employee` · `attendance.by_department` · `attendance.late` · `attendance.absent` (needs a schedule, window ≤ 93 days, excludes today) · `leave.usage` · `leave.requests` (`status=PENDING|APPROVED|REJECTED|CANCELLED`) · `payroll.summary` · `payroll.detail` (`period_id`) · `documents.expiring` (`days`) · `documents.expired`.

Errors: `INVALID_DATE_RANGE`, `DATE_RANGE_TOO_LONG` (422), `VALIDATION_ERROR` (422), `NOT_FOUND` (404).

## Rate limits
`api` 120/min per user · `attendance` 30/min per user · login 20/min per IP (+5 failed per email+IP).

## Not in v1 yet
Leave, payroll/payslips, documents, notifications (Prompts 2+). The Flutter app renders these tabs from demo data only until then.
