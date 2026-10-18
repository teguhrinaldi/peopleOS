# Reimbursements

Employees claim business expenses with a receipt. A reviewer approves the full amount, approves a lower amount (with a reason), or rejects the claim (with a reason). HR then pays it separately, or routes it through payroll. Nothing is ever deleted. Claims are cancelled instead, and every step leaves an audit row plus a history event.

API reference: [api-reimbursements.md](api-reimbursements.md).

## Data model

| Table | Purpose |
|---|---|
| `expense_categories` | Configurable catalogue per company: `code`, `name`, `is_active`, `receipt_required`, `max_amount_per_claim`, `max_amount_per_month` (per employee, per calendar month of the expense date), `max_age_days`. Retire a category with `is_active = false`. Do not delete it. |
| `expense_policies` | One row per company: `approval_route` (`MANAGER_OR_HR` default, or `HR_ONLY`), `payment_route` (`SEPARATE` default, or `PAYROLL`), company-wide `max_amount_per_claim` / `max_amount_per_month`, `max_receipt_kb` (default 5120). |
| `expense_requests` | The claim. `request_number` is UNIQUE (`RB-YYYYMM-0001`). Stores amounts as `DECIMAL(15,2)`, the status, the receipt metadata (never the binary), decision fields, a `policy_snapshot` taken at submission and the `payment_route` taken at approval. Also has the nullable payroll linkage `payroll_item_line_id` (UNIQUE), `payroll_period_id` and `payroll_included_at`. |
| `expense_request_events` | Append-only lifecycle history: `created`, `updated`, `submitted`, `review_started`, `approved`, `rejected`, `withdrawn`, `cancelled`, `scheduled`, `payroll_included`, `paid`. |
| `expense_payments` | Append-only. `expense_request_id` is UNIQUE, so one payment per claim can ever exist. `idempotency_key` is UNIQUE. Stores method, reference, paid_at and paid_by. |
| `expense_number_sequences` | Monthly counter per company that produces the request numbers. |

Both append-only tables are protected twice. Eloquent `updating`/`deleting` hooks throw, and MySQL `BEFORE UPDATE` / `BEFORE DELETE` triggers raise `SQLSTATE 45000`.

## State machine

```
DRAFT ─► SUBMITTED ─► UNDER_REVIEW ─► APPROVED ─► SCHEDULED_FOR_PAYMENT ─► PAID
  │          │              │   └──► REJECTED          │                     
  └──────────┴──────────────┴──────────► CANCELLED ◄───┘ (HR, before payment)
```

`App\Services\Reimbursements\ExpenseStateMachine` is the only place that defines the allowed transitions. Every action calls `ExpenseStateMachine::assert()` on the locked row. An invalid transition returns **409 `INVALID_TRANSITION`**.

Actor rules on top of the table:

- **Withdraw** (owner only): from `DRAFT`, `SUBMITTED` or `UNDER_REVIEW`. After a decision, the owner gets 409 and must ask HR.
- **Cancel** (`expense.manage`): any non-terminal, non-draft state. A reason is required. It is refused (409 `EXPENSE_ALREADY_SETTLED`) once the claim is in payroll or has a payment.
- `REJECTED`, `PAID` and `CANCELLED` are terminal. A rejected claim cannot be resubmitted, so the employee creates a new one.

## Rules

| Rule | When it is checked | Error |
|---|---|---|
| Category active | create, edit, submit | 422 `EXPENSE_CATEGORY_UNAVAILABLE` |
| Currency = company currency (`general.currency`) | create, edit | 422 `CURRENCY_NOT_SUPPORTED` |
| Amount > 0, at most 2 decimals | create, edit | 422 `VALIDATION_ERROR` |
| Expense date not in the future | create, edit, submit | 422 `EXPENSE_DATE_IN_FUTURE` |
| Receipt present if the category requires it | submit | 422 `RECEIPT_REQUIRED` |
| Per-claim limit (category and company) | submit | 422 `EXPENSE_LIMIT_EXCEEDED` (`details.scope`) |
| Monthly limit (category and company), per employee | submit | 422 `EXPENSE_MONTHLY_LIMIT_EXCEEDED` |
| Expense age ≤ `max_age_days` | submit | 422 `EXPENSE_TOO_OLD` |
| Reject reason | reject | 422 `REJECTION_REASON_REQUIRED` |
| Approved amount ≤ submitted and > 0 | approve | 422 `APPROVED_AMOUNT_EXCEEDS_SUBMITTED` / `VALIDATION_ERROR` |
| Lower approved amount needs `adjustment_reason` | approve | 422 `ADJUSTMENT_REASON_REQUIRED` |

How the monthly limits are counted: the check adds `COALESCE(approved_amount, submitted_amount)` over the employee's claims with status `SUBMITTED`, `UNDER_REVIEW`, `APPROVED`, `SCHEDULED_FOR_PAYMENT` or `PAID`, for the same calendar month of the expense date, and checks that the total stays within the limit. Withdrawn, cancelled and rejected claims give their allowance back. Submission locks the employee row, so two simultaneous submissions cannot both slip under a limit.

Each adjustment is stored in three places: `adjustment_reason` on the claim, the `approved` history event (with `submitted_amount` and `approved_amount`), and the `expense.approved` audit row.

## Who can do what

Access is based on permissions only (see `PermissionRegistry`):

| Permission | Employee | Manager | HR | Admin |
|---|---|---|---|---|
| `expense.request`: own claims | ✓ | ✓ | ✓ | ✓ |
| `expense.view_team` / `expense.approve_team`: direct reports | | ✓ | ✓ | ✓ |
| `expense.view` / `expense.approve`: everyone | | | ✓ | ✓ |
| `expense.pay`: schedule and record payments | | | ✓ | ✓ |
| `expense.manage`: categories, policy, cancel | | | ✓ | ✓ |

- **No self-approval.** Nobody can review, approve, reject, schedule or pay their own claim. This holds even with `expense.approve` / `expense.pay`.
- **Manager scope.** A manager reaches only their direct reports, through `AccessScope` and the current assignment's `manager_id`. With `approval_route = HR_ONLY`, team approvers cannot decide at all.
- **Drafts are private** to their author. Other users get a 404, even HR.
- **Ids are resolved inside the caller's visible set.** A foreign id returns 404 `NOT_FOUND`, identical to a missing one, and this includes the receipt download. The policy (`ExpenseRequestPolicy`) is checked again on the locked row inside each service call.

Notifications go through `NotificationService`. They are delivered after the transaction commits, and each one has a dedupe key, so a retried action never notifies twice.

- **Submitted:** the employee's manager, if they hold an approve permission and the route allows it. Otherwise every `expense.approve` holder except the claimant.
- **Approved:** the claimant. With the SEPARATE route, the `expense.pay` holders as well.
- **Rejected, scheduled, paid:** the claimant.
- **Cancelled by HR:** the claimant.

## Receipts

The receipt rules follow the document module:

- **Private disk.** `config/reimbursements.php` sets `disk` = `local`, i.e. `storage/app/private`.
- **Server-made path.** Files are stored at `receipts/{employeeId}/{40 random chars}`, with no extension and nothing from the client in the path.
- **Upload checks** (`ReceiptFileInspector`, reusing `FileNameSanitizer` / `InspectedFile`):
  - real upload, not empty
  - size ≤ the effective limit: `min(expense_policies.max_receipt_kb, reimbursements.max_kb_ceiling)`. PHP's `upload_max_filesize` and `post_max_size` must be at least that.
  - no executable or markup segment anywhere in the name (`x.php.pdf`, `x.pdf.php`, ...)
  - extension `pdf`/`jpg`/`jpeg`/`png`, with the MIME type sniffed from the content and matching the extension
  - PDF header or image structure verified
  - no `<?php` tag anywhere in the file
- **Never exposed.** `receipt_disk` / `receipt_path` are hidden from serialization and kept out of the audit trail.
- **Authorized streaming only.** `GET /reimbursements/{id}/receipt` sends the file as an attachment with `nosniff`, `no-store` and a sandbox CSP.
- **Replacement keeps history.** A draft's receipt can be replaced. The old file stays on disk and its sha256 is recorded in the `updated` event.

## Payment

`ReimbursementPaymentService::pay()` takes a required idempotency key (body `idempotency_key` or header `Idempotency-Key`) and works as follows:

1. Lock the claim `FOR UPDATE`.
2. If the key exists for the same claim, return the stored payment (`meta.idempotent_replay = true`). If it exists for another claim, return 409 `IDEMPOTENCY_KEY_REUSED`.
3. If the claim is already paid, return 409 `ALREADY_PAID`. Otherwise the state machine requires `SCHEDULED_FOR_PAYMENT`.
4. Refuse PAYROLL-routed claims (409 `PAYMENT_ROUTE_PAYROLL`).
5. Insert the payment row. The UNIQUE constraints on `expense_request_id` and `idempotency_key` are the final backstop.

The real-process tests prove the result under concurrency: four simultaneous payers produce exactly one payment, one event, one audit row and one notification.

## Payroll route

`payment_route` is copied from the company policy **at approval** onto the claim. A later policy change therefore never re-routes approved claims.

- **SEPARATE claims** never appear in payroll. `PayrollRouting` ignores them, and `markIncluded` refuses them with 409 `PAYMENT_ROUTE_SEPARATE`.
- **PAYROLL claims** cannot be scheduled or paid separately (409 `PAYMENT_ROUTE_PAYROLL`).

`App\Services\Reimbursements\PayrollRouting` is the hook for the payroll generator. **It is not wired into payroll yet.**

| Method | Behaviour |
|---|---|
| `eligibleForPayroll(Employee, start, end): Collection` | Returns claims with route `PAYROLL`, status `APPROVED`/`SCHEDULED_FOR_PAYMENT`, no payroll line, no payment, approved on or before `end` (end of day, company time). `start` is intentionally not a lower bound, so late approvals roll into the next run. |
| `markIncluded(request, lineId, periodId)` | Idempotent for the same line. Refuses another line (`PAYROLL_ALREADY_INCLUDED`), a paid claim (`ALREADY_PAID_SEPARATELY`), a SEPARATE claim and any other status. Moves `APPROVED` to `SCHEDULED_FOR_PAYMENT`. `payroll_item_line_id` is UNIQUE, so one line carries at most one claim. |
| `settlePeriod(periodId, actor)` | When the payroll period is paid, each included claim gets a `PAYROLL` payment (key `payroll:{id}`) and becomes `PAID`. Safe to call repeatedly. |

The FKs to `payroll_item_lines` / `payroll_periods` use `nullOnDelete`. If the generator deletes and rebuilds draft lines, the claim simply becomes eligible again. Use the approved amount as the earning line amount.

## Reports

Five reports are registered in `ReportService::DEFINITIONS`, under group `reimbursements`:

- `reimbursements.requests`
- `reimbursements.by_employee`
- `reimbursements.by_department`
- `reimbursements.by_category`
- `reimbursements.by_status`

To run them, a user needs `report.view` plus `expense.view` (everyone) or `expense.view_team` (direct reports only).

- **Window:** by expense date.
- **Drafts:** never included.
- **Totals:** claims, submitted, approved (`APPROVED`/`SCHEDULED`/`PAID`) and paid.
- **Exports:** CSV/PDF through the existing export endpoint.

Known limitation: the web admin's employee filter dropdown (`ReportDesk::options`) uses attendance scope for non-leave groups. The data itself is always scoped by `expense.*`.

## Demo data

`ReimbursementDemoSeeder` is listed in `DatabaseSeeder::DEMO_MODULES`. It always installs the six default categories and the policy row. If the demo company has no claims yet, it also creates claims for Michael Carter (`employee@peopleos.test`) in every stage, plus one submitted claim for Olivia. All of them go through the real services, with real PDF receipts on the private disk.

## Tests

The tests live in `tests/Feature/Reimbursements/*` and run against real MySQL:

- `ReimbursementWorkflowTest`: roles, scope, IDOR, self-approval, the full invalid-transition matrix, rules, payment idempotency, append-only, audit, notifications, numbering
- `ReceiptUploadTest`: hardening, size, traversal, streaming
- `ReimbursementPayrollAndReportsTest`
- `ReimbursementConcurrencyTest`: real processes through `tests/Support/concurrent_reimbursement.php`
