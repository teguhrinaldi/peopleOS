# Troubleshooting

## Install and environment

| Symptom | Cause / fix |
|---|---|
| `composer install` says the PHP version is too low | Laravel 13 needs PHP ≥ 8.3. Check `php -v` is the one on `PATH`; on Windows several PHP versions can coexist |
| `SQLSTATE[HY000] [2002] connection refused` | MySQL is not running or `DB_HOST/DB_PORT` is wrong. Start MySQL; `php artisan db:show` verifies the connection |
| `Identifier name ... is too long` in a migration | MySQL limits identifiers to 64 characters: give the index an explicit short name |
| Migrations take minutes on Windows | InnoDB fsync cost. For a *development* MySQL only, `innodb_flush_log_at_trx_commit=0` helps (see `scripts/dev-mysql.ps1`); never in production |
| `No application encryption key` | `php artisan key:generate` |
| Web admin has no styles / blank | `npm install && npm run build`; make sure `public/build` exists on the server |
| Employee photos / logo 404 | `php artisan storage:link` (only for public files) |
| `419 Page Expired` on the admin | Session expired or cookies blocked; check `APP_URL` matches the URL in the browser and HTTPS settings |

## Login and permissions

| Symptom | Cause / fix |
|---|---|
| Admin signs in but sees few menu items | Permissions changed in code: run `php artisan db:seed --class=RolesAndPermissionsSeeder` |
| `403` on every admin page | The user lacks `admin.access` (employees never have it) |
| Mobile: `ACCOUNT_DISABLED` / `EMPLOYEE_TERMINATED` | The account or employee was deactivated/terminated: expected. Reactivate in Employees |
| Mobile: `NO_EMPLOYEE_PROFILE` | The login is not linked to an employee record. Create/link it in Employees |
| `TOO_MANY_ATTEMPTS` | 5 failed logins per email+IP per minute; wait, or clear the cache |
| Manager cannot see someone | Visibility follows the **current** assignment's manager. Check the employee's manager and effective dates |

## Attendance

| Code | Meaning / what to do |
|---|---|
| `OUTSIDE_GEOFENCE` | Server-computed distance exceeds the location radius. Check the location's coordinates/radius; switch the policy to *review* mode if you want to accept and flag |
| `GPS_ACCURACY_TOO_LOW` | The phone's fix is worse than the policy limit; raise `max_gps_accuracy_meters` or ask the employee to step outside |
| `MOCK_LOCATION_DETECTED` | The OS reports a mocked location; disable developer mock-location apps |
| `NO_SCHEDULE` / `NOT_A_WORKING_DAY` | No shift for that day, or a rest day/holiday without approved overtime |
| `ON_LEAVE` | Full-day approved leave covers the date |
| `OFFLINE_EVENT_TOO_OLD` | A queued check-in older than the policy's offline window; use an attendance correction |
| `LOCATION_STALE` | The GPS fix was older than 5 minutes; refresh location |
| Late minutes look wrong | Lateness uses the frozen schedule snapshot for that date (shift, grace). Editing a shift never changes past days, by design |
| Absent report is empty | Schedule snapshots missing: ensure the scheduler runs (`attendance:build-schedules`) or run it manually with `--date` |
| Selfie missing in admin | Private disk path/permissions: check `storage/app/private` is writable |

Idempotency: a retried request with the same `idempotency_key` returns `200 replayed:true`. A key reused for a different action returns `409 IDEMPOTENCY_KEY_CONFLICT`.

## Leave

| Code | Fix |
|---|---|
| `LEAVE_INSUFFICIENT_BALANCE` | Balance counts pending requests as reserved. HR can adjust the balance (reason required) or allow negative in the policy |
| `LEAVE_OVERLAP` | Another pending/approved request covers a slot; reject/cancel it or pick other dates |
| `LEAVE_NOTICE_TOO_SHORT`, `LEAVE_TOO_FAR_IN_PAST` | Policy rules (`min_notice_days`, `max_past_days`) |
| `LEAVE_POLICY_MISSING` | The leave type has no default policy: add one in Leave Types |
| `LEAVE_SPANS_YEARS` | Split the request at the start of the new leave year |

## Payroll

| Code | Fix |
|---|---|
| `INVALID_STATE_TRANSITION` | The action is not allowed in the run's current state (or someone else already did it): refresh the page |
| `PAYROLL_PROCESSING` | Another generation is running; a run stuck over 15 minutes can be taken over |
| `SEPARATE_APPROVER_REQUIRED` | The approver must differ from whoever generated/submitted. Setting `payroll.require_separate_approver` |
| `NEGATIVE_NET_PAY` | Deductions exceed earnings for listed employees: fix components, regenerate |
| `PAYROLL_LOCKED` | Finalized/paid payroll is immutable. Create an adjustment period instead |
| Employee missing from payroll | No salary components effective at the period end, or not employed in the period (see run summary → skipped) |
| Payslip PDF missing | `php artisan payroll:payslips --verify` regenerates missing files from the snapshot |

## Documents

| Symptom | Fix |
|---|---|
| Upload rejected `422` | Extension allow-list, content/extension mismatch, size, or a name with a dangerous extension. Check `config/documents.php` |
| Large files fail before reaching Laravel | Raise `upload_max_filesize`, `post_max_size` (PHP) and `client_max_body_size` (Nginx) |
| Employee cannot see a document | It is not flagged *visible to employee*, belongs to someone else (shows as 404), or is archived |
| Expiry notifications not arriving | Scheduler not running (`documents:refresh-status`) |

## Flutter

| Symptom | Fix |
|---|---|
| App shows demo data | Built without `--dart-define=PEOPLEOS_USE_API=true` |
| Android emulator cannot reach the API | Use `http://10.0.2.2:8000`, not `localhost` |
| Real phone cannot reach the API | Use the computer's LAN IP, same Wi-Fi, firewall open; or an HTTPS domain |
| Cleartext HTTP blocked | Allowed only in debug builds; use HTTPS in release |
| Web build: CORS error | Publish `config/cors.php` and allow the web origin |
| Location permission loop | Re-enable in system settings; the app only requests location at check-in/out |
| Pending sync never clears | Open *Sync status*; business rejections (e.g. outside area) stay as `SYNC_FAILED` until discarded or retried |
| `flutter build windows` fails | Needs Visual Studio with the C++ workload |

## Getting more information

Application log: `storage/logs/laravel.log`. API errors always carry a stable `error.code`. The audit trail (Settings → Audit log) shows who did what and when.
