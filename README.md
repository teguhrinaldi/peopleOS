# PeopleOS

**Manage people. Simplify work.** A workforce & HR management system delivered as **source code** you host yourself: a Flutter employee app, a Laravel REST API and web admin, on MySQL 8.

```
PEOPLEOS/
  app/         Flutter employee app (Demo mode needs no backend)
  BE/          Laravel 13: REST API v1 + Web Admin + migrations/seeders
  docs/        architecture, database, api, security, deployment, ...
  marketing/   product video and thumbnails
  scripts/     local development helpers
```

Workflow: **Employee → Attendance → Leave → Approval → Payroll → Documents → Reporting**

| Module | Highlights |
|---|---|
| People | Employees with dated assignments (department, position, manager, location, shift), lifecycle, org structure |
| Attendance | GPS + server-side geofence, optional selfie, overnight shifts, offline sync with idempotency, corrections, overtime, immutable daily schedule snapshot |
| Leave | Configurable types and policies, balances (allowance − used − reserved), half days, approval flow, race-proof |
| Payroll | Configurable salary components, effective-dated salaries, calculation engine, review → approve → finalize → paid, immutable after finalization, PDF payslips |
| Documents | Private storage, upload hardening, expiry tracking and alerts |
| Notifications | In-app, read/unread, extensible to push/email |
| Reports | Attendance, leave, payroll, documents with CSV and PDF export |
| Security | Permission-based roles, scope-aware access, audit trail, IDOR-tested |

> PeopleOS ships **no tax, social-security or statutory payroll rules**. You configure payroll rules for your own jurisdiction ([docs/payroll.md](docs/payroll.md)). It makes no compliance claim.

## Quick start (about 15 minutes)

Requirements: PHP 8.3+, Composer 2, MySQL 8+, Node 20+, Flutter 3.4x. Details: [docs/development.md](docs/development.md).

```bash
# 1. Database
mysql -u root -p -e "CREATE DATABASE peopleos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Backend
cd BE
composer install
cp .env.example .env && php artisan key:generate     # set DB_* in .env
php artisan migrate --seed                            # roles + permissions + DEMO company
php artisan storage:link
npm install && npm run build                          # web admin assets
php artisan serve                                     # http://127.0.0.1:8000  (web admin: /login)

# 3. Flutter app
cd ../app
flutter pub get
flutter run                                           # Demo mode: sample data on the device, no server
flutter run --dart-define=PEOPLEOS_USE_API=true       # API mode: enter your server URL on the login screen
```

Android emulator → `http://10.0.2.2:8000`; iOS simulator → `http://127.0.0.1:8000`; a real phone → your computer's LAN IP or an HTTPS domain.

**Production:** do not seed the demo company. Follow [docs/deployment.md](docs/deployment.md) (HTTPS, private storage, scheduler, security checklist).

## Demo logins

`php artisan migrate --seed` creates a demo company. Every demo password is `password`: **never use them in production**.

| Email | Role | Use it to |
|---|---|---|
| admin@peopleos.test | Admin | everything in the web admin, approve payroll, settings |
| hr@peopleos.test | HR | people, attendance, leave, payroll preparation, documents, reports |
| manager@peopleos.test | Manager (Amanda Clark) | approve leave/corrections/overtime for her team |
| kevin@peopleos.test | Manager (Kevin Tan) | Engineering team |
| employee@peopleos.test | Employee (Michael Carter) | mobile app: GPS check-in, leave, payslips, documents |
| jessica@peopleos.test | Employee | Surabaya site, **GPS + selfie** policy |
| olivia@peopleos.test | Employee | pending leave, correction and overtime |

More in [docs/demo-data.md](docs/demo-data.md).

## Documentation

| | |
|---|---|
| [architecture](docs/architecture.md) | layers, modelling decisions, demo/API modes |
| [database](docs/database.md) | tables, constraints, FK strategy |
| [api](docs/api.md) · [payroll](docs/api-payroll.md) · [documents](docs/api-documents.md) | REST contract |
| [attendance](docs/attendance.md) · [leave](docs/leave.md) · [payroll](docs/payroll.md) · [documents](docs/documents.md) | business rules and state machines |
| [security](docs/security.md) | authentication, authorization, IDOR, uploads, audit, checklist |
| [deployment](docs/deployment.md) | production install, HTTPS, storage, scheduler |
| [development](docs/development.md) · [testing](docs/testing.md) | local setup, tests |
| [customization](docs/customization.md) | branding, permissions, adding modules, payroll rules |
| [troubleshooting](docs/troubleshooting.md) | error codes and fixes |
| [assets](docs/assets.md) · [web-admin](docs/web-admin.md) · [demo-data](docs/demo-data.md) | assets, admin UI notes, demo data |

## License and assets

Third-party fonts, icons and images and their licenses are listed in [docs/assets.md](docs/assets.md).
