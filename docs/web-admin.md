# Web Admin

Custom Blade + Tailwind CSS v4 + Alpine.js admin living in `BE/` next to the REST API. No Filament or admin package.

## Run it

```bash
cd BE
composer install
cp .env.example .env && php artisan key:generate   # first time only
php artisan migrate --seed                          # or your own DB; the demo seeder is optional
php artisan storage:link                            # REQUIRED once: serves employee photos and the company logo from /storage
npm install
npm run build                                       # compiles CSS/JS + bundled Inter font into public/build (not committed)
php artisan serve                                   # http://127.0.0.1:8000  ->  redirects to /admin
```

During front-end work use `npm run dev` (Vite dev server) instead of `npm run build`.

Demo logins (password `password`): `admin@peopleos.test`, `hr@peopleos.test`, `manager@peopleos.test`.
Only accounts that hold the `admin.access` permission can sign in (Admin, HR, Manager). Employee-only accounts are refused.

## Tests

```bash
$env:DB_DATABASE = 'peopleos_test_web'   # any database whose name contains "test"; the admin suite refuses others
php artisan test tests/Feature/Admin     # admin only
php artisan test                         # whole backend
```

## Modules

| Area | Route (name) | Permission | Notes |
|---|---|---|---|
| Dashboard | `/admin` (`admin.dashboard`) | `admin.access` | KPIs, pending-approval cards linking to the queues below, leave, **payroll status** (latest non-cancelled period, state, net total from the run, link; `payroll.view`) and **expiring documents** (count within `documents.expiring_days`, expired count, link to the filtered list; `document.view`). Managers see neither |
| Employees, Departments, Positions, Organization | `/admin/employees` … | `employee.*`, `organization.*` | Phase A |
| Attendance board | `/admin/attendance` (`admin.attendance`) | `attendance.view` or `attendance.view_team` | Daily board (company-local dates), filters, "not checked in" list, CSV export (`admin.attendance.export`) |
| Attendance record | `/admin/attendance/{id}` (`admin.attendance.show`) | record policy | Frozen schedule snapshot, events with coordinates, geofence diagram, selfies |
| Selfie image | `/admin/attendance/events/{id}/selfie` (`admin.attendance.selfie`) | `AttendanceRecordPolicy::viewSelfie` | Streams from the private selfie disk, `no-store` |
| Review flagged attendance | `POST /admin/attendance/{id}/review` | `attendance.review` | `AttendanceReviewService`; reject needs a note; never your own record |
| Correction queue | `/admin/attendance/corrections` | `attendance.correct` or `attendance.approve_team` | `CorrectionService`; own requests hidden and refused |
| Overtime | `/admin/overtime` (`admin.overtime`) | `overtime.view` / `overtime.approve` / `overtime.approve_team` | `OvertimeService`; approved minutes ≤ requested; reject needs a note |
| Shifts | `/admin/shifts` (+ `/assignments`, `/calendars`, `/holidays`) | `attendance.manage` | Working time computed server-side; overrides and calendar assignments are date-ranged without overlap |
| Work locations | `/admin/work-locations` (+ `/policies`) | `attendance.manage` | Lat/lng/radius validation, schematic radius diagram, one default policy |
| Settings → Audit log | `/admin/activity` (`admin.activity`) | `activity.view` | Read-only audit trail, filters, old → new drawer. Lives inside Settings (no top-level Activity item); HR sees only this tab |
| Settings → General, Roles | `/admin/settings` (+ `/roles`) | `settings.manage` | Company name, time zone, address, logo; currency, date format, employee numbers, leave year, payroll and document settings (all through `App\Support\Settings`, audited as `settings.updated` with old/new); attendance defaults card (default policy, "selfie required"); read-only role matrix. The Settings sidebar item shows for `settings.manage` or `activity.view` |
| Leave Requests | `/admin/leave-requests` (`admin.leave-requests`, tabs: To review, All requests, Calendar) | `leave.view` / `leave.view_team` / `leave.approve` / `leave.approve_team` | Queue scoped by `AccessScope`, own requests hidden; filters (status, type, department, dates, employee); detail drawer (days, working dates, balance impact, policy snapshot, history); approve / reject (note required) / cancel via `LeaveService`; month calendar of who is off |
| Employee → Leave tab | `/admin/employees/{id}?tab=leave` | `leave.view` / `leave.view_team` (+ `leave.manage` for actions) | Balances per type per leave year and the requests; HR can adjust a balance (reason required, `LeaveBalanceService::adjust`) and file leave on the employee's behalf (`LeaveService::create` with HR as actor) |
| Leave Types | `/admin/leave-types` (`admin.leave-types`) | `leave.manage` | Types (colour token, paid, active), default policy and per-employment-type policies via `LeaveSetupService`; types with requests can only be deactivated |
| Payroll | `/admin/payroll` (`admin.payroll`), `/admin/payroll/{period}` (`.show`), `/{period}/items/{item}` (`.items.show`) | `payroll.view` (actions: `payroll.manage` generate / submit / cancel, `payroll.approve` approve, return-to-draft, `payroll.finalize` finalize / mark paid) | Periods with status badges and totals from each run snapshot; workflow stepper DRAFT → REVIEW → APPROVED → FINALIZED → PAID (PROCESSING and CANCELLED shown as states); only the buttons the state machine AND the viewer's permissions allow; confirm dialogs spell out consequences of finalize / mark paid / cancel; friendly messages for `SEPARATE_APPROVER_REQUIRED`, `NEGATIVE_NET_PAY` (lists the employees), `PAYROLL_PROCESSING`, `INVALID_STATE_TRANSITION`, `PAYROLL_LOCKED`; skipped-employee warnings; items table (search, sticky first column) with a detail drawer of every earning/deduction line and its inputs; adjustment periods (create from a finalized period, add/remove entries with a reason). FINALIZED/PAID pages carry no edit controls and the only DELETE route is a DRAFT adjustment entry. Money is formatted from decimal strings with the run's currency and decimals. All writes through `PayrollActions` -> `PayrollPeriodService` / `PayrollGenerator` / `PayrollWorkflow` |
| Salary Structures | `/admin/salary-structures` (`.components`, `/{id}`, `/{id}/edit`) | `salary.view` / `salary.manage` | Component catalogue (kind, calculation, defaults, active; used components keep their meaning), structures with component lines, assign a structure to several employees from a date (per employee, conflicts reported individually). Prominent note: no tax or statutory rules are built in. Writes through `SalaryActions` -> `SalaryComponentService`, `SalaryStructureService`, `EmployeeSalaryService` |
| Employee → Payroll tab | `/admin/employees/{id}?tab=payroll` | `salary.view` and/or `payslip.view` (+ `salary.manage` to change) | Current values, append-only dated salary history, "Change salary from date" (`POST /admin/employees/{id}/salary`; no edit or delete route exists), the employee's payslips |
| Payslips | `/admin/payslips` (`.show`, `/{id}/pdf`, `POST /{id}/regenerate`) | `payslip.view` (regenerate: `payroll.finalize`) | Searchable list across periods; the PDF is streamed from the private disk after `PayslipPolicy` (unknown or forbidden ids answer 404, the storage path is never rendered); regenerating a missing PDF is idempotent and audited as `payslip.pdf_regenerated` |
| Documents | `/admin/documents` (`.show`, `/{id}/file[?preview=1]`, `/types`) | `document.view` (writes: `document.manage`) | Filters (type, status, employee, company-only, expiring within N days, search); status badges with icon + text; drag-and-drop upload with the same size/extension checks as the server (the server still decides from the file content); detail with audit trail; download or inline preview (PDF/JPG/PNG only, nosniff + restrictive CSP) through `DocumentPolicy`; edit metadata, archive / restore (reason), soft delete; document types management; Employee → Documents tab. `DocumentService` errors land on the right field |
| Reports | `/admin/reports` (`.show`, `/{key}/export/{csv\|pdf}`) | `report.view` + the report's domain permission | Catalogue grouped Attendance / Leave / Payroll / Documents (only what the viewer may run; forbidden = unknown = 404); filters per report declaration; sticky-header table with totals row; summary tiles and simple SVG bars are derived from the same rows; the screen shows the first 500 rows and says so, CSV / PDF contain everything (cap 20,000, audited `report.exported`) |
| Notifications bell | top bar, `/admin/notifications` | `admin.access` | The signed-in user's own `AppNotification` rows: unread count, latest eight, mark read / mark all read, link to the related page when the entity type matches and the viewer may open it |

History is never rewritten from the admin: editing a shift, location, policy, calendar or holiday only affects days that
are scheduled from now on, because each attendance day freezes its own schedule snapshot (`attendance_schedules`).
Overrides start today or later; a running override can only be ended, and a holiday already used by a schedule keeps its date.

## Responsive behaviour

- **Tables** scroll sideways inside their card, keep the first column (who / what) sticky (`.dt-sticky`, default for `<x-data-table>`) and hide low-priority columns on smaller screens with Tailwind (`hidden md:table-cell`, `lg:`, `xl:`). Nothing is lost: the detail page or drawer has every field.
- **Filters** collapse behind a "Filters" button into a bottom sheet below the `md` breakpoint (`<x-filter-bar>` for search + selects, `<x-filter-panel>` for date/select forms). Empty fields are dropped from the URL.
- **Keyboard**: every interactive element has a visible `:focus-visible` outline; dialogs, drawers and sheets trap focus (`x-trap`) and close on Escape.
- **Motion**: Alpine transitions are CSS transitions, and `prefers-reduced-motion: reduce` turns all of them (and spinners) off.
- Status is never colour-only: badges carry text, calendar cells carry screen-reader text.

## Settings keys

`Settings → General` edits the typed keys declared in `App\Support\Settings::DEFINITIONS`: `general.currency` (validated against ISO 4217 from ICU), `general.date_format` (one of `App\Support\DateDisplay::FORMATS`, used by the new pages), `employee.number_prefix` / `employee.number_digits` (suggest the next number on *Add employee*: one past the highest existing number with that prefix, soft-deleted included), `leave.year_start_month`, `payroll.require_separate_approver`, `payroll.payslip_prefix`, `payroll.rounding_decimals`, `documents.expiring_days`. Only changed keys are written, in one audit row.

## Structure

- Routes: `routes/web.php` (login, logout, `/admin` group), `routes/admin.php` (every admin route with its `permission:` guard).
- Navigation and placeholder pages: `app/Support/AdminNavigation.php` is the single source for the sidebar, permissions per item and the "arrives in a later release" pages. All sidebar modules are built now; the placeholder mechanism (a nav entry with a `placeholder` key gets a designed "arrives in a later release" page) remains for future modules.
- Controllers stay thin (`app/Http/Controllers/Admin`), validation in `app/Http/Requests/Admin`, logic in `app/Services/Admin`.
  Admin services reuse the domain services (`EmployeeService`, `CorrectionService`, `OvertimeService`, `AttendanceReviewService`,
  `ShiftCalculator`, `AccessScope`, `ActivityLogger`); attendance and approval tables are never written from a controller.
- Every change made in the admin writes an `activities` row (`Services/Admin/Concerns/AuditsChanges`).
- Design tokens are CSS variables in `resources/css/app.css` (light + dark). Components live in `resources/views/components`
  (`<x-button>`, `<x-card>`, `<x-badge>`, `<x-data-table>`, `<x-modal>`, `<x-sheet>`, `<x-geo.radius>`, `<x-shift-time>`, `<x-duration>` ...).
- Theme: follows the OS by default; the top-bar toggle stores the choice in `localStorage`.

## Roles

The Settings → Roles page is read-only. System roles are defined in `app/Support/PermissionRegistry.php` and re-applied by
`RolesAndPermissionsSeeder` on every deploy, so changing a role means changing the registry.

## Batch 2 notes (Payroll, Salary, Payslips, Documents, Reports, bell)

- **Writes never come from controllers.** `PayrollActions`, `SalaryActions`, `DocumentActions` wrap the domain services and translate `ApiException` codes: field-level codes (`UNSUPPORTED_FILE_TYPE`, `EXPIRY_DATE_REQUIRED`, `PERIOD_OVERLAP`, `SALARY_CHANGE_CONFLICT`, ...) become errors under the right input (`Concerns/TranslatesDomainErrors`); workflow codes become plain-language flash messages, with a list when useful (`PayrollActionFailed`, `error_items`).
- **Read models**: `PayrollDesk`, `SalaryDesk`, `PayslipDesk`, `DocumentDesk`, `ReportDesk`, `NotificationBell` (all `app/Services/Admin`).
- **Locked payroll**: no UI or route edits FINALIZED/PAID payroll. The only DELETE under `/admin/payroll` is `…/adjustment-entries/{entry}` (DRAFT adjustment periods). `PUT /admin/payroll/{period}` (name, pay date, notes) is refused by the service with `PAYROLL_LOCKED` once finalized.
- **Money** is shown from decimal strings with `Money::format` (run currency/decimals; salary screens use the company payroll decimals). No float is involved.
- **Shared UI added**: `<x-panel>` (right-hand detail panel loaded on demand), `<x-doc-status>` (icon + text), `fileDrop` Alpine component (drag and drop, size/extension checks mirroring `config/documents.php`), flash lists (`error_items` / `flash_items`).
- **Tests** (`tests/Feature/Admin`): `PayrollWebTest`, `SalaryWebTest`, `PayslipWebTest`, `DocumentWebTest`, `ReportWebTest`, `NotificationBellTest` on top of `Batch2TestCase` (payroll + document fixtures, custom single-permission roles).
- **Audit fixes found while doing the visual pass**: `sr-only` text inside horizontally scrolling tables made whole pages scroll sideways on phones (Leave calendar, payroll) — scroll wrappers are now `relative`; stat tiles stack on phones; filter select labels shortened.

## Prompt 3 batch A: dropdowns, attendance trend, data provenance

### Dropdowns (`<x-listbox>`, `resources/js/listbox.js`)
- **Cause of the old look**: `<x-select>` rendered a native `<select>`; its open list is drawn by the browser/OS, not by our CSS.
- **Now**: every select in the admin (`<x-select>`, `<x-mfield as="select">` and the raw selects in Documents, Payslips, Organization/Settings time zone, salary structure rows, document upload) is wrapped by `<x-listbox>`. The native select stays in the DOM as the real form control (name, value, `required`, `x-model`, `change` events), so GET filters, auto-submit, form posts and `old()` keep working. If Alpine does not start, the native select is simply visible (progressive enhancement).
- ARIA combobox + listbox: `aria-expanded`, `aria-controls`, `aria-activedescendant`, labelled by the field label; keyboard ArrowUp/Down, PageUp/Down, Home/End, Enter/Space, Esc (closes only the list, never the dialog around it), Tab, type-ahead; outside click closes; selected/active/disabled states; option groups.
- Search box automatically above 7 options (`data-search="always|never"` overrides); "No matches" state; optional remote options via `data-options-url` with loading / error / retry (no screen uses it yet).
- The popover is `position: fixed` and flips above the trigger near the bottom edge, so table/card/dialog overflow never clips it; below 768 px it is a bottom sheet. Transitions are disabled by `prefers-reduced-motion`. Light and dark tokens.
- Employees gained Manager and Shift filters (options from the DB, scoped). Filters never widen the permission scope.
- Real-browser check: `scratchpad/shots-tool/lb.mjs` drives click, Esc, ArrowDown+Enter (asserts the GET request and the preserved value), type-ahead, Home/End, outside click, edge flip, dialog, search, no results and the mobile sheet (17 assertions, light and dark).

### Attendance trend (`App\Services\Admin\AttendanceMetrics`, `<x-chart.attendance-trend>`)
- One documented definition (class docblock) of Present, On time, Late, Expected, Absent and Not checked in yet, used by the KPI cards, the donut and the chart. Business dates are company-local `work_date`s.
- Stacked bars On time + Late (= Present) + Absent (closed working days only); rest days/holidays are drawn as "no data", never as zero; late = stripes and absent = dots so colour is not the only cue; value labels, gridlines, axis, legend with period totals, keyboard-focusable bars with exact tooltips, an aria summary and a "Show as table" toggle.
- Range 7 / 14 / 30 days via `GET /admin/dashboard/trend?days=` (same scope as the page) with loading and error states; a failed aggregation shows an error panel, an empty scope shows an empty state.
- Data provenance for every screen: [data-provenance-web.md](data-provenance-web.md).
