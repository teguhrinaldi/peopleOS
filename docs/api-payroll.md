# Payroll API (v1)

Same conventions as [api.md](api.md): base `/api/v1`, Sanctum bearer token, envelope `{ "success", "data", "meta?" }` / `{ "success": false, "error": { "code", "message", "details" } }`. Business rules: [payroll.md](payroll.md).

* **Money** is always a decimal **string** (`"8000000.00"`). Format with the run's `currency` and `decimals`.
* **Dates** (`start_date`, `effective_from` …) are `YYYY-MM-DD` company-local business dates; instants are ISO‑8601 with the company offset.
* Payslip storage paths are never returned.
* There are **no DELETE routes** for periods, runs, items, lines or payslips (405). The only deletes are salary components/structures that were never used, and adjustment entries of a DRAFT adjustment period.

## Error codes

| HTTP | `error.code` | Meaning |
|---|---|---|
| 403 | `FORBIDDEN` | missing permission |
| 403 | `SEPARATE_APPROVER_REQUIRED` | approver generated/submitted this run (Settings `payroll.require_separate_approver`) |
| 404 | `NOT_FOUND` | unknown id, or someone else's payslip / not released |
| 409 | `INVALID_STATE_TRANSITION` (`details.action`, `status`, `allowed_from`) | action not allowed in the run's state |
| 409 | `PAYROLL_PROCESSING` | another generation is running |
| 409 | `PAYROLL_LOCKED` | finalized/paid payroll cannot be changed |
| 409 | `PERIOD_OVERLAP` `PERIOD_NOT_FINALIZED` `COMPONENT_IN_USE` `BASIC_COMPONENT_EXISTS` `SALARY_CHANGE_CONFLICT` | conflicts |
| 422 | `VALIDATION_ERROR` (`details.<field>[]`) | invalid input |
| 422 | `NEGATIVE_NET_PAY` (`details.employees[]`) | approval blocked, see employees with negative net |
| 422 | `PAYROLL_EMPTY` `REASON_REQUIRED` `INVALID_PERIOD` `NOT_AN_ADJUSTMENT` `INVALID_ADJUSTMENT_TARGET` `EMPLOYEE_NOT_IN_ORIGINAL_PAYROLL` `NO_CHANGES` `STRUCTURE_EMPTY` `COMPONENT_INACTIVE` | rules |

## Employee payslips

### `GET /payslips`
Auth: `payslip.view_own` (own, released payroll only) or `payslip.view`. Query: `scope=mine|all` (`all` needs `payslip.view`), `year`, `payroll_period_id`, `employee_id` (only with `scope=all`), `page`, `per_page`. Paginated, newest first.
```json
{ "id": 12, "payslip_number": "PS-202607-0003",
  "period": { "id": 1, "code": "2026-07", "name": "July 2026", "type": "REGULAR", "start_date": "2026-07-01", "end_date": "2026-07-31", "pay_date": "2026-07-31" },
  "status": "PAID", "currency": "IDR", "decimals": 0,
  "gross_pay": "9500000.00", "total_deductions": "100000.00", "net_pay": "9400000.00",
  "issued_at": "2026-07-31T15:00:00+07:00", "has_pdf": true, "pdf_url": "https://host/api/v1/payslips/12/pdf" }
```

### `GET /payslips/{id}`
Same object plus `company { name, legal_name, address }` and `item`:
```json
"item": { "id": 3, "employee": { "employee_number": "EMP-0012", "full_name": "Michael Carter", "department": "Marketing", "position": "Marketing Staff", "branch": "Bandung Head Office", "employment_type": "permanent", "join_date": "2022-01-15", "terminated_at": null },
  "inputs": { "salary_reference_date": "2026-07-31", "working_days": 23, "present_days": 21, "overtime_minutes": 0, "unpaid_leave_days": "0.0" },
  "basic_salary": "9000000.00", "gross_pay": "10785000.00", "total_deductions": "100000.00", "net_pay": "10685000.00", "has_negative_net": false, "warnings": [],
  "earnings": [ { "code": "BASIC", "name": "Basic Salary", "kind": "EARNING", "calculation": "FIXED", "rate": null, "quantity": null, "unit_amount": null, "base_amount": null, "amount": "9000000.00", "description": null, "adjustment_reason": null },
                { "code": "TRANSPORT", "calculation": "PER_PRESENT_DAY", "quantity": "21.00", "unit_amount": "50000.00", "amount": "1050000.00", "description": "21 days x 50,000" } ],
  "deductions": [ { "code": "COOP", "amount": "100000.00" } ] }
```
Errors: `404` for another employee's payslip or a payslip of payroll that is not FINALIZED/PAID.

### `GET /payslips/{id}/pdf[?download=1]`
Streams `application/pdf` from the private disk (`Cache-Control: private, no-store`); a missing file is regenerated from the snapshot. Same authorization as show.

## Salary setup (`salary.view` read, `salary.manage` write)

### Components
`GET /salary-components?active=&kind=` · `GET /salary-components/{id}` · `POST /salary-components` · `PATCH /salary-components/{id}` · `DELETE /salary-components/{id}`
```json
{ "code": "MEAL", "name": "Meal Allowance", "kind": "EARNING", "calculation": "PER_PRESENT_DAY",
  "is_basic": false, "default_amount": 35000, "default_rate": null, "description": null, "sort_order": 20, "is_active": true }
```
`kind`: `EARNING|DEDUCTION`; `calculation`: `FIXED|PERCENT_OF_BASIC|PERCENT_OF_GROSS|PER_PRESENT_DAY|PER_OVERTIME_HOUR|UNPAID_LEAVE_DEDUCTION` (`PERCENT_OF_GROSS` and `UNPAID_LEAVE_DEDUCTION` are deductions only). `default_amount` for amount types, `default_rate` (percent) for rate types. Errors: `409 COMPONENT_IN_USE` (changing kind/calculation/is_basic, or deleting, once used), `409 BASIC_COMPONENT_EXISTS`.

### Structures
`GET /salary-structures` · `GET /salary-structures/{id}` · `POST` · `PATCH` · `DELETE` (employee history is kept)
```json
{ "code": "STAFF", "name": "Staff", "description": null, "is_active": true,
  "components": [ { "salary_component_id": 1, "amount": 6000000, "rate": null, "sort_order": 0 } ] }
```
`PATCH` with `components` replaces the template's lines (employees are unaffected).

`POST /salary-structures/{id}/assign` `{ employee_id, effective_from, reason?, overrides?: [{ salary_component_id, amount?, rate? }] }` → `201 { created: [salaryValue], ended: 3, warnings: [] }`. Copies the structure into the employee's history from the date (and ends unlisted components).

### Employee salary
`GET /employees/{id}/salary?date=YYYY-MM-DD` → `{ employee, date, current: [salaryValue], history: [salaryValue] }` (`current` = values in force on `date`, default today).

`POST /employees/{id}/salary`
```json
{ "effective_from": "2026-07-01", "reason": "Annual raise",
  "components": [ { "salary_component_id": 1, "amount": 9000000 }, { "salary_component_id": 4, "remove": true } ] }
```
→ `201 { created: [...], ended: 1, warnings: [ { "code": "AFFECTS_FINALIZED_PAYROLL", "periods": ["2026-07"] } ] }`. Errors: `409 SALARY_CHANGE_CONFLICT`, `422 NO_CHANGES`.

`salaryValue`: `{ id, salary_component_id, code, name, kind, calculation, amount, rate, effective_from, effective_to, salary_structure_id, reason, created_by, created_at }`.

## Payroll periods (`payroll.view` to read)

### `GET /payroll-periods?status=&type=&year=&page=&per_page=` · `GET /payroll-periods/{id}`
```json
{ "id": 3, "type": "REGULAR", "code": "2026-09", "name": "September 2026", "start_date": "2026-09-01", "end_date": "2026-09-30", "pay_date": "2026-09-30",
  "status": "REVIEW", "adjusts_payroll_period_id": null, "adjustment_reason": null, "notes": null,
  "run": { "id": 3, "status": "REVIEW", "allowed_actions": ["generate", "return_to_draft", "approve", "cancel"],
           "currency": "IDR", "decimals": 0, "employee_count": 17, "total_gross": "…", "total_deductions": "…", "total_net": "…", "negative_net_count": 0,
           "summary": { "working_days": 22, "eligible": 18, "included": 17, "skipped": [ { "employee_id": 9, "employee_number": "EMP-0022", "employee_name": "Liam Park", "reason": "EMPLOYEE_INACTIVE" } ], "warnings": [] },
           "generated_by": { "id": 2, "name": "Sarah Wilson" }, "generated_at": "…", "submitted_by": { "id": 2, "name": "Sarah Wilson" }, "submitted_at": "…",
           "approved_by": null, "finalized_by": null, "paid_by": null, "payment_reference": null, "return_reason": null },
  "original": null, "adjustment_periods": [] }
```
Skip reasons: `NO_SALARY_COMPONENTS`, `EMPLOYEE_INACTIVE`, `EMPLOYEE_PENDING`. Item warning codes: `NEGATIVE_NET`, `NO_BASIC_SALARY`, `NO_WORKING_DAYS`.

### `POST /payroll-periods` — `payroll.manage`
`{ start_date, end_date, name?, pay_date?, notes? }` → `201` period with a DRAFT run. Period ≤ 31 days. `409 PERIOD_OVERLAP`.
`PATCH /payroll-periods/{id}` `{ name?, pay_date?, notes? }` — not after finalization (`409 PAYROLL_LOCKED`).

### Workflow actions (all `POST`, no body unless noted, return the period)
| URL | Permission | Notes |
|---|---|---|
| `/payroll-periods/{id}/generate` | `payroll.manage` | idempotent; DRAFT/REVIEW only; `409 PAYROLL_PROCESSING` if running |
| `/payroll-periods/{id}/submit` | `payroll.manage` | `422 PAYROLL_EMPTY` |
| `/payroll-periods/{id}/approve` | `payroll.approve` | `403 SEPARATE_APPROVER_REQUIRED`, `422 NEGATIVE_NET_PAY` |
| `/payroll-periods/{id}/return-to-draft` `{ reason }` | `payroll.manage` / `payroll.approve` | REVIEW or APPROVED → DRAFT |
| `/payroll-periods/{id}/finalize` | `payroll.finalize` | issues payslips, renders PDFs, notifies employees |
| `/payroll-periods/{id}/mark-paid` `{ payment_reference? }` | `payroll.finalize` | FINALIZED → PAID |
| `/payroll-periods/{id}/cancel` `{ reason }` | `payroll.manage` | DRAFT/REVIEW/APPROVED only |

Every wrong-state call → `409 INVALID_STATE_TRANSITION`.

### Runs, items, lines (read-only, `payroll.view`)
`GET /payroll-runs/{id}` · `GET /payroll-runs/{id}/items?q=&negative=&employee_id=&page=&per_page=` (default 50) · `GET /payroll-items/{id}` (with `earnings[]` / `deductions[]` lines as in the payslip detail).

### Adjustments
`POST /payroll-periods/{id}/adjustments` `{ reason, name?, pay_date? }` (`payroll.manage`, original must be FINALIZED/PAID regular period; `409 PERIOD_NOT_FINALIZED`) → `201` period `type: "ADJUSTMENT"`, code `2026-07-A1`.

`GET|POST /payroll-periods/{adjustmentId}/adjustment-entries` — `{ employee_id, kind: EARNING|DEDUCTION, label, amount (> 0), reason }`; `DELETE …/adjustment-entries/{entryId}`. Only while the adjustment period is DRAFT; employee must be in the original run. Entries changed after generation require generating again. Then generate → submit → approve → finalize → mark-paid as above.

## Overtime rules & payroll

Rule engine background: [overtime.md](overtime.md). Routes in `routes/api/v1/overtime.php`.

### Rules — read: `overtime.manage` | `payroll.manage` | `payroll.view`; write: `overtime.manage` | `payroll.manage`

`GET /overtime-rules?active=` · `GET /overtime-rules/{id}` · `POST /overtime-rules` · `PATCH /overtime-rules/{id}` · `DELETE /overtime-rules/{id}`

```json
{ "name": "Standard overtime", "effective_from": "2026-01-01", "effective_to": null,
  "employment_type": null, "department_id": null, "priority": 0,
  "rate_basis": "BASIC", "rate_divisor": 173,
  "multiplier_tiers": { "workday": [{"up_to_hours": 1, "multiplier": 1.5}, {"multiplier": 2}],
                        "rest_day": [{"up_to_hours": 8, "multiplier": 2}, {"multiplier": 3}],
                        "holiday":  [{"multiplier": 3}] },
  "rounding_minutes": 15, "rounding_direction": "DOWN", "min_minutes_per_request": 0,
  "max_minutes_per_day": 240, "max_minutes_per_period": 3600, "is_active": true, "notes": null }
```

`rate_basis`: `BASIC|FIXED_EARNINGS`; `rounding_minutes`: `1,5,6,10,15,30,60`; `rounding_direction`: `DOWN|NEAREST|UP`.
Errors: `422 VALIDATION_ERROR` (unknown day type, multiplier ≤ 0, non-increasing bands, open band not last, end before start,
foreign department), `409 RULE_IN_USE` on delete once it has paid overtime (deactivate / end-date it instead).

### Overtime requests (existing routes, extra fields)

`GET /overtime-requests?scope=&status=&payroll_status=NONE|PENDING_PAYROLL|INCLUDED|ADJUSTED|LEGACY` and `GET /overtime-requests/{id}` now return:

```json
{ "requested_minutes": 120, "approved_minutes": 120, "verified_minutes": 90, "verification_status": "PARTIAL",
  "day_type": "workday", "payroll_status": "INCLUDED",
  "payroll": { "payroll_period": {"id": 1, "code": "2026-07", "name": "July 2026", "status": "FINALIZED"},
               "payroll_item_id": 7, "payroll_item_line_id": 31, "minutes": 90, "hours": "1.50", "day_type": "workday",
               "rate": "30000.00", "multiplier": null, "amount": "75000.00", "currency": "IDR",
               "segments": [{"minutes": 60, "multiplier": "1.5", "amount": "45000", "payroll_item_line_id": 31},
                            {"minutes": 30, "multiplier": "2", "amount": "30000", "payroll_item_line_id": 32}],
               "locked": true, "payslip": {"id": 3, "payslip_number": "PS-202607-0001"} } }
```

`payroll` is shown only to the employee themself and to users with `payroll.view` / `payslip.view` (a team manager sees
`payroll_status` but `payroll: null`); `payslip` only once the run is FINALIZED/PAID.

`POST /overtime-requests/{id}/void-payroll-inclusion` — `payroll.manage`, body `{"reason": "..."}`. Only for an inclusion in
a FINALIZED/PAID run (`409 PAYROLL_NOT_FINALIZED` otherwise — regenerate instead; `409 NOT_INCLUDED`). The request becomes
`ADJUSTED` and is never paid automatically again; record the money correction in an adjustment period.

### Overtime on payroll lines

`GET /payroll-items/{id}` and `GET /payslips/{id}` lines carry a `source` block for earning-source lines (`null` otherwise):

```json
{ "code": "OVERTIME", "name": "Overtime – Workday ×1.5", "calculation": "SOURCE:OVERTIME", "quantity": "1.00",
  "unit_amount": "30000.00", "amount": "45000.00", "description": "1.00 h (60 min) × 30,000.00/h × 1.5",
  "source": { "key": "overtime", "minutes": 60, "hours": "1.00", "rate": "30000.00", "multiplier": "1.500", "amount": "45000.00",
              "refs": [{"type": "overtime_request", "id": 12, "work_date": "2026-07-14", "day_type": "workday", "minutes": 60,
                        "multiplier": "1.5", "amount": "45000", "approved_minutes": 180, "verified_minutes": 180, "carried_over": false}] } }
```

`payroll_runs.summary.earning_sources.overtime` = `{ "included_requests": n, "excluded": [{overtime_request_id, employee_id, work_date, reason, approved_minutes, verified_minutes}] }`
(reasons: `NO_ATTENDANCE`, `NOT_VERIFIED`, `NO_RULE`, `NO_RATE_BASIS`, `BELOW_MINIMUM`, `CAPPED_DAY`, `CAPPED_PERIOD`, `NO_TIER_FOR_DAY_TYPE`).
New generation error: `409 PAYROLL_INPUT_CHANGED` (source records kept changing during 3 attempts; retry).
