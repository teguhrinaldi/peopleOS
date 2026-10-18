# Customization guide

PeopleOS is meant to be modified. This page lists the extension points and where the rules live, so you change the right file.

## Configure first (no code)

Most business behaviour is data, not code. Use the web admin before touching source:

| Want to change | Where |
|---|---|
| Company name, timezone, currency, date format, employee number format, leave year, payslip prefix, rounding, document expiry window | Settings → General |
| Shifts, breaks, grace periods (overnight supported), working calendars, holidays | Attendance → Shifts |
| Work locations, geofence radius, GPS accuracy limit, selfie requirement, offline window | Attendance → Work Locations (+ attendance policies) |
| Leave types, allowances, carry-over, notice, half days, negative balance | Leave → Leave Types |
| Salary components (earnings/deductions, fixed, % of basic, per present day, per overtime hour, unpaid leave) and structures | Payroll → Salary Structures |
| Document types and who may see them | Documents → types |
| Roles | The four system roles are defined in code (below) so deploys cannot silently diverge |

## Branding

* **Web admin:** colours, radii and shadows are CSS variables in `BE/resources/css/app.css` (light and dark blocks). Replace the logo in `BE/resources/views/components/logo.blade.php`; upload a company logo in Settings. Run `npm run build`.
* **Flutter app:** all colours/typography/spacing/radii/elevation/motion are in `app/lib/core/theme` (`PeopleColors`, `PeopleTypography`, `PeopleSpacing`, `PeopleRadii`, `PeopleElevation`, `PeopleMotion`, `PeopleTheme`). Change tokens there, not in screens. App name, id and icons: `app/android/app/src/main`, `app/ios/Runner`, `app/web`.
* **Fonts:** Inter is bundled locally (OFL, see [assets.md](assets.md)); swap the font asset and the family name in the typography file.
* **Payslip / report PDFs:** `BE/resources/views/payroll/payslip.blade.php`, `BE/resources/views/reports/pdf.blade.php`.

## Permissions and roles

`BE/app/Support/PermissionRegistry.php` is the single source of truth for permission slugs and the default role matrix (Admin, HR, Manager, Employee).

1. Add the slug (and its label) to `all()`.
2. Add it to the roles that should hold it in `roles()`.
3. Run `php artisan db:seed --class=RolesAndPermissionsSeeder` (idempotent; removes slugs you delete from the registry).
4. Check it with `$user->hasPermission('x.y')`, `@can('x.y')`, route middleware `permission:x.y`, or a policy. **Never test role names.**

For "only my direct reports" style access add an `*_team` permission and resolve people with `AccessScope`.

## Adding a module (pattern used by Leave, Payroll, Documents)

* Migration (`database/migrations`), model, enum for states.
* A `Services/<Module>` folder: DB-free calculators, a service that owns every rule and transaction (`DB::transaction` + `lockForUpdate` where races matter), `ActivityLogger` for audit, `NotificationService` for in-app notifications.
* Policy `app/Policies/<Model>Policy.php` (auto-discovered).
* API: controller + FormRequest + resource, routes in `routes/api/v1/<module>.php` (auto-loaded inside the authenticated group); scheduled jobs in `routes/schedule/<module>.php`.
* Web admin: controller in `Http/Controllers/Admin`, route in `routes/admin.php`, nav entry in `Support/AdminNavigation.php` **inside the existing group** (the top-level sidebar is deliberately fixed).
* Demo data: `database/seeders/<Module>DemoSeeder.php`.
* Tests: unit tests for calculators, feature tests for permissions + IDOR + state machine, and a real multi-process concurrency test if races matter (copy `tests/Feature/LeaveConcurrencyTest.php`).

## Business rules you are likely to change

| Rule | File |
|---|---|
| Lateness / early leave / worked minutes | `app/Services/Attendance/ShiftCalculator.php` |
| Geofence, GPS accuracy, mock location, selfie policy | `app/Services/Attendance/AttendanceService.php::verify` |
| Which shift/location/calendar applies on a date | `app/Services/Attendance/ScheduleService.php` |
| Leave day counting, notice, overlap, balance | `app/Services/Leave/LeaveCalendar.php`, `LeaveService.php` |
| Payroll calculation (earnings, deductions, rounding, unpaid leave) | `app/Services/Payroll/PayrollCalculator.php` (pure, unit-tested) |
| Payroll state machine | `app/Services/Payroll/PayrollStateMachine.php` |
| Payslip layout | `resources/views/payroll/payslip.blade.php` |
| Upload allow-list and size | `config/documents.php` |

**Payroll and tax:** PeopleOS contains no tax, social-security or statutory rules. Model your jurisdiction with salary components (e.g. a deduction as a percentage of basic or gross) and, if needed, a custom calculation type in `PayrollCalculator`. You are responsible for compliance.

## Notifications: add push or email

In-app notifications are always stored. To add a channel, implement `App\Services\Notifications\NotificationChannel` (`send(AppNotification $n)`) and list the class in `config/peopleos.php` → `notifications.channels`. No feature code changes. Failures in a channel never break the business action.

## Reports

A report is one class extending `App\Services\Reports\Report` (key, columns, filters, rows, permissions) registered in `ReportService::DEFINITIONS`. It automatically appears in the API, web admin, CSV and PDF export.

## Flutter

* Architecture: UI → controller (Riverpod) → repository interface → API or Demo implementation, selected once in `app/lib/app/di.dart`. To add an endpoint, add it to the repository interface, implement it in both `Api*Repository` and the demo backend, then use it from a controller.
* Server URL, mode and flags are compile-time `--dart-define`s ([development.md](development.md)).
* Localization is English-only in V1; strings live next to their widgets.

## What not to change lightly

* Migration history of a deployed database: add new migrations instead.
* The grouped admin sidebar structure, the permission-based checks, the immutability of finalized payroll, the idempotency key on attendance, and server-side geofence/time validation. These are the product's integrity guarantees, each covered by tests.
