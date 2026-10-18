# Security

## Authentication

* **Employee app → API:** Laravel Sanctum personal access tokens (bearer). Tokens expire (`SANCTUM_TOKEN_EXPIRATION_MINUTES`, default 30 days), are revoked on logout, and are all revoked when an employee is terminated.
* **Admin / HR / Manager → Web Admin:** session login. Requires the `admin.access` permission; the employee role does not have it.
* Login is rate-limited (5 failed attempts per email+IP per minute, 20 requests per IP per minute) and returns the same message for an unknown email and a wrong password.
* Disabled accounts and terminated employees are refused at login **and** on every request that carries an already-issued token (`active` middleware).

## Authorization (permission-based)

* Permissions live in `PermissionRegistry`; roles (`admin`, `hr`, `manager`, `employee`) are bundles. Code never tests role names. Custom roles can be composed from the same permissions.
* A **Manager** gets team-scoped permissions (`employee.view_team`, `attendance.view_team`, `attendance.approve_team`, `overtime.approve_team`) and does *not* inherit HR permissions (asserted by tests).
* Every request is checked in four layers: authentication → permission → **resource ownership/scope** (`AccessScope`, policies) → organization (single company enforced by queries).

### IDOR protection

The client-supplied `employee_id`, `attendance_id`, `correction id`, `overtime id`, `event id` are never trusted:

* The acting employee is always derived from the token (`$request->user()->employee`), never from the body.
* List endpoints intersect any `employee_id` filter with the caller's allowed set.
* Show/approve/cancel endpoints run a policy against the loaded record (`403 FORBIDDEN` otherwise).
* Reviewers cannot decide their own requests, even when they hold the approval permission.
* Covered by `AuthorizationTest`, `CorrectionTest`, `OvertimeTest`.

## Input handling

* All writes go through Form Requests / explicit validation; `$request->all()` is not used for writes.
* Selfie uploads: extension **and** MIME validated (JPEG/PNG), size ≤ 5 MB, stored under a random name on a private disk; DB stores path + metadata only.
* Selfies are streamed only after a policy check with `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff`; the storage path never appears in JSON.
* Server time and server-side geofence distance are authoritative; mock locations are rejected by policy.

## Data integrity

Unique constraints + transactions + `FOR UPDATE` + idempotency keys protect against duplicate/concurrent attendance (see [attendance.md](attendance.md)). Foreign keys are restrictive so history cannot be destroyed by accident.

## Audit trail

`activities` records actor, action, entity, old/new values, IP and user agent for login/logout, employee lifecycle, attendance events, corrections, overtime and reviews. Passwords, tokens and selfie paths are redacted; the table is append-only in application code.

## Privacy

* GPS is captured **only** at check-in/out. No background tracking, no continuous location.
* Selfies are evidence and are access-controlled like any other private record.

## Errors

Machine-readable codes, never stack traces: outside `APP_DEBUG` unexpected exceptions become `500 SERVER_ERROR`.

## Deployment checklist

* `APP_ENV=production`, `APP_DEBUG=false`, unique `APP_KEY`, HTTPS only (Android release builds should not allow cleartext).
* Dedicated MySQL user (not root) with only the `peopleos` schema.
* Change/remove all demo accounts (`db:seed --class=RolesAndPermissionsSeeder` installs roles without demo data).
* Keep `storage/app/private` outside the web root; run `php artisan storage:link` only for the *public* disk (employee photos).
* Back up the database and `storage/app`.
* CORS: Laravel's default allows any origin on `api/*` (needed for the Flutter web build in development). In production run `php artisan config:publish cors` and set `allowed_origins` to the domain that serves the web app. Mobile apps are not affected by CORS.

## Known limitations

* No 2FA, password reset by email or SSO in V1.
* The web admin's forgotten-password flow is not implemented.
* Rate limits use the default cache store (use Redis in production for multi-server).
