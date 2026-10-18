# Documents

Employee and company documents (contracts, identification references, certificates, handbooks …) with
expiry tracking. This document is the source of truth for the module's rules. API contract: [api-documents.md](api-documents.md).

## Concepts

| Concept | Table | Purpose |
|---|---|---|
| Document type | `document_types` | Configurable catalogue: `requires_expiry`, `default_visible_to_employee`, `allow_employee_documents`, `allow_company_documents`, `is_active`, `sort_order` |
| Document | `documents` | Metadata of one file: owner (`employee_id`, **null = company-wide**), type, title, expiry date, status, visibility, notes, uploader, archive info. Soft-deleted |

The binary is **never** stored in MySQL. `documents` keeps `file_disk`, `file_path` (hidden from every serialization, never in JSON or audit rows), `file_extension`, `original_name` (sanitised, display/download only), `mime_type` (detected from content), `size_bytes`, `sha256`.

Default types (installed by `DocumentTypeSeeder`, idempotent, safe for production: `php artisan db:seed --class=DocumentTypeSeeder`):

| Code | Name | Expiry required | Visible by default | Employee docs | Company docs |
|---|---|---|---|---|---|
| `EMPLOYMENT_CONTRACT` | Employment Contract | no | yes | yes | no |
| `IDENTIFICATION` | Identification Reference | **yes** | yes | yes | no |
| `CERTIFICATE` | Certificate | no | yes | yes | no |
| `PAYSLIP` | Payslip | no | yes | yes | no |
| `COMPANY_DOCUMENT` | Company Document | no | yes | no | yes |
| `OTHER` | Other | no | **no (HR-only)** | yes | yes |

Types are edited (or retired with `is_active = false`) through `DocumentTypeService` / `POST|PATCH /document-types`. A retired type keeps its documents but cannot be used for new uploads (`DOCUMENT_TYPE_INACTIVE`). Payslip PDFs are produced by the Payroll module; this module does not generate them and has no payroll coupling.

## State machine

```
            expiry passes / edits (derived, automatic)
   ACTIVE  <──────────────>  EXPIRING  <──────────────>  EXPIRED
      │                          │                          │
      └──────────── archive (manual, document.manage) ──────┘
                                 ▼
                             ARCHIVED ── restore ──► derived state for TODAY
   soft delete: allowed from any state; a deleted document is never listed, shown or downloaded
```

Derived status (pure `DocumentStatusCalculator`, dates are business dates in the **company timezone**, N = setting `documents.expiring_days`, default 30):

| Condition | Status |
|---|---|
| archived (`archived_at` set) | `ARCHIVED` (wins over everything) |
| no expiry date | `ACTIVE` |
| `expiry_date < today` | `EXPIRED` — the expiry date itself is the last valid day |
| `today ≤ expiry_date ≤ today + N` | `EXPIRING` (inclusive at both ends) |
| `expiry_date > today + N` | `ACTIVE` |

Rules:

* `ARCHIVED` is entered only by `archive()` and left only by `restore()`, which returns to the derived state for *today* (an archived document whose date passed restores as `EXPIRED`). Archiving twice → `409 DOCUMENT_ALREADY_ARCHIVED`; restoring a non-archived document → `409 DOCUMENT_NOT_ARCHIVED`; editing an archived document → `409 DOCUMENT_ARCHIVED` (restore first).
* The job never touches archived or deleted rows, so an archived document can never become "expired".
* API responses and list filters compute status from the expiry date live (same rules), so they are correct even between job runs; the stored `status` column is kept in sync for reporting and notifications by:
  * `DocumentService` on create / update / archive / restore;
  * the hourly command **`documents:refresh-status`** (`routes/schedule/documents.php`, `withoutOverlapping`).
* The uploaded file is immutable. To replace a file, upload a new document and archive the old one.
* Moving a document to another employee is not supported (delete and re-upload).

## Notifications

`documents:refresh-status` → `DocumentService::refreshStatuses()`:

1. Four conditional bulk `UPDATE`s align the stored status with today (only rows that differ; archived/deleted rows excluded).
2. Every non-archived `EXPIRING`/`EXPIRED` document whose `notified_status` differs from its status is processed in its own transaction **with the row locked**: re-checked, notified, then `notified_status = status`.

Recipients (through `NotificationService` only): the employee — only when the document is `visible_to_employee` — and every active user holding `document.manage`. Company documents notify HR only. Documents of terminated/deleted employees do not notify.

| Type | Title | Body example |
|---|---|---|
| `document.expiring` | Document expiring | "Your National ID Card expires on 2026-10-12." / "Michael Carter: National ID Card expires on 2026-10-12." |
| `document.expired` | Document expired | "Your First Aid Certificate expired on 2026-08-21. Please provide an updated copy." |

`data = { document_id, status, expiry_date }`, never a path. `dedupe_key = document:{id}:{STATUS}:{expiry_date}:u{userId}`. Each person is notified once per document, state and expiry date. Changing the expiry date resets `notified_status`, so a renewed document that later approaches expiry notifies again.

### Idempotency and concurrency

* Running the command twice notifies once (`notified_status` + unique `dedupe_key`).
* Concurrent runs (scheduler on two servers, a manual run during the hourly one) are safe: each bulk `UPDATE` re-evaluates its `WHERE` on the locked latest row version, the notification step locks each row and re-checks it, and the unique `dedupe_key` is the last line of defence. Proven by `DocumentConcurrencyTest` (4 real PHP processes racing → every recipient notified exactly once, every stale status fixed exactly once).

## Authorization

Permission-based (`PermissionRegistry`, no new permissions were added):

| Permission | Grants |
|---|---|
| `document.view` | every document of the company (incl. HR-only and archived) |
| `document.manage` | upload, update metadata, archive, restore, delete, manage types — and implies `document.view` |
| `document.view_own` | own documents with `visible_to_employee = true` + company documents with `visible_to_employee = true`; never archived ones |

* Managers get **nothing** for their team; team relationships grant no document access.
* `DocumentPolicy` runs on the loaded record for show/download; `DocumentQuery` expresses the same rules in SQL for lists, so a list and a detail can never disagree.
* A document the caller may not see answers **404 `NOT_FOUND`**, byte-for-byte identical to a non-existent id (no existence leak). Callers with no document permission at all get 403. Management endpoints check `document.manage` *before* loading the record (403 regardless of whether the id exists).
* `employee_id` from the client is only trusted after proving the employee exists in the company; `company_id` is never accepted from the client.

## Storage security

* Private disk (`config/documents.php`: `disk = local` → `storage/app/private`), path `documents/{employeeId|company}/{40 random chars}` — **no extension and nothing from the client**. Never publicly served: no `/storage/...` URL, no signed URL is ever generated.
* Streamed only by `GET /api/v1/documents/{id}/download` after the policy: `Content-Disposition: attachment; filename="<ASCII-safe name>.<stored extension>"`, `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`, `X-Document-Sha256`.
* Upload validation (`DocumentFileInspector`), nothing is written before all checks pass:
  1. a valid upload, not empty, ≤ `documents.max_kb` (default 10 240 KB = 10 MB; PHP `upload_max_filesize`/`post_max_size` must be at least this);
  2. no null bytes and no executable/markup segment anywhere in the name (`x.pdf.php`, `x.php.pdf`, `.svg`, `.html`, `.exe`, …);
  3. extension in the allow-list: `pdf, jpg, jpeg, png, docx, xlsx`;
  4. MIME detected **from content** (`finfo`) must match the extension (a PHP script renamed `.pdf` is `text/x-php` → rejected);
  5. structure: PDF header, real JPEG/PNG image header, OOXML package with `[Content_Types].xml` + `word/document.xml` / `xl/workbook.xml`, no macro (`vbaProject.bin`) or traversal entries;
  6. no `<?php` anywhere in the file (polyglots).
  Failures: `422 UNSUPPORTED_FILE_TYPE` (or `VALIDATION_ERROR` for size/empty), `details.file[]`.
* The original name is sanitised (directories, `..`, control characters and odd symbols removed) and used only for display and the download name. The stored MIME is the canonical type for the validated extension.
* If the database write fails after the file was stored, the file is deleted (compensation).

## Audit

`ActivityLogger` rows: `document.uploaded`, `document.updated` (changed fields only), `document.archived`, `document.restored`, `document.deleted`, `document_type.created`, `document_type.updated`. Values are restricted to an allow-list (title, owner, type, expiry, status, visibility, notes, original name, MIME, size, sha256, archive info) — **never `file_path`/`file_disk`**.

## Retention

Delete is a **soft delete** (`deleted_at`, `deleted_by`); the file stays on the private disk. A purge command (physically removing soft-deleted files after a retention period) is **out of scope** for this version; operators who need it should delete both the row and `file_path` together.

## Demo data

`DocumentDemoSeeder` (run automatically after `DemoSeeder`) generates real small PDFs with dompdf (`resources/views/documents/pdf/demo.blade.php`, company name, title, "DEMO DOCUMENT" watermark) for: employment contracts for most employees, identification references, certificates, HR-only notes, and company documents (Employee Handbook, Code of Conduct, Holiday Calendar {year}, plus two HR-only company documents). Dates are relative to the seed date, so there are always EXPIRING, EXPIRED and one ARCHIVED document. `employee@peopleos.test` (Michael Carter) has a visible contract, a visible ID reference expiring in 12 days, a visible certificate and an HR-only "Reference Check Notes" he cannot see. The seeder finishes by running `refreshStatuses()` so the expiry notifications exist. It is idempotent.

## Service API (for the Web Admin)

* `App\Services\Documents\DocumentService`: `upload(User, UploadedFile, array)`, `update(User, Document, array)`, `archive(User, Document, ?reason)`, `restore(User, Document)`, `delete(User, Document)`, `refreshStatuses()`, `statusFor(Document)`, `daysUntilExpiry(Document)`.
* `App\Services\Documents\DocumentQuery`: `all()`, `selfService(User)`, `visibleTo(User)`, `findVisible(User, id)`, `filter(Builder, filters)`, `whereStatus()`, `whereExpiringWithin()`.
* `App\Services\Documents\DocumentStorage::download(Document)` — call only after `Gate::authorize('download', $document)`.
* `App\Services\Documents\DocumentTypeService`: `list()`, `create()`, `update()`, `installDefaults()`.
* `App\Policies\DocumentPolicy` (auto-discovered): `viewAny`, `viewAll`, `view`, `download`, `create`, `update`, `archive`, `restore`, `delete`.
