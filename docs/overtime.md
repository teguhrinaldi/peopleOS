# Overtime → payroll (rule engine)

Overtime requests (see [attendance.md](attendance.md#overtime)) become pay through a configurable **rule engine** that
runs inside payroll generation. Every paid minute is traceable: payroll line → overtime request ids → rule snapshot.

> **No legal compliance is implied.** PeopleOS ships *mechanics* (rate basis, divisor, multipliers by day type and
> hour band, rounding, caps). The demo rule values are illustrative. Each buyer must configure the rules of their
> jurisdiction and employment contracts, and have them reviewed by a qualified payroll / labour-law adviser.

## Switching it on

| Setting (`settings` table, web admin → Settings) | Default | Meaning |
|---|---|---|
| `overtime.engine_enabled` | `false` | Pay overtime through the rule engine. When on, `PER_OVERTIME_HOUR` salary components are **skipped** (item warning `OVERTIME_COMPONENT_SUPERSEDED:<code>`) so overtime is never counted twice. When off, payroll behaves exactly as before (legacy component). |
| `overtime.require_attendance_verification` | `true` | Without a complete attendance record (check-in **and** check-out) for the work date, the request is not paid (`NO_ATTENDANCE`). `false` = pay the approved minutes when there is no attendance at all. |
| `overtime.carry_over_late_approvals` | `true` | Overtime approved after its period was finalized is paid by the next regular period. `false` = only an adjustment period of the original period pays it. |

Migration from the legacy component: the migration marks every APPROVED request that lies inside an already
FINALIZED/PAID run as `payroll_status = LEGACY` (paid by the old component) — the engine never pays those again.
Enable the engine at a period boundary; an open run generated before the switch keeps its legacy line until it is regenerated.

## Minutes: requested → approved → verified → eligible → payable

| Field | Source |
|---|---|
| `requested_minutes` | the employee's request (`end_at − start_at`) |
| `approved_minutes` | the reviewer (≤ requested) |
| `verified_minutes` | attendance cross-check (below), recorded at approval and **re-evaluated at every payroll generation** |
| eligible | `min(approved, verified)` when the work date has a complete attendance record; otherwise approved (verification not required) or 0 |
| payable | eligible after rounding, minimum and caps of the rule (`OvertimeCalculator`) |

**Verification** (`OvertimeVerifier`, pure): the part of the *approved window* `[start_at, start_at + approved]` that lies
inside `[check_in, check_out]` **and outside the scheduled shift** `[expected_start, expected_end]` of that date
(regular hours are paid by the salary). On rest days and holidays there is no regular shift, so the whole overlap counts.
`verification_status`: `VERIFIED` (verified ≥ approved), `PARTIAL`, `NOT_VERIFIED` (attendance but no overlap),
`NO_ATTENDANCE`, `NOT_REQUIRED`.

**Day type**: frozen on the request at approval from the employee's schedule — `holiday` (company holiday) >
`rest_day` (non-working day of the working calendar) > `workday`.

## Rules (`overtime_rules`, effective-dated)

| Column | Meaning |
|---|---|
| `effective_from` / `effective_to` | validity by work date |
| `employment_type`, `department_id` | optional scope; NULL = everyone. Most specific wins, then `priority`, then latest `effective_from` |
| `rate_basis`, `rate_divisor` | hourly rate = round(basis / divisor, 2). `BASIC` = FIXED earnings flagged basic; `FIXED_EARNINGS` = all FIXED earnings (e.g. basic + fixed allowances). Divisor e.g. 173 (≈ 40 h × 52 / 12) |
| `multiplier_tiers` | JSON per day type, ordered bands over the cumulative hours **of the work date**: `{"workday":[{"up_to_hours":1,"multiplier":1.5},{"multiplier":2}], "rest_day":[...], "holiday":[...]}`. A missing day type = not payable (`NO_TIER_FOR_DAY_TYPE`) |
| `rounding_minutes`, `rounding_direction` | 1 = none; 15 = quarter hours, `DOWN` (default) / `NEAREST` / `UP` (UP/NEAREST may pay up to unit − 1 minutes more than eligible — a policy choice) |
| `min_minutes_per_request` | below it nothing is paid (`BELOW_MINIMUM`) |
| `max_minutes_per_day`, `max_minutes_per_period` | caps (`CAPPED_DAY`, `CAPPED_PERIOD`); minutes already paid by other runs for the same dates count |
| `is_active` | inactive rules are ignored |

No matching rule → the request is excluded (`NO_RULE`) and stays pending. Editing a rule never changes finalized
payroll (each inclusion stores the rule snapshot); a rule that has paid overtime cannot be deleted (`409 RULE_IN_USE`).

## Calculation (`App\Services\Overtime\OvertimeCalculator`, DB-free, bcmath strings)

```
rate          = round(basis / divisor, 2)
minutes       = round(eligible, rounding) → minimum → day cap → period cap
segments      = minutes split over the day type's bands, starting at the minutes already paid that day
segment       = round(rate × minutes × multiplier / 60, currency decimals)      (multiply first, divide last)
request total = Σ segments
payroll line  = one per (day type, multiplier, rate) per employee = Σ its segments
```

Requests of an employee are processed in `(work_date, start_at, id)` order, so two requests on one day share the
"first hour" band and the day cap deterministically.

### Worked example (exactly what `OvertimePayrollTest::test_worked_example_…` asserts)

Demo rule: basic / 173; workday first hour ×1.5, then ×2; rest day ×2 (8 h) then ×3; holiday ×3. Currency IDR (0 decimals).
Employee: basic **5,190,000**, transport 50,000 per present day, cooperative savings 100,000, legacy OVERTIME component 40,000/h.

| Step | Value |
|---|---|
| Hourly rate | 5,190,000 / 173 = **30,000.00** |
| Tue 14 Jul 2026: shift 08:00–17:00, request 17:00–20:00 (180 min), checked out 20:00 | verified 180 → eligible 180 |
| → band 1 (first hour) | 60 min × 30,000 × 1.5 / 60 = **45,000** |
| → band 2 | 120 min × 30,000 × 2 / 60 = **120,000** |
| Sat 18 Jul 2026 (rest day): request 09:00–13:00 (240 min), attended 08:00–13:00 | 240 min × 30,000 × 2 / 60 = **240,000** |
| Overtime total | **405,000** (3 lines: Workday ×1.5, Workday ×2, Rest day ×2) |
| Gross | 5,190,000 + 2 × 50,000 + 405,000 = **5,695,000** (legacy OVERTIME component skipped) |
| Net | 5,695,000 − 100,000 = **5,595,000** |

On the payslip PDF the first line reads: `Overtime – Workday ×1.5 | 1.00 h × 30,000.00/h × 1.5 = 45,000 | 14 Jul: 180 min`.

If the employee had checked out at 18:00 instead, verified = 60 → only the 1.5× hour is paid (45,000), status `PARTIAL`.

## Exactly once

* `overtime_payroll_inclusions` holds one row per paid request: run, line, rule, minutes, day type, multiplier
  (NULL when several bands applied — see `segments`), hourly rate, amount, segments and a JSON snapshot of the rule and inputs.
* `active_request_id` is **UNIQUE** (= `overtime_request_id` while live, NULL only after a controlled void) — the database
  itself refuses a second live inclusion of a request, across all runs and periods.
* Generation (inside the store transaction, period + run locked): release this run's previous inclusions →
  lock the referenced requests `FOR UPDATE` and re-validate (still APPROVED, same approved minutes, not taken by
  another run) → insert lines → insert inclusions. A request that changed or was taken in between makes the store
  roll back and the build is repeated (max 3 times, then `409 PAYROLL_INPUT_CHANGED`).
* Regeneration while DRAFT/REVIEW is idempotent (same requests, same lines). **Finalize** sets `locked_at` on the run's
  inclusions (model guard: no edit/delete). **Cancel** releases them. **Return to draft** releases them *and* clears
  `generated_at`, so the run must be regenerated before it can be submitted again (its old lines would otherwise pay
  requests that are no longer reserved).
* Never paid: `PENDING`, `REJECTED`, `CANCELLED`; `payroll_status` `ADJUSTED` (voided) and `LEGACY`; anything with a live
  inclusion in another run.

`overtime_requests.payroll_status`: `NONE` → `PENDING_PAYROLL` (approved) → `INCLUDED` (live inclusion) →
`ADJUSTED` (voided after finalization). `LEGACY` = paid by the old component.

## Late approval after finalization

A finalized run is never edited. Overtime approved after its period was finalized stays `PENDING_PAYROLL` and is paid by:

1. the **next regular period** generated (default; the line ref has `carried_over: true`, the PDF shows "(late approval)"), or
2. an **adjustment period** of the original period (`POST /payroll-periods/{id}/adjustments`): its generation adds overtime
   lines for people paid in the original run, next to manual entries — with its own review/approval/finalize workflow.

Whichever is generated first takes it; the unique constraint guarantees the other cannot.

**Undoing a finalized inclusion** (e.g. paid outside payroll by mistake): `POST /overtime-requests/{id}/void-payroll-inclusion`
(`payroll.manage`, reason required, finalized inclusions only). The inclusion is voided, the request becomes `ADJUSTED`
and is never picked up again; the money correction is a manual entry in an adjustment period. Open runs are corrected by regenerating.

## Adapting to your jurisdiction

* Create one rule per policy (company-wide, then scoped ones per employment type / department), with `effective_from`
  dates for legal changes — never edit a rule retroactively, end-date it and add a new one.
* Choose the basis (`BASIC` or `FIXED_EARNINGS`) and divisor your law/contract prescribes; put daily thresholds into
  `up_to_hours` bands; use `max_minutes_per_day/period` for statutory limits (pay is capped; approval is not blocked).
* Things the engine does **not** do (implement as an extra earning source or manual adjustment if needed): night-shift
  premiums, meal/transport compensation for overtime, time-off-in-lieu, per-week thresholds, different holiday classes,
  tax treatment, pro-rating by salary changes inside the period (the salary on the period reference date is used).

## Limitations

* Verification uses one attendance record per work date; overnight overtime crossing into the next day is verified
  against that day's record only.
* Rounding is per segment (per request and band); a line's `hours × rate × multiplier` may differ from its amount by
  rounding when several requests are grouped in one line.
* Day type is frozen at approval; requests created directly (imports) are classified at payroll time.

## API

See [api-payroll.md](api-payroll.md#overtime-rules--payroll).

## Demo data (safe path)

Fresh install: `php artisan migrate:fresh --seed` — `PayrollDemoSeeder` enables the engine, pays July/August (PAID) and
September (REVIEW) overtime through it, then calls `OvertimeDemoSeeder`.

Existing dev database (keeps every finalized/paid run untouched):

```
php artisan migrate                                          # adds the 2026_06_10 overtime tables/columns (+ LEGACY backfill)
php artisan db:seed --class=RolesAndPermissionsSeeder        # adds overtime.manage to Admin/HR
php artisan db:seed --class=OvertimeDemoSeeder               # idempotent
```

`OvertimeDemoSeeder` creates the demo rule (if none), switches the engine on, and creates the next open regular period
after the latest one (October 2026 on the demo data) with: a verified workday overtime (EMP‑0015, 180 min), a rest-day
overtime (EMP‑0016, 240 min), a partially verified one (EMP‑0012, 60 of 120 min), an unverified one (EMP‑0019 → excluded,
listed in the run summary) and a late approval of a date in the last finalized month (EMP‑0021 → carried over). The
period is generated and submitted (REVIEW). It extends/creates the attendance rows of those dates only. Note: the
existing September REVIEW run keeps its legacy lines until someone regenerates it; regenerating it applies the engine
(whose verification then excludes September demo overtime without matching attendance).
