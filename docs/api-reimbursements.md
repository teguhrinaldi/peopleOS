# Reimbursements API (v1)

Base path: `/api/v1`. All endpoints need a Sanctum bearer token and pass the `active` middleware. Responses use the standard envelope:

- Success: `{"success":true,"data":…,"meta":…}`
- Error: `{"success":false,"error":{"code","message","details"}}`

Business rules are described in [reimbursements.md](reimbursements.md).

Conventions:
- **Ids are plain integers resolved inside the caller's visible set.** Someone else's claim, someone else's draft, and an id that does not exist all return the same **404 `NOT_FOUND`**.
- **Amounts are decimal strings** with 2 decimals, e.g. `"150000.00"`. Requests accept numbers or strings with at most 2 decimals.
- **Dates** are `Y-m-d` in company time. Timestamps are ISO-8601.
- **Invalid lifecycle actions** return **409 `INVALID_TRANSITION`** with `details.status`.
- **Permission failures** return **403 `FORBIDDEN`**.

## Setup

| Method | Path | Who | Notes |
|---|---|---|---|
| GET | `/expense-categories` | any `expense.*` | Active categories. `?include_inactive=1` works only with `expense.manage`. |
| POST | `/expense-categories` | `expense.manage` | `code` (A-Z0-9_-, upper-cased, unique), `name`, `description?`, `is_active?`, `receipt_required?`, `max_amount_per_claim?`, `max_amount_per_month?`, `max_age_days?`, `sort_order?` |
| PATCH | `/expense-categories/{id}` | `expense.manage` | Any subset of the fields above. |
| GET | `/expense-policy` | any `expense.*` | `approval_route`, `payment_route`, company limits, `max_receipt_kb`, `effective_max_receipt_kb`, `allowed_receipt_types` |
| PUT | `/expense-policy` | `expense.manage` | `approval_route` (`MANAGER_OR_HR` or `HR_ONLY`), `payment_route` (`SEPARATE` or `PAYROLL`), `max_amount_per_claim?`, `max_amount_per_month?`, `max_receipt_kb` (50–102400) |

Category object:
```json
{"id":1,"code":"TRANSPORT","name":"Transport","description":null,"is_active":true,"receipt_required":true,
 "max_amount_per_claim":"1500000.00","max_amount_per_month":"3000000.00","max_age_days":60}
```

## Claims

| Method | Path | Who | Body |
|---|---|---|---|
| GET | `/reimbursements` | see `scope` | Query: `scope` = `mine` (default) \| `review` \| `pay` \| `all`, `status`, `category_id`, `employee_id`, `from`, `to` (expense date), `q` (request number), `page`, `per_page` ≤ 100 |
| POST | `/reimbursements` | `expense.request` (own) | multipart or JSON: `expense_category_id`, `expense_date`, `submitted_amount`, `business_purpose`, `description?`, `currency?` (must be the company currency), `receipt?` (file), `submit?` (bool, default **false**, which saves a DRAFT). Returns **201**. |
| GET | `/reimbursements/{id}` | visible | |
| PATCH | `/reimbursements/{id}` | owner, DRAFT only | Any subset of the create fields. An optional `receipt` replaces the current one. |
| POST | `/reimbursements/{id}/receipt` | owner, DRAFT only | multipart `receipt` |
| GET | `/reimbursements/{id}/receipt` | visible | Streams the file as an attachment (`nosniff`, `no-store`, sandbox CSP, `X-Receipt-Sha256`). 404 if there is no receipt. |
| GET | `/reimbursements/{id}/history` | visible | `{"events":[…], "payment":{…}\|null}` |
| POST | `/reimbursements/{id}/submit` | owner | DRAFT → SUBMITTED. Runs every rule (receipt, limits, age). |
| POST | `/reimbursements/{id}/review` | approver in scope, not self | SUBMITTED → UNDER_REVIEW |
| POST | `/reimbursements/{id}/approve` | approver in scope, not self | `approved_amount?` (≤ submitted, defaults to submitted), `adjustment_reason` (required when lower), `note?`. UNDER_REVIEW → APPROVED |
| POST | `/reimbursements/{id}/reject` | approver in scope, not self | `reason` (required). UNDER_REVIEW → REJECTED |
| POST | `/reimbursements/{id}/withdraw` | owner | `reason?`. Allowed from DRAFT, SUBMITTED or UNDER_REVIEW → CANCELLED |
| POST | `/reimbursements/{id}/cancel` | `expense.manage` | `reason` (required). Any non-terminal, non-draft claim → CANCELLED. 409 once the claim is paid or in payroll. |
| POST | `/reimbursements/{id}/schedule` | `expense.pay`, not self | `scheduled_for?` (date). APPROVED → SCHEDULED_FOR_PAYMENT. Only for claims on the SEPARATE route. |
| POST | `/reimbursements/{id}/pay` | `expense.pay`, not self | `idempotency_key` (or header `Idempotency-Key`), `method` (`BANK_TRANSFER` \| `CASH` \| `E_WALLET` \| `OTHER`), `reference?`, `paid_at?`, `note?`. SCHEDULED_FOR_PAYMENT → PAID. A replay returns 200 with `meta.idempotent_replay: true`. |

What each `scope` value returns:

| `scope` | Claims returned | Required permission | Drafts |
|---|---|---|---|
| `mine` | The caller's own claims | none extra | Included |
| `review` | Non-draft claims the caller can decide (never their own) | `expense.approve` or `expense.approve_team` | Excluded |
| `pay` | APPROVED, SCHEDULED or PAID claims of others | `expense.pay` | Excluded |
| `all` | Everything visible to the caller | any `expense.*` view, approve, pay or manage permission | Only the caller's own |

Claim object:
```json
{
  "id": 12, "request_number": "RB-202610-0007", "status": "UNDER_REVIEW",
  "employee": {"id": 5, "full_name": "Michael Carter", "employee_number": "EMP-0012"},
  "category": {"id": 1, "code": "TRANSPORT", "name": "Transport"},
  "expense_date": "2026-10-08", "currency": "IDR",
  "submitted_amount": "420000.00", "approved_amount": null,
  "business_purpose": "Airport transfer", "description": null,
  "receipt": {"name": "receipt.pdf", "extension": "pdf", "mime_type": "application/pdf", "size_bytes": 18211,
              "sha256": "…", "download_url": "/api/v1/reimbursements/12/receipt"},
  "submitted_at": "…", "review_started_at": "…", "reviewed_at": null, "reviewer": null, "review_note": null,
  "rejection_reason": null, "adjustment_reason": null, "cancelled_at": null, "cancel_reason": null,
  "payment_route": null, "scheduled_for": null, "paid_at": null, "payment": null, "payroll": null,
  "actions": {"can_edit": false, "can_submit": false, "can_withdraw": true, "can_start_review": false,
              "can_approve": false, "can_reject": false, "can_schedule": false, "can_pay": false, "can_cancel": false},
  "created_at": "…", "updated_at": "…"
}
```

`actions` shows what the **current viewer** may do right now. Clients should drive their buttons from it instead of re-implementing the rules. The server still enforces every rule.

`payment`, once the claim is paid: `{"id","amount","currency","method","reference","paid_at","paid_by","note","idempotency_key"}`.

History event: `{"id","action","from_status","to_status","actor":{"id","name"},"note","data","at"}`.

## Error codes

| Code | Status | When |
|---|---|---|
| `NOT_FOUND` | 404 | Unknown or invisible id, or a missing receipt |
| `FORBIDDEN` | 403 | Missing permission, self-approval or self-payment, or editing someone else's claim |
| `INVALID_TRANSITION` | 409 | The action is not allowed from the current status |
| `ALREADY_PAID` | 409 | The claim already has a payment |
| `IDEMPOTENCY_KEY_REUSED` | 409 | The key was used for another claim |
| `PAYMENT_ROUTE_PAYROLL` | 409 | Schedule or pay was called on a payroll-routed claim |
| `EXPENSE_ALREADY_SETTLED` | 409 | Cancel was called after payment or payroll inclusion |
| `VALIDATION_ERROR` | 422 | Field validation failed |
| `REJECTION_REASON_REQUIRED` / `CANCEL_REASON_REQUIRED` | 422 | The reason is missing |
| `APPROVED_AMOUNT_EXCEEDS_SUBMITTED` / `ADJUSTMENT_REASON_REQUIRED` | 422 | An approved-amount rule failed |
| `RECEIPT_REQUIRED`, `EXPENSE_LIMIT_EXCEEDED`, `EXPENSE_MONTHLY_LIMIT_EXCEEDED`, `EXPENSE_TOO_OLD`, `EXPENSE_DATE_IN_FUTURE` | 422 | A submission rule failed (`details` carries the limit or scope) |
| `EXPENSE_CATEGORY_UNAVAILABLE`, `CURRENCY_NOT_SUPPORTED` | 422 | The category is inactive or unknown, or the currency is wrong |
| `UNSUPPORTED_FILE_TYPE` | 422 | The receipt type, content or name was refused |
| `EMPLOYEE_NOT_ACTIVE` | 403 | The claimant is not an active employee |

## Reports

Reports use the existing endpoints: `GET /reports/{key}` and `GET /reports/{key}/export/{csv|pdf}`.

- Keys: `reimbursements.requests`, `reimbursements.by_employee`, `reimbursements.by_department`, `reimbursements.by_category`, `reimbursements.by_status`
- Filters: `from`, `to` (expense date), `department_id`, `employee_id`, `status`
- Required permissions: `report.view` plus `expense.view`, or `expense.view_team` (direct reports only)
