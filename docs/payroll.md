# Salary structures, payroll and payslips

This document is the source of truth for the payroll module's business rules. The API contract is in [api-payroll.md](api-payroll.md).

> **PeopleOS is a configurable payroll-engine foundation, not a tax or statutory product.**
> It contains **no** income-tax (e.g. PPh 21), social-security (e.g. BPJS), minimum-wage or other government rule, and it does not claim compliance with any jurisdiction. Every earning and deduction is a component the buyer configures. If your country requires statutory calculations, configure them as components where the generic calculation types suffice, or extend `PayrollCalculator` with your own rule and have it reviewed by a qualified local advisor.

## Concepts

| Concept | Table | Purpose |
|---|---|---|
| Salary component | `salary_components` | Company catalog: code (unique per company), name, kind `EARNING`/`DEDUCTION`, calculation type, default amount/rate, sort, active flag. Exactly one component may be flagged `is_basic` (a `FIXED` earning) |
| Salary structure | `salary_structures`, `salary_structure_components` | Named template ("Staff", "Manager") listing components with default values |
| Employee salary | `employee_salary_components` | The employee's **effective-dated** values (`effective_from` / `effective_to`, `created_by`, `reason`, optional source structure) |
| Payroll period | `payroll_periods` | `REGULAR` pay period, or `ADJUSTMENT` linked to a finalized period (`adjusts_payroll_period_id`). `status` mirrors its run |
| Payroll run | `payroll_runs` | Exactly one per period (unique). Workflow state, actors, currency/decimals and company snapshot, totals, generation summary |
| Payroll item | `payroll_items` | One per employee per run (unique). Employee snapshot, inputs, basic/gross/deductions/net |
| Payroll item line | `payroll_item_lines` | One per earning/deduction with code, name, kind, calculation, rate, quantity, unit amount, base, amount, description |
| Adjustment entry | `payroll_adjustments` | Manual earning/deduction (with reason) of an `ADJUSTMENT` period |
| Payslip | `payslips` | Issued at finalization, one per item (unique), unique number, PDF on the private disk |

## Calculation types

All maths is in `App\Services\Payroll\Calculation\PayrollCalculator` — a pure class without database, clock or settings, fed by an input DTO and fully unit-tested (`tests/Unit/PayrollCalculatorTest.php`). Money uses bcmath strings, never floats.

| Calculation | Uses | Formula | Kinds |
|---|---|---|---|
| `FIXED` | amount | amount per period | both |
| `PERCENT_OF_BASIC` | rate (%) | basic × rate / 100 | both |
| `PERCENT_OF_GROSS` | rate (%) | gross × rate / 100 | **deductions only** |
| `PER_PRESENT_DAY` | amount | amount × present days | both |
| `PER_OVERTIME_HOUR` | amount | amount × approved overtime minutes / 60 (legacy flat rate; **skipped** when the overtime rule engine is on, see [overtime.md](overtime.md)) | both |
| `UNPAID_LEAVE_DEDUCTION` | optional rate (default 100 %) | basic / working days × unpaid leave days × rate / 100 | **deductions only** |

```
basic       = sum of FIXED components flagged is_basic
gross       = sum of earning lines            (Basic + allowances = Gross)
deductions  = sum of deduction lines          (PERCENT_OF_GROSS uses the gross above)
net         = gross - deductions              (Gross - Deductions = Net)
```

* **Rounding:** every line is rounded **half-up** to the currency decimals *before* summing, so a payslip always adds up. Decimals come from Settings `payroll.rounding_decimals` (new key): `auto` (default) = the currency's minor units from `config/payroll.php` (IDR, JPY, KRW, VND … → 0, everything else → 2, capped at 2 because money columns are `DECIMAL(15,2)`), or an explicit `0`, `1`, `2`. The currency is Settings `general.currency` (IDR by default). Both are **snapshotted on the run** at generation.
* Lines whose amount rounds to 0 are omitted (the inputs stay on the item).
* **Negative net** is calculated, stored (`has_negative_net`), listed in the run summary and **blocks approval** (`422 NEGATIVE_NET_PAY` with the employees). It is never clamped silently.
* Values are never negative: component amounts/rates are validated `>= 0`; a deduction is a positive amount with kind `DEDUCTION`.

### Inputs (`PayrollInputCollector`)

| Input | Source |
|---|---|
| Present days | `attendance` rows whose `work_date` is in the period, with a `check_in_at`, and `status != REJECTED` |
| Overtime (legacy) | engine **off**: sum of `overtime_requests.approved_minutes` with status `APPROVED` and `work_date` in the period, consumed by `PER_OVERTIME_HOUR`. Engine **on**: 0 for components; overtime arrives as earning-source lines (below) |
| Unpaid leave days | `LeaveLedger::unpaidDaysBetween(employee, max(start, join), reference date)` (half days count 0.5) |
| Working days | days of the period that are working days in the **company default working calendar** (Mon–Fri if none) and are not company holidays. Per-employee calendars are deliberately not used, so everyone's daily rate in a period has the same divisor |

## Earning sources (extension point)

Modules that pay their own approved records (overtime now, reimbursements next) plug into generation through
`App\Services\Payroll\Contracts\PayrollEarningSource`, registered in `config/payroll.php` → `earning_sources`.
Adding a module needs **no payroll code change**: implement the interface and add the class to that list.

| Method | When | Must |
|---|---|---|
| `key()` | always | stable id stored on `payroll_item_lines.source_key` (e.g. `overtime`) |
| `enabled()` | each generation | `false` = contributes nothing (its hooks still run) |
| `prepare(period, run, employeeIds)` | build, no transaction | batch-load candidates (read only) |
| `linesFor(employee, period, EarningContext)` | build, no transaction | return `EarningLine`s: code, name, amount, quantity / unit amount / multiplier / minutes, description, **`refs`** (source records, stored as JSON on the line) and a private `payload` |
| `summary()` | after build | notes for `payroll_runs.summary.earning_sources.<key>` (e.g. excluded records + reason) |
| `lockSources(run, lines)` | store tx (period + run locked) | lock referenced records `FOR UPDATE`, re-validate, throw `EarningSourceConflict` if one changed or was taken (the build is retried, max 3, then `409 PAYROLL_INPUT_CHANGED`) |
| `markIncluded(run, line, earning)` | store tx, after the line is inserted | record the inclusion (protected by a UNIQUE constraint) |
| `releaseRun(run)` | regeneration (store tx), cancel, return to draft | forget this run's non-locked inclusions |
| `freezeRun(run)` | finalize tx | make inclusions immutable |

`EarningContext` gives the employee's salary components on the reference date (`basic()`, `fixedEarnings()`), decimals
and the run. Source lines enter `PayrollCalculator` as `PayrollInput::sourceLines`: earnings are added to gross before
`PERCENT_OF_GROSS` deductions; they are stored with `calculation = SOURCE:<KEY>`, `source_key`, `source_refs`, `minutes`,
`multiplier`. Adjustment periods also ask the sources (for employees paid in the original run).

## Effective-dated salaries

* A change **closes** the row in force (`effective_to = effective_from − 1 day`) and adds a new open row. Amounts are never overwritten. Example: Basic 8,000,000 from 2026‑01‑01 and 9,000,000 from 2026‑07‑01 → June uses 8,000,000, July uses 9,000,000 (tested).
* The timeline only grows forward: a change dated on/before an existing later change of the same component → `409 SALARY_CHANGE_CONFLICT`. Submitting the value already in force → `422 NO_CHANGES`. `remove: true` ends a component.
* **Mid-period rule (no pro-rating):** payroll uses the values in force on the **reference date** = the period end date, or the termination date when the employee left earlier. A raise effective 2026‑07‑16 therefore applies to all of July. Pro-rating for joiners/leavers/mid-month changes is not automatic; use an adjustment entry if your policy requires it.
* Back-dated changes are allowed, but finalized payroll is never recalculated; the response warns `AFFECTS_FINALIZED_PAYROLL` with the period codes so HR can create an adjustment.
* **Assigning a structure** copies its values (with optional per-employee overrides) into the employee's history from a date, and ends components the employee has that the structure does not list. Later edits of the structure never change employees.
* Deactivating a component stops it from being paid in future generations; a component with employee values or payroll lines cannot change kind/calculation/basic flag and cannot be deleted (`409 COMPONENT_IN_USE`).

## Who is paid

* Employees with `join_date <= period end` and (`terminated_at` empty or `>= period start`).
* Status `ACTIVE`, or `TERMINATED` with a termination date: a terminated employee is included only for periods overlapping their employment (historical processing), never later ones.
* `INACTIVE` / `PENDING` employees are not paid and appear in the run summary (`EMPLOYEE_INACTIVE`, `EMPLOYEE_PENDING`). There is no status history, so re-processing an old month for someone who is inactive today requires reactivation or an adjustment.
* Employees with no salary components on the reference date are skipped and reported (`NO_SALARY_COMPONENTS`) — never silently.

## State machine

One place: `App\Services\Payroll\PayrollStateMachine` (action → allowed source states → target). `PayrollTransitions::apply()` is the only writer of `payroll_runs.status` and the mirrored `payroll_periods.status`.

```
 DRAFT ──generate──► PROCESSING ──finish──► DRAFT ──submit──► REVIEW ──approve──► APPROVED ──finalize──► FINALIZED ──mark_paid──► PAID
   │                     │                                    │  │                   │
   │                     └─abort (failure / stalled)─► DRAFT|REVIEW (where it started)  │
   │                                                          │  └─generate─► PROCESSING (regenerate → DRAFT, must re-submit)
   │                                                          └─return_to_draft─► DRAFT ◄─return_to_draft─┘
   └──cancel──► CANCELLED ◄──cancel── REVIEW, APPROVED
```

| Action | From | To | Permission | Extra rules |
|---|---|---|---|---|
| generate | DRAFT, REVIEW | PROCESSING → DRAFT | `payroll.manage` | `409 PAYROLL_PROCESSING` while someone else generates |
| submit | DRAFT | REVIEW | `payroll.manage` | needs a generation with ≥ 1 employee (`422 PAYROLL_EMPTY`) |
| approve | REVIEW | APPROVED | `payroll.approve` | separate approver (below); no negative net |
| return-to-draft | REVIEW, APPROVED | DRAFT | `payroll.manage` or `payroll.approve` (APPROVED: `payroll.approve`) | reason required |
| finalize | APPROVED | FINALIZED | `payroll.finalize` | issues payslips, renders PDFs |
| mark-paid | FINALIZED | PAID | `payroll.finalize` | optional payment reference |
| cancel | DRAFT, REVIEW, APPROVED | CANCELLED | `payroll.manage` | reason required |

Anything else → `409 INVALID_STATE_TRANSITION` (`details.action`, `status`, `allowed_from`). Nothing leaves FINALIZED except → PAID; PAID and CANCELLED are terminal. Tested exhaustively (unit: every state pair and every action × state; feature: every invalid API action in every state).

**Segregation of duties:** with Settings `payroll.require_separate_approver` (default `true`) the approver must differ from the user who generated **and** from the user who submitted the run → `403 SEPARATE_APPROVER_REQUIRED`. In the demo, HR prepares and the Admin approves.

## Snapshot & immutability

* Generation stores everything needed to reproduce the slip: employee number/name/department/position/branch/employment type/join and termination dates, salary reference date, working/present days, overtime minutes, unpaid days, and per line the component code, name, kind, calculation, rate, quantity, unit amount, base and amount (earning-source lines also: source key, source record refs, minutes, multiplier; overtime inclusions freeze the rule snapshot). The run stores currency, decimals and a company snapshot (name, legal name, address).
* Payslip PDFs are rendered **only** from that snapshot. Changing a salary, a component name, the currency, rounding or the payslip prefix after finalization changes nothing in finalized items, lines or payslips (tested).
* FINALIZED/PAID payroll cannot be edited or deleted:
  1. **Services** refuse (`409`), the state machine has no edge back.
  2. **Model guards** (`updating`/`saving`/`deleting` events on `PayrollPeriod`, `PayrollRun`, `PayrollItem`, `PayrollItemLine`, `Payslip`, `PayrollAdjustment`) throw `PayrollLockedException` (`409 PAYROLL_LOCKED`). The only permitted writes are FINALIZED → PAID with its payment fields, and payslip document bookkeeping (PDF file metadata while missing, `notified_at`). Periods, runs and payslips can never be deleted.
  3. **No delete routes** exist for periods, runs, items, lines or payslips (tested).
  4. Foreign keys from history to masters are `RESTRICT`.
  Raw SQL against the database bypasses application guards; restrict the production DB user accordingly.
* **Corrections** use an `ADJUSTMENT` period (`POST payroll-periods/{id}/adjustments`, reason required) linked to the finalized original. Manual entries (employee, EARNING/DEDUCTION, label, amount, reason) may be added/removed only while it is DRAFT and only for employees paid in the original run; changing entries drops stale generated items. "Generate" turns the entries into items/lines (`MANUAL_ADJUSTMENT`, linked to the original item), then the same submit → approve → finalize → paid workflow applies and adjustment payslips are issued (e.g. `PS-202607A1-0001`). Overpayments that would make an adjustment's net negative are recovered in a later regular payroll instead (e.g. an "Other Deductions" value).

## Concurrency & idempotency

* `payroll_runs.payroll_period_id` UNIQUE (one run per period), `payroll_items(payroll_run_id, employee_id)` UNIQUE, `payslips.payroll_item_id` and `payslips.payslip_number` UNIQUE.
* Every action runs in a transaction that locks the period row then the run row `FOR UPDATE` (fixed order: no deadlocks), and re-validates state after acquiring the lock — a double submission sees the new state and gets `409 INVALID_STATE_TRANSITION`.
* Generation: tx 1 moves to PROCESSING with a private token (concurrent callers now get `409 PAYROLL_PROCESSING`); calculation happens outside a transaction; tx 2 re-locks, verifies the token, deletes and re-inserts all items/lines atomically and computes totals with `SUM()` over the stored items. On failure the run returns to the state it started from with the previous items intact. A PROCESSING run older than `config('payroll.processing_timeout_minutes')` (15) is considered crashed and may be taken over.
* Regular periods never overlap (cancelled ones excluded); creation locks the company row.
* Earning sources: each overtime request has at most one live inclusion (`overtime_payroll_inclusions.active_request_id` UNIQUE); referenced requests are locked `FOR UPDATE` in the store transaction. `OvertimeConcurrencyTest` races real processes (three generations of one period; two periods competing for the same late-approved overtime) → every request paid exactly once.
* Proven by `tests/Feature/Payroll/PayrollConcurrencyTest.php` with real parallel PHP processes: three simultaneous generations → one run, exactly N items, no duplicate items/lines; two simultaneous approvals and three simultaneous finalizations → exactly one succeeds, one set of payslip numbers and PDFs.

## Payslips

* Issued at finalization in employee-number order: `{payroll.payslip_prefix}-{period code without dashes}-{0001}`, e.g. `PS-202607-0001`.
* PDF rendered with dompdf (DejaVu Sans, bundled with dompdf) in the PeopleOS navy/blue style: company, employee, employee number, department, position, period, pay date, present/working days, earnings, deductions, gross, net, payslip number and issue date. Overtime lines show `hours × rate/h × multiplier = amount` and the paid work dates/minutes, from the frozen line.
* Stored on the **private** disk (`config('payroll.payslip_disk')`, default `local` → `storage/app/private/payslips/{year}/{number}-{random}.pdf`), path never exposed; streamed only through `GET /api/v1/payslips/{id}/pdf` after the policy check, with `Cache-Control: private, no-store`.
* PDFs are rendered right after the finalize transaction commits. A failure never undoes finalization: `php artisan payroll:payslips` (scheduled hourly, `--verify` also re-creates files missing from disk) renders missing files. It locks each payslip and never rewrites an existing file.
* Access: `payslip.view` → every payslip; `payslip.view_own` → only one's own and only for FINALIZED/PAID payroll. Other ids answer `404` (no probing).
* The employee gets one in-app notification `payslip.available` per payslip (`dedupe_key = payslip.available.{id}`), sent after the PDF exists.

## Audit trail

`ActivityLogger` records: `salary_component.created|updated|deleted`, `salary_structure.created|updated|deleted`, `employee_salary.changed` (only the changed components with from/to values, effective date and reason), `payroll_period.created|updated`, `payroll.generated|regenerated|generation_failed|generation_released`, `payroll.submitted|approved|returned|finalized|paid|cancelled` (old/new status, period code, counts), `payroll_adjustment.period_created|entry_added|entry_removed`, `overtime_rule.created|updated|deleted`, `overtime.payroll_voided`. Full salary tables, file paths and PDF contents are never logged.

## Settings & configuration

| Key | Default | Meaning |
|---|---|---|
| `general.currency` | `IDR` | Currency code snapshotted on each run |
| `payroll.rounding_decimals` | `auto` | `auto` (currency minor units, ≤ 2) or `0`/`1`/`2` |
| `payroll.require_separate_approver` | `true` | Approver ≠ generator/submitter |
| `payroll.payslip_prefix` | `PS` | Payslip number prefix (letters/digits) |
| `overtime.engine_enabled` / `overtime.require_attendance_verification` / `overtime.carry_over_late_approvals` | `false` / `true` / `true` | Overtime rule engine, see [overtime.md](overtime.md) |
| `config/payroll.php` | | `earning_sources`, `payslip_disk`, `payslip_directory`, `processing_timeout_minutes`, `currency_decimals` |

## Demo data (`PayrollDemoSeeder`)

Demo values only (not statutory): components Basic Salary, Transport (50,000/present day), Meal (35,000/present day), Position Allowance, Overtime (50,000/hour), Unpaid Leave deduction, Cooperative Savings (100,000), Other Earnings/Deductions; structures **Staff** and **Manager**; salaries for all 18 employees by position level (6.5–24 million IDR); Michael Carter 8,000,000 → **9,000,000 from July 2026**, Olivia Martin 7,000,000 → 7,400,000 from September. Attendance is back-filled from 2026‑07‑01 so present-day allowances are realistic. The **overtime rule engine is on** with the demo rule (basic/173; workday ×1.5 first hour then ×2; rest day ×2 then ×3; holiday ×3 — demo values): the demo overtime (July/August/September) has matching attendance and is paid as verified `Overtime – Workday ×1.5/×2` lines with request references. `OvertimeDemoSeeder` then creates **October 2026 in REVIEW** with a verification scenario (verified, rest day, partial, not verified → excluded, late approval of a finalized month carried over). For an existing database use the safe path in [overtime.md](overtime.md#demo-data-safe-path) (never touches finalized payroll). **July and August 2026 are FINALIZED + PAID** with real PDFs (Noah Davis, terminated 2026‑07‑31, appears in July only; Liam Park is INACTIVE and reported as skipped); **September 2026 is in REVIEW**, generated and submitted by HR (`hr@peopleos.test`) — HR cannot approve it, the Admin (`admin@peopleos.test`) can.

## Limitations

* No statutory/tax rules, no bank-file export, no automatic pro-rating, no multi-currency per employee.
* Working-day divisor uses the company default calendar only.
* `INACTIVE` status has no history (see "Who is paid").
* PDFs render synchronously after finalize (≈ 0.2–0.5 s each); very large companies should run `payroll:payslips` from a queue/worker instead.
