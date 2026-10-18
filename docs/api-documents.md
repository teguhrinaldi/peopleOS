# Documents API (v1)

Base path `/api/v1` · Sanctum bearer token · same envelope, time format and error rules as [api.md](api.md). Business rules: [documents.md](documents.md).

## Document object

```jsonc
{
  "id": 41,
  "title": "National ID Card (reference copy)",
  "document_type": { "id": 2, "code": "IDENTIFICATION", "name": "Identification Reference" },
  "employee": { "id": 6, "full_name": "Michael Carter", "employee_number": "EMP-0012" },   // null for company documents
  "is_company_document": false,
  "status": "EXPIRING",                 // ACTIVE | EXPIRING | EXPIRED | ARCHIVED (computed for today, company timezone)
  "expiry_date": "2026-10-12",          // null = no expiry
  "days_until_expiry": 12,              // null without expiry; 0 = last valid day; negative = expired
  "file": { "name": "National_ID_Card_reference_copy.pdf", "extension": "pdf", "mime_type": "application/pdf",
            "size_bytes": 2154, "sha256": "9f2c…" },
  "download_url": "/api/v1/documents/41/download",
  "uploaded_at": "2026-05-02T09:14:00+07:00",
  "updated_at": "2026-05-02T09:14:00+07:00",

  // only for callers holding document.view or document.manage:
  "visible_to_employee": true, "notes": null,
  "uploaded_by": { "id": 2, "name": "Sarah Wilson" },
  "archived_at": null, "archived_by": null, "archive_reason": null
}
```

No storage path is ever returned. Download the file only through `download_url` (send the bearer header).

## Document type object

`{ "id", "code", "name", "description", "requires_expiry" }` — managers (`document.manage`) also get
`default_visible_to_employee`, `allow_employee_documents`, `allow_company_documents`, `is_active`, `sort_order`.

## Error codes (module)

| HTTP | `error.code` | When |
|---|---|---|
| 403 | `FORBIDDEN` | no document permission at all; `scope=all` without `document.view`/`document.manage`; any write without `document.manage` |
| 403 | `NO_EMPLOYEE_PROFILE` | self-service (`scope=mine`) for an account without an employee |
| 404 | `NOT_FOUND` | unknown id **or** a document the caller may not see (identical answer) or a deleted one |
| 404 | `FILE_MISSING` | the record exists but its file is missing on the disk |
| 409 | `DOCUMENT_ALREADY_ARCHIVED` `DOCUMENT_NOT_ARCHIVED` `DOCUMENT_ARCHIVED` `DOCUMENT_TYPE_CODE_TAKEN` | state conflict |
| 422 | `VALIDATION_ERROR` | invalid input (`details.<field>[]`), incl. file too large/empty, unknown employee or type |
| 422 | `UNSUPPORTED_FILE_TYPE` | extension not allowed, content does not match extension, dangerous name, invalid structure, embedded PHP (`details.file[]`) |
| 422 | `EXPIRY_DATE_REQUIRED` `DOCUMENT_TYPE_NOT_ALLOWED` `DOCUMENT_TYPE_INACTIVE` | document type rules |

---

## `GET /document-types`
Auth: any of `document.view`, `document.manage`, `document.view_own` (else `403`).
Query: `include_inactive=1` (managers only). Response: `data: [DocumentType]` ordered by `sort_order`, name.

## `POST /document-types` · `PATCH /document-types/{id}`
Permission: `document.manage`.

| Field | Rules |
|---|---|
| `name` | required on create, ≤ 100 |
| `code` | optional on create (derived from name, upper snake case), ≤ 40, **prohibited on update** |
| `description` | nullable, ≤ 255 |
| `requires_expiry`, `default_visible_to_employee`, `allow_employee_documents`, `allow_company_documents`, `is_active` | boolean (at least one of the two `allow_*` must stay true) |
| `sort_order` | integer 0–65000 |

`201` / `200` → DocumentType (full). Errors: `409 DOCUMENT_TYPE_CODE_TAKEN`, `422 VALIDATION_ERROR`, `404 NOT_FOUND`.

## `GET /documents`
Auth: any document permission.

| Query | Notes |
|---|---|
| `scope` | `mine` (default): own documents visible to the employee + company documents visible to all, never archived; needs `document.view_own` and an employee profile. `all`: every document; needs `document.view` or `document.manage` |
| `employee_id` | narrows only (with `scope=mine` another person's id simply returns nothing) |
| `document_type_id` | |
| `status` | `ACTIVE` `EXPIRING` `EXPIRED` `ARCHIVED` (case-insensitive), computed from dates for today |
| `expiring_within_days` | 0–366: not archived, expiring between today and today + N (expired excluded) |
| `company_only=1` / `employee_only=1` | |
| `q` | title / file name contains (≤ 100) |
| `sort` | `recent` (default, newest upload first) · `expiry` (soonest first, no expiry last) · `title` |
| `page`, `per_page` | default 20, max 100 |

`200` → `data: [Document]`, `meta: { page, per_page, total, last_page }`.

Examples: employee app `GET /documents?status=expiring` · HR dashboard `GET /documents?scope=all&expiring_within_days=30&sort=expiry`.

## `GET /documents/{id}`
Policy on the loaded record. `200` → Document. `404 NOT_FOUND` when unknown, deleted or not visible to the caller.

## `GET /documents/{id}/download`
Same authorization as show. Streams the file:

```
200 OK
Content-Type: application/pdf
Content-Length: 2154
Content-Disposition: attachment; filename="National ID Card (reference copy).pdf"
Cache-Control: max-age=0, no-store, private
X-Content-Type-Options: nosniff
Content-Security-Policy: default-src 'none'; sandbox
X-Document-Sha256: 9f2c…
```

The file name is ASCII-only and always ends with the validated extension. Errors are JSON (`404 NOT_FOUND`, `404 FILE_MISSING`, `403 FORBIDDEN`).

## `POST /documents` (multipart/form-data)
Permission: `document.manage`.

| Field | Rules |
|---|---|
| `file` | **required**; ≤ `documents.max_kb` (default 10 MB); `pdf`, `jpg/jpeg`, `png`, `docx`, `xlsx`, verified by content |
| `document_type_id` | required, active type of the company |
| `employee_id` | nullable; omitted/null = company-wide document. Must exist in the company; the type must allow it |
| `title` | required, ≤ 150 |
| `expiry_date` | `Y-m-d`, nullable; required when the type `requires_expiry` |
| `visible_to_employee` | boolean; default = the type's `default_visible_to_employee` (for company documents: visible to all employees) |
| `notes` | nullable, ≤ 1000 (HR-only field) |

`201` → Document. Nothing is written to disk when any check fails.

## `PATCH /documents/{id}`
Permission: `document.manage`. Only present keys change: `title`, `document_type_id`, `expiry_date` (null clears it unless the type requires one), `visible_to_employee`, `notes`. The file cannot be replaced. Status is recomputed; a new expiry date restarts reminders.
`200` → Document. Errors: `404`, `409 DOCUMENT_ARCHIVED`, `422 EXPIRY_DATE_REQUIRED|DOCUMENT_TYPE_NOT_ALLOWED|DOCUMENT_TYPE_INACTIVE|VALIDATION_ERROR`.

## `POST /documents/{id}/archive`
Permission: `document.manage`. Body `{ "reason?": "≤ 500" }`. `200` → Document (`status: ARCHIVED`). `409 DOCUMENT_ALREADY_ARCHIVED`.

## `POST /documents/{id}/restore`
Permission: `document.manage`. `200` → Document with the derived status for today. `409 DOCUMENT_NOT_ARCHIVED`.

## `DELETE /documents/{id}`
Permission: `document.manage`. Soft delete; the file is retained. `200` → `{ "id": 41, "deleted": true }`. Afterwards the id answers `404` everywhere.

## Notifications produced
`document.expiring` ("Document expiring") and `document.expired` ("Document expired"), `entity_type = App\Models\Document`, `data = { document_id, status, expiry_date }` — see [documents.md](documents.md#notifications). They appear in `GET /notifications`.
