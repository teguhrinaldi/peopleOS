# Testing

## Philosophy

Behaviour that matters (money, access, history, concurrency) is tested against **real MySQL**, because it depends on unique constraints, foreign keys and row locks that SQLite or mocks do not reproduce. Pure logic (shift maths, geofence distance, payroll calculation, state machines, file-name sanitising) is unit-tested without a database.

## Backend (Laravel)

Create a test database once:

```sql
CREATE DATABASE peopleos_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
cd BE
php artisan test                              # everything
php artisan test --testsuite=Unit             # no database, seconds
php artisan test tests/Feature/Api            # API behaviour
php artisan test tests/Feature/Admin          # web admin
php artisan test --filter=LeaveConcurrencyTest
```

`phpunit.xml` points at `peopleos_test`; override with `DB_DATABASE=...`. Never point tests at a real database: `ConcurrencyTest`, `LeaveConcurrencyTest`, `PayrollConcurrencyTest` and `DocumentConcurrencyTest` truncate every table.

`RefreshDatabase` migrates once per run; on a slow disk this takes about two minutes, so the full suite is long (tens of minutes on Windows with default MySQL durability). Use `--filter`/folders while developing and the full suite before releasing. Run suites that use committed data (concurrency tests) on their own database when you run several runs in parallel.

### What is covered

| Area | Where | Notes |
|---|---|---|
| Shift maths incl. overnight, geofence distance | `tests/Unit` | pure |
| Check-in/out, geofence, selfie, offline sync, idempotency, schedule snapshot, today state | `Feature/Api/AttendanceCheckInTest` | server clock authority, terminated employee, overnight, snapshot immutability |
| Auth, rate limit, tokens | `Feature/Api/AuthTest` | |
| Permissions and IDOR | `AuthorizationTest`, `NotificationAndPrivacyTest`, `Feature/SecurityHardeningTest` | every API route 401 anonymously; every admin route closed to anonymous/employee; no destructive payroll routes; no secrets in responses; token expiry; production error leakage |
| Corrections, overtime, flagged-attendance review | `CorrectionTest`, `OvertimeTest`, `AttendanceReviewTest` | own-request exclusion, manager scope, reject reason |
| Employee lifecycle and history | `EmployeeLifecycleTest` | transitions, dated assignments, audit old/new, delete restrictions |
| Leave | `Feature/Api/LeaveTest`, `LeaveConcurrencyTest` | balance reserve/use, half days, overlap, policy rules, approval scope, integration with attendance and termination |
| Payroll | `Feature/Payroll/*`, `Unit/Payroll*` | calculator, effective-dated salary, exhaustive state machine, immutability, adjustments, payslip IDOR and PDF, concurrency |
| Documents | `Feature/Documents/*` | malicious uploads, path traversal, IDOR, status derivation, scheduled job idempotency, concurrency |
| Reports | `Feature/Api/ReportsTest` | scope, aggregation, CSV formula neutralization, PDF, ranges |
| Web admin | `Feature/Admin/*` | permissions per role, CRUD, validation, XSS escaping, dashboard numbers |

### The 30 dangerous cases

The Prompt-2 checklist maps to tests like this: other employee's profile / attendance / payslip / document (`AuthorizationTest`, `Payslip*`, `DocumentAccess*`); manager outside scope and self-approval (`CorrectionTest`, `OvertimeTest`, `LeaveTest`); double check-in/out, duplicate offline sync, retry after timeout (`AttendanceCheckInTest` + `ConcurrencyTest`); concurrent/overlapping leave and balance race (`LeaveConcurrencyTest`); concurrent payroll generation and duplicate items, edit/delete finalized payroll, salary change after finalization (`PayrollConcurrencyTest`, `PayrollImmutabilityTest`); work location / shift change after attendance (`test_changing_the_shift_tomorrow_does_not_rewrite_yesterdays_schedule`); termination then check-in; invalid geofence; fake `employee_id`/`company_id` (`NotificationAndPrivacyTest`); unauthorized download, malicious upload, invalid MIME, oversize, path traversal (`Documents/*`).

## Flutter

```bash
cd app
dart format .
flutter analyze
flutter test
```

Tests use fakes for location, camera, connectivity and storage, so they need no device. Coverage: login, every Home state, check-in flow (inside/outside area, GPS denied, selfie, offline, timeout), the offline outbox (PENDING_SYNC → SYNCED, retry with the same idempotency key, SYNC_FAILED kept, duplicate tap), error mapping (401/403/404/409/422/429/5xx/timeout), DTO parsing against the documented JSON, leave rules and flow, notifications, payslips, documents, tablet layouts.

### Live API tests

Optional tests that drive the real Laravel API with the app's repository code:

```bash
# terminal 1: BE with the demo seed
php artisan serve --port=8000
# terminal 2
cd app
PEOPLEOS_API_TESTS=1 flutter test test/api/live_api_test.dart   # PowerShell: $env:PEOPLEOS_API_TESTS='1'
```

They write real rows (check-ins, a leave draft) to the demo database; run them against demo data only.

## Manual end-to-end script (release check)

1. Employee login → Home → Check in (GPS inside area; selfie if the location's policy requires it) → working timer → Check out → history shows the day.
2. Airplane mode → Check in → "saved on this device" → reconnect → pending sync → synced (same record, no duplicate).
3. Request leave → manager approves in the web admin → employee sees approved, balance updated → Home shows "On leave" on that day.
4. Generate payroll (HR) → review → approve (a different user) → finalize → payslip PDF available to the employee, and an attempt to edit is refused.
5. Upload a document for an employee → employee opens it; another employee cannot.
6. Reports → export CSV and PDF.
