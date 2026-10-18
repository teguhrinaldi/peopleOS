# Database

MySQL 8+, `utf8mb4_unicode_ci`, InnoDB. Default database name: `peopleos` (tests use `peopleos_test`). All timestamps are UTC.

## Tables (Prompt 1)

| Area | Tables |
|---|---|
| Authorization | `users`, `roles`, `permissions`, `user_roles`, `role_permissions` |
| Organization | `companies`, `branches`, `departments`, `positions`, `work_locations`, `employees`, `employee_assignments` |
| Attendance setup | `attendance_policies`, `shifts`, `shift_breaks`, `shift_assignments`, `working_calendars`, `working_calendar_days`, `working_calendar_assignments`, `holidays` |
| Attendance data | `attendance_schedules`, `attendance`, `attendance_events`, `attendance_corrections`, `overtime_requests` |
| Platform | `activities` (audit), `settings`, `personal_access_tokens` (Sanctum), `sessions`, `cache`, `jobs` |

Added beyond the requested list: `attendance_policies` (referenced by the spec as "attendance policy"). **Later phases** will add leave (`leave_types`, `leave_policies`, `leave_balances`, `leave_requests`), payroll (`salary_structures`, `salary_components`, `employee_salary_components`, `payroll_periods`, `payroll_runs`, `payroll_items`, `payroll_item_lines`, `payslips`), `documents`, `notifications`.

## Relationships

```
companies ─┬─ branches ─┬─ departments (self-parent)
           │            └─ work_locations ── attendance_policies
           ├─ positions
           └─ employees ── employee_assignments ──► department, position, branch,
                 │                                   manager(employee), work_location, shift
                 ├─ user (nullable, SET NULL)
                 ├─ attendance_schedules ── attendance ── attendance_events
                 ├─ attendance_corrections, overtime_requests
                 └─ shift_assignments, working_calendar_assignments
```

## Unique constraints

`users.email` · `employees(company_id, employee_number)` · `employees(company_id, email)` · `employees.user_id` · `branches/departments/positions/shifts/work_locations(company_id, code)` · `roles.slug` · `permissions.slug` · `role_permissions(role_id, permission_id)` · `user_roles(user_id, role_id)` · `working_calendar_days(working_calendar_id, day_of_week)` · `holidays(company_id, date)` · `settings(company_id, key)` · `attendance_schedules(employee_id, work_date)` · **`attendance(employee_id, attendance_schedule_id)`** · **`attendance_events.idempotency_key`**.

Reserved for later phases: `leave_balances(employee_id, leave_type_id, year)`, `payroll_runs(payroll_period_id)`, `payroll_items(payroll_run_id, employee_id)`, `payslips(payroll_item_id)`.

## Foreign-key strategy

* **Master → historical: `RESTRICT`.** A department, position, shift, location or employee that history refers to cannot be physically deleted (asserted by a test). Retire it with `is_active = false` / soft delete.
* `user → employee`: `SET NULL` (`employees.user_id`); `employee_assignments.manager_id`: `SET NULL`.
* `attendance → attendance_events`: `CASCADE` (events belong exclusively to their attendance).
* Audit/approval actors (`reviewed_by`, `created_by`, `activities.actor_id`): `SET NULL` so history survives user removal.
* Employees are **soft-deleted**, and only never-used `PENDING` records may be deleted (`EmployeeService::delete`). Terminated employees stay forever.
* Finalized payroll (later phase) must not be deletable through normal flows.

## Indexes (purposeful, not blanket)

* employees: `(company_id, status)`, `full_name`, unique keys above
* employee_assignments: `(employee_id, effective_from)`, `(department_id, effective_to)`, `(position_id, effective_to)`, `(manager_id, effective_to)`
* attendance: `(employee_id, work_date)`, `(work_date, status)`, `status`
* attendance_events: `(attendance_id, type)`, `(employee_id, occurred_at)`, unique `idempotency_key`
* corrections / overtime: `(employee_id, status)`, `(status, work_date)`
* activities: `(actor_id, created_at)`, `(entity_type, entity_id)`, `created_at`
* shift_assignments / working_calendar_assignments: `(employee_id, …dates)`

## Notes

* The employee unique keys include soft-deleted rows on purpose: an employee number (and work email) is never reused, so historical documents and exports stay unambiguous. Pick a new number instead of recycling one.

* `attendance.status`: `PENDING | CHECKED_IN | CHECKED_OUT | CORRECTION_REQUESTED | CORRECTED | REJECTED`. `employees.status`: `PENDING | ACTIVE | INACTIVE | TERMINATED`. Requests: `PENDING | APPROVED | REJECTED | CANCELLED`.
* `working_calendar_days.day_of_week` is ISO-8601 (1 = Monday … 7 = Sunday).
* Overnight shifts store `crosses_midnight` (derived on save) and the schedule snapshot stores absolute UTC `expected_start_at/expected_end_at`.
