# Web Admin data provenance

Audit of every number, chart, list, filter, report, export and badge in the Web Admin: where it comes from, whether it is
computed live from MySQL, how that was verified, and what was wrong (and fixed) during the audit (Prompt 3, batch A,
2026-10-11). The Web Admin is server-rendered Blade: it does not call the REST API, it calls the same domain services the
API uses, so the "API endpoint" column names the API twin of the data where one exists.

Rules checked for every row: no hardcoded business data, one definition per metric, permission scope applied in SQL
(`AccessScope`) before any filter, no N+1 queries, no success message before the database confirms the write.

Legend: **Dynamic** = computed from database rows on every request (no cache, no fixtures).

## Dashboard (`/admin`, `DashboardController@__invoke` → `App\Services\Admin\DashboardService::forUser`)

| Screen / component | Displayed value | Source (file::method) | API twin | Tables / query | Dynamic | Verified how | Issues + fix |
|---|---|---|---|---|---|---|---|
| KPI "Employees" | headcount excl. terminated, active, pending | `DashboardService::forUser` (`$byStatus`) | `GET /api/v1/employees` (paginated, not a count) | `employees` scoped by `AccessScope::employees(employee.view, employee.view_team)`, `GROUP BY status` | yes | `DashboardTest::test_kpis_match_the_known_fixture`, `test_managers_see_only_their_team` | none |
| KPI "Present today", "Late today", "Not checked in yet" | present / late / expected − present among expected | `AttendanceMetrics::series` (today entry) via `DashboardService::trend` | `GET /api/v1/attendance/today` (own record only) | `attendances` (check_in_at not null, status ≠ REJECTED, `work_date` = company today), `employee_assignments` (shift on date), `employees` (status, join/termination), `leave_request_days`+`leave_requests` (approved full-day), `holidays`, `working_calendars` | yes | `AttendanceMetricsTest::test_kpi_cards_and_chart_use_the_same_numbers_and_html_matches`, `DashboardTest::test_kpis_match_the_known_fixture` | **Fixed**: KPI cards and the trend used two different code paths (`expectedToday` + `presentByDate`); both now read `AttendanceMetrics`, one documented definition. |
| KPI "On leave today" | people with approved leave today (full + half day) | `DashboardService::leaveToday` | `GET /api/v1/leave-requests` | `leave_request_days` ⨝ `leave_requests` (APPROVED) for today, scoped | yes | `DashboardLeaveTest` | none |
| Donut "Attendance overview" | on time, late, not checked in, on full-day leave; centre = sum of segments | `DashboardService::forUser` (`donut`) | — | as above | yes | `DashboardTest::test_donut_and_weekly_trend_come_from_real_rows` | **Fixed**: the "On leave" slice counted half-day leave too, so a person on half-day leave who was also present was counted twice and the centre total (present + not yet + on leave) could exceed the headcount. The slice is now "On full-day leave" (expected people only) and the centre is the sum of the slices. |
| Chart "Attendance trend" (7/14/30 days) | per day on time, late, absent, expected; totals | `DashboardService::trend` → `AttendanceMetrics::series/totals`; partial `GET /admin/dashboard/trend?days=` | — | one pass over the tables above for the whole range (5 queries, no per-day queries) | yes | `AttendanceMetricsTest` (definitions, join/termination, empty/reversed/future range, API check-in at 06:40 WIB = 23:40 UTC lands on the WIB day, chart HTML = aggregation, manager scope, empty scope, failing query), real browser: `p3-trend-*` screenshots before/after inserting a late attendance in the dev DB | **Fixed** (rebuild): no reporting period, rest days drawn like zero days, no absent series, no tooltip/table/aria text, no empty or error state, candle-like bars. Absent is computed only for closed working days; rest days/holidays are "no data"; a failed aggregation shows an error state, never zeros. |
| "Pending approvals" cards | pending leave / corrections / overtime / flagged attendance the viewer may act on, own requests excluded | `DashboardService::approvals` | — (the API exposes the queues themselves, e.g. `GET /api/v1/leave-requests`) | `leave_requests`, `attendance_corrections`, `overtime_requests` (status PENDING), `attendances.needs_review`, scope minus own employee | yes | `DashboardTest::test_pending_approvals_are_scoped_and_only_shown_for_queues_the_user_may_act_on` | none |
| "Who is off" list | approved + pending leave now and next 14 days (6 rows) | `DashboardService::upcomingLeave` | `GET /api/v1/leave-requests` | `leave_requests` with eager `employee`, `leaveType` | yes | `DashboardLeaveTest` | none |
| Tile "Payroll status" | latest non-cancelled period, state, net from run snapshot | `DashboardService::payrollStatus` | `GET /api/v1/payroll-periods` | `payroll_periods` ⨝ `payroll_runs.total_net` (DECIMAL string, `Money::format`) | yes | `NotificationBellTest::test_dashboard_payroll_tile_reports_the_latest_period` | none |
| Tile "Expiring documents" | expiring within `documents.expiring_days`, expired count | `DocumentDesk::expiryCounts` → `DocumentQuery::whereStatus` | `GET /api/v1/documents?status=EXPIRING` | `documents` (expiry vs company today, not archived, not deleted) | yes | `DocumentWebTest::test_dashboard_expiring_documents_tile_matches_the_data` | none |
| "Recent activity" | last 8 audit rows | `DashboardService::recentActivity` → `ActivityPresenter` | — | `activities` + eager `actor`, subject names batch-loaded per entity type | yes | `DashboardTest::test_recent_activity_lists_real_audit_rows_with_readable_sentences` | none |
| Top bar bell | unread count + latest 8 for the signed-in user | `NotificationBell::for` | `GET /api/v1/notifications` | `notifications WHERE user_id = me` (2 queries) | yes | `NotificationBellTest` (scoping, mark read only own, 404 for others) | none |

## Lists, filters and detail pages

| Screen / component | Displayed value | Source | API twin | Tables / query | Dynamic | Verified how | Issues + fix |
|---|---|---|---|---|---|---|---|
| Employees list + filters (department, position, location, **manager**, **shift**, status, employment type, search, sort) | employees in scope with current placement | `EmployeeDirectory::query/paginate/filterOptions` | `GET /api/v1/employees` | `employees` scoped, `whereExists` on the current `employee_assignments` row per filter; placement batch-loaded | yes | `EmployeeListTest`, `DropdownFiltersTest::test_employee_filters_really_change_the_query`, `test_filters_stay_inside_the_permission_scope` | **Added** manager and shift filters (options from the DB: people who manage someone in the viewer's scope; company shifts). |
| All filter dropdowns (≈60 selects in 16 screens) | options | Blade `<x-select>` / `<x-listbox>`; options passed by controllers from the DB (departments, positions, locations, shifts, managers, leave types, document types, payroll periods, employees) or from PHP enums (statuses, kinds) | — | per page | yes | `DropdownFiltersTest::test_every_select_on_admin_pages_is_enhanced`, real browser `lb.mjs` | **Fixed**: native `<select>` popups (OS-drawn) replaced by the PeopleOS listbox; the native select stays the submitted control. Static presets that are UI choices, not business data: report status lists (enums), document "expiring within 7/30/60/90 days", dashboard trend range 7/14/30. |
| Departments / Positions / Organization | names, counts, hierarchy | `OrganizationService` | `GET /api/v1/organization/*` | `departments`, `positions`, `branches`, counts by `employee_assignments` | yes | `OrganizationTest` | none |
| Attendance board, record, corrections, overtime | daily rows, events, selfies | `AttendanceBoard`, `ApprovalQueue` | `/api/v1/attendance/*` | `attendances`, `attendance_events`, `attendance_schedules`, `attendance_corrections`, `overtime_requests` | yes | `AttendanceBoardTest`, `ApprovalQueuesTest` | none |
| Leave requests / calendar / types / employee leave tab | requests, balances | `LeaveDesk`, `LeaveActions` | `/api/v1/leave-requests`, `/api/v1/leave-balances` | `leave_requests`, `leave_request_days`, `leave_balances`, `leave_types`, `leave_policies` | yes | `LeaveRequestsTest`, `EmployeeLeaveTabTest`, `LeaveTypesTest` | none |
| Payroll periods list / detail / item drawer | totals, items, lines | `PayrollDesk::periods/items`, `PayrollController@item` | `/api/v1/payroll-periods`, `/api/v1/payroll-runs/{id}/items` | `payroll_periods` + eager `run`; `payroll_items` paginated; `payroll_item_lines` for one item | yes (snapshot rows) | `PayrollWebTest`, `QueryBudgetTest` | none |
| Salary components / structures / employee salary tab | catalogue, lines, effective-dated history | `SalaryDesk` | `/api/v1/salary-*`, `/employees/{id}/salary` | `salary_components`, `salary_structures`, `salary_structure_components`, `employee_salary_components` | yes | `SalaryWebTest`, `QueryBudgetTest` | none |
| Payslips list / detail / PDF | number, net, PDF stream | `PayslipDesk`, `PayslipDocuments::stream` | `/api/v1/payslips` | `payslips` ⨝ released `payroll_runs`, eager `period/employee/item.run`; file from private disk | yes | `PayslipWebTest`, `QueryBudgetTest` | none |
| Documents list / detail / types / employee tab | status (live from expiry), metadata, audit trail | `DocumentDesk`, `DocumentQuery`, `DocumentService::statusFor` | `/api/v1/documents` | `documents` + eager `type`, `employee`; `activities` for the trail | yes | `DocumentWebTest`, `QueryBudgetTest` | none |
| Settings → Audit log | audit rows, old → new | `ActivityLog`, `ActivityPresenter` | — | `activities` | yes | `ActivityAndSettingsTest` | none |

## Reports and exports (`/admin/reports`)

| Screen / component | Displayed value | Source | API twin | Tables / query | Dynamic | Verified how | Issues + fix |
|---|---|---|---|---|---|---|---|
| Report catalogue | reports the viewer may run | `ReportDesk::catalogue` → `ReportService::available` | `GET /api/v1/reports` | report definitions + permissions | yes | `ReportWebTest::test_catalogue_shows_only_reports_the_viewer_may_run` | none |
| Report table, totals row, summary tiles, bars | rows of the report | `ReportDesk::run/tiles/bars` → `Report::rows/totals` | `GET /api/v1/reports/{key}` | per definition (attendances, leave_requests, payroll_items, documents …) scoped by `ReportContext::employeeIds` | yes; tiles and bars are derived from the same rows | `ReportWebTest` (filters, scope, totals, tiles) | none |
| CSV / PDF export | same rows as the screen (cap 20,000), audited | `ReportService::csv/pdf` | `GET /api/v1/reports/{key}/export/{format}` | as above; `activities` row `report.exported` | yes | `ReportWebTest::test_csv_and_pdf_exports_use_the_same_filters_and_are_audited`, `test_manager_reports_are_scoped_to_the_team` | none |

## Writes: no success before persistence

Every admin form posts to a controller that calls a domain service inside a transaction and only then redirects with a
success flash; failures come back as field errors or an error flash (`TranslatesDomainErrors`, `PayrollActionFailed`).
Nothing in the admin uses optimistic UI. Checked by the feature tests that assert the database after each successful
post (`assertDatabaseHas`/model `fresh()` in `PayrollWebTest`, `SalaryWebTest`, `DocumentWebTest`, `LeaveRequestsTest`,
`EmployeeCrudTest` …) and that failed posts leave the data unchanged.

## Performance

`QueryBudgetTest` loads the documents, payroll, payslips, payroll detail, employees, salary components, notifications and
dashboard pages with one row and with nine rows; the query count may not grow by more than 3. `DashboardTest` keeps its
own flat-query check. The trend aggregation uses five queries for any range (7, 14 or 30 days).

## Verification run (2026-10-11)

- Admin suite on `peopleos_test_web`: 257 tests, 257 passed (includes `AttendanceMetricsTest` 8, `DropdownFiltersTest` 5, `QueryBudgetTest` 1).
- Real browser (headless Chrome against the dev DB): `lb.mjs` 17/17 dropdown assertions (light and dark); trend chart with data, 30-day range, tooltip, table, manager scope, and a late attendance inserted into the dev DB for Fri 9 Oct (late 9 → 10, absent 5 → 4 in the totals) and removed again afterwards. The empty and error states were rendered from the real component (`p3-trend-empty-state.png`, `p3-trend-error-state.png`) because the dev data always has attendance; both states are also asserted in `AttendanceMetricsTest`.
- The API check-in path was verified in `AttendanceMetricsTest::test_company_timezone_decides_the_business_day_of_a_real_api_check_in` (the dev "today" is a Sunday, where the API correctly refuses check-in).
