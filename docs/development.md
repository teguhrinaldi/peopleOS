# Development guide

## Prerequisites

| Tool | Version used |
|---|---|
| PHP | 8.3 (Laravel 13 needs ≥ 8.3) with `pdo_mysql, mbstring, openssl, fileinfo, gd, intl, zip, curl, bcmath` |
| Composer | 2.x |
| MySQL | 8.0+ (developed on 8.4) |
| Node | 20+ (Vite/Tailwind for the web admin) |
| Flutter | 3.4x stable (developed on 3.47) |

## Backend

```bash
cd BE
composer install
cp .env.example .env
php artisan key:generate
# edit DB_* in .env, then create the database:
#   CREATE DATABASE peopleos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate --seed
php artisan storage:link
npm install && npm run build       # web admin assets (npm run dev for hot reload)
php artisan serve                  # http://127.0.0.1:8000
```

* Web admin: `http://127.0.0.1:8000/login` (admin@peopleos.test / password)
* API health: `GET /api/v1/ping`
* Environment knobs (`.env`): `PEOPLEOS_DEFAULT_TIMEZONE`, `SANCTUM_TOKEN_EXPIRATION_MINUTES`, `PEOPLEOS_SELFIE_DISK`.
* Attendance limits live in `config/peopleos.php`.

### Local MySQL without installing a service (Windows)

`scripts/dev-mysql.ps1` initializes a data directory under `.dev/` (gitignored) and runs `mysqld` on 127.0.0.1:3306 with relaxed durability for speed (dev only). `scripts/dev-mysql-stop.ps1` stops it. The root user has an empty password: **never do this on a shared machine or in production.**

### Tests

Tests need a real MySQL (behaviour depends on constraints and locks):

```sql
CREATE DATABASE peopleos_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php artisan test                       # all
php artisan test --testsuite=Unit      # pure logic, no DB, instant
php artisan test --filter=ConcurrencyTest
```

`phpunit.xml` points at `peopleos_test`; override with `DB_DATABASE=…`. `RefreshDatabase` runs the migrations once per run (about a minute on a slow Windows disk). `ConcurrencyTest` truncates all tables of the test database and spawns parallel PHP processes, so never point it at real data.

### Style

`vendor/bin/pint` (Laravel Pint, PSR-12 based).

## Flutter app

```bash
cd app
flutter pub get
flutter run                                             # Demo mode (default): no backend
flutter run --dart-define=PEOPLEOS_USE_API=true         # API mode
flutter run --dart-define=PEOPLEOS_USE_API=true --dart-define=API_BASE_URL=http://10.0.2.2:8000
flutter analyze && flutter test
```

Server URL by target: Android emulator `http://10.0.2.2:8000`, iOS simulator `http://127.0.0.1:8000`, physical device the machine's LAN IP or (recommended) an HTTPS domain. With `API_BASE_URL` empty the login screen asks for the URL and remembers it. See `app/README.md` for architecture, offline design and platform notes.

Visual Studio is required to build the Windows desktop target; web and Android/iOS work without it.

## Repository conventions

* Business rules in services, not controllers; authorization by permission, never role name.
* New permission → add to `PermissionRegistry` (and the role matrix), run `php artisan db:seed --class=RolesAndPermissionsSeeder`.
* New domain rule → add a test that fails first.
* Every external asset (fonts, icons, photos) is recorded in `docs/assets.md`.
* Never commit `.env`.
