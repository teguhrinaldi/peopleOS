# Leave

## Concepts

| Concept | Table | Notes |
|---|---|---|
| Leave type | `leave_types` | Configurable (code, name, paid/unpaid, colour token, active). Types with requests are deactivated, never deleted. |
| Leave policy | `leave_policies` | Rules per type, optionally per employment type (`NULL` = default). |
| Balance | `leave_balances` | One row per employee × type × leave year. |
| Request | `leave_requests` | The request and its decision. Keeps a `policy_snapshot`. |
| Request days | `leave_request_days` | One row per half-day slot (`AM`/`PM`) the request occupies. |

Demo types (Annual 12 days, Sick, Personal 3 days, Unpaid, Other) are **examples**: buyers configure their own types and allowances in Leave Types (web admin) or `POST /api/v1/leave-setup/types`.

## Policy options

| Field | Meaning |
|---|---|
| `annual_allowance` | Days per leave year. `NULL` = not balance-limited (usage still tracked). |
| `prorate_first_year` | New joiners get the allowance pro-rated by remaining whole months, rounded down to half days. |
| `carry_over_max` | Unused days carried into the next year, capped (applied when the next year's balance is created). |
| `allow_negative`, `max_negative_days` | Allow borrowing up to N days. Default: never negative. |
| `allow_half_day` | Single-day AM/PM requests. |
| `allow_overlap` | Default false: a request may not overlap another live request. |
| `min_notice_days` | Earliest allowed start = today + N. Not checked for drafts. |
| `max_past_days` | How far back a request may start (e.g. Sick leave 7). Default 0. |
| `max_consecutive_days` | Working-day cap per request. |
| `requires_reason` | Reason required on submit. |

Policy resolution: the policy for the employee's `employment_type`, else the type's default. Leave years start in the month set by `leave.year_start_month` (Settings, default January). A request cannot span two leave years.

## Counting days

Only the employee's **working days** count, using the same calendar and holiday rules as attendance (`ScheduleService`): a Friday–Monday request is 2 days, a public holiday inside a request is free. Half days count 0.5.

## Balance

```
available = allowance + carried_over + adjustment − used − reserved
```

* **Submit** reserves: `reserved += days`. Nothing is consumed yet.
* **Approve** moves: `reserved −= days; used += days`.
* **Reject / cancel pending** releases `reserved`. **Cancel approved** (before it starts) releases `used`.
* HR adjustments (`adjustment`, audited, reason required) can never make `available` negative.
* The allowance is a snapshot taken when the balance row is created; editing a policy later affects new years only.

## State machine

```
DRAFT ──submit──► PENDING ──approve──► APPROVED ──cancel (before start)──► CANCELLED
  │                  │ └──reject (reason required)──► REJECTED
  └──cancel──► CANCELLED   └──cancel──► CANCELLED
```

Anything else returns `409 REQUEST_NOT_PENDING`. Drafts hold no balance and no calendar slots and are visible only to their author.

## Who can do what

| Action | Rule |
|---|---|
| Request, edit draft, submit | Self (`leave.request`), or HR on behalf of an employee in scope (`leave.manage`) |
| View | Self; `leave.view` (all) / `leave.view_team` (direct reports); reviewers |
| Approve / reject | `leave.approve` (anyone) or `leave.approve_team` (direct reports). **Never one's own request.** |
| Cancel | Owner, or someone who could approve it |
| Types, policies, balance adjustments | `leave.manage` |

Authorization is checked before status, so people outside the scope get `403` and never learn a request's state.

## Concurrency

Every write locks rows in a fixed order: **employee → request → balance**.

* The employee lock serializes one person's simultaneous submissions, so two requests cannot both pass the balance check.
* Overlap is refused by a readable pre-check and, decisively, by the unique index `leave_days_no_overlap (employee_id, date, slot, blocking)`. `blocking` is `1` for live requests and `NULL` once released; MySQL ignores `NULL`s in unique indexes.
* Two approvers deciding at once: the request row lock lets one win; the other gets `409`, and `used` is counted once.

`tests/Feature/LeaveConcurrencyTest.php` proves all three with real parallel PHP processes.

## Integration

* **Attendance:** check-in on an approved full-day leave returns `409 ON_LEAVE`; `GET /attendance/today` reports `state: ON_LEAVE`. Half-day leave leaves the other half workable.
* **Payroll:** reads `LeaveLedger::unpaidDaysBetween()` (approved unpaid days per calendar slot) for the unpaid leave deduction.
* **Termination:** pending and not-yet-started approved leave is cancelled and its days released.
* **Notifications:** the direct manager is notified when a request is submitted; the employee when it is approved or rejected.
* **Audit:** draft created/updated, submitted, approved, rejected, cancelled, balance adjusted, type/policy changes.
