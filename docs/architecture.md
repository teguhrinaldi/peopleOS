# Architecture

## Shape of the product

```
Flutter app ──(Demo)──► local/mock repositories
     │
     └──(API)──► Laravel REST /api/v1 ──► MySQL 8
                        ▲
Web Admin (Blade + Tailwind + Alpine) ──┘   same services, session auth
```

One installation = one company (no multi-tenancy). The buyer runs their own MySQL, Laravel and (optionally) builds the Flutter app against their own URL. Nothing calls a proprietary service; there are no embedded secrets.

## Backend (Laravel 13, PHP 8.3)

```
Route → Controller → FormRequest → Policy / permission → Service → Model → MySQL
```

* **Controllers are thin.** Business rules live in `app/Services`:
  `Attendance/{AttendanceService, ScheduleService, ShiftCalculator, GeofenceService, CorrectionService, OvertimeService, AttendanceReviewService, TodayService}`, `EmployeeService`, `ActivityLogger`, `AccessScope`.
* **Pure logic is isolated** (`ShiftCalculator`, `GeofenceService` have no DB access) and unit-tested.
* **Authorization is permission-based.** `app/Support/PermissionRegistry.php` is the single source of truth for permission slugs and the default role matrix. Code checks permissions (`$user->hasPermission('employee.edit')`, `@can('employee.edit')`), never role names.
* **Scope resolution** – `AccessScope` answers "which employees may this user see?" from permissions: an *all* permission → everyone; a *team* permission → direct reports (by their **current** assignment); plus optionally self. Every list and policy funnels through it, so ids in URLs/bodies can never widen access (IDOR).
* **Errors** – `ApiException(code, message, status, details)` for domain rules; the exception handler renders one JSON envelope with a stable machine-readable `error.code` for the API.
* **Clock** – `CompanyContext` (singleton) owns the company, its timezone and "now". Everything is stored in UTC and converted at the edge.
* **Auditing** – `ActivityLogger` writes an append-only `activities` row (actor, action, entity, old/new values, IP, user agent) and redacts secrets.
* **No Filament.** The web admin is custom Blade + Tailwind + Alpine so the PeopleOS design lock can be honoured exactly.

### Key modelling decisions

| Decision | Why |
|---|---|
| `employee_assignments` with `effective_from/to` instead of columns on `employees` | Department/position/manager/location/shift changes keep history; "current" is a query, never an overwrite |
| `attendance_schedules` snapshot per employee/day | Yesterday's attendance never changes when today's assignment does |
| Statuses stored as strings, cast to backed enums | Readable data, portable, checked in PHP |
| Restrictive foreign keys on master → history | Historical data is sacred; soft-deletes for people |
| `attendance_events` are cascade-owned by their attendance | They belong to it exclusively |
| Selfies on a private disk, DB stores path + metadata | Never binary in MySQL, never publicly addressable |

## Flutter app

```
UI (widgets) → feature controller/state → repository (interface) → data source
                                             ├─ Demo: in-memory / local persisted mock
                                             └─ API : Dio → Laravel /api/v1
```

Mode is a compile-time flag (`--dart-define=PEOPLEOS_USE_API`) and the UI never knows which repository it talks to. Offline attendance goes through a local outbox with idempotency keys (see [attendance.md](attendance.md)). See `app/README.md` for the folder layout and state-management choice.

## Web Admin

Grouped sidebar is the locked primary navigation: Dashboard · PEOPLE (Employees, Departments, Positions, Organization) · ATTENDANCE (Attendance, Shifts, Work Locations, Overtime) · LEAVE (Leave Requests, Leave Types) · PAYROLL (Payroll, Salary Structures, Payslips) · Documents · Reports · Activity · Settings. Items appear only for users holding the matching permission. Leave and Payroll pages are designed placeholders until their phases.

## Testing strategy

* Unit tests: shift maths (incl. overnight), geofence maths.
* Feature tests against **real MySQL** (`peopleos_test`) because behaviour depends on unique constraints, row locks and FK rules.
* `ConcurrencyTest` spawns real parallel PHP processes.
* Flutter: unit/widget tests with fakes for location, camera, connectivity and storage.
