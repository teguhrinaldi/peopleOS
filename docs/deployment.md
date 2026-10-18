# Deployment

PeopleOS is source code you host yourself. This guide takes a fresh Linux server to a running installation. Windows/IIS works too; the steps below use Ubuntu + Nginx.

## 1. Requirements

| | Minimum |
|---|---|
| PHP | 8.3 with `pdo_mysql mbstring openssl fileinfo gd intl zip curl bcmath xml dom` |
| Composer | 2.x |
| MySQL | 8.0+ (or MariaDB 10.6+, untested), `utf8mb4` |
| Node | 20+ (only to build web admin assets; not needed on the server if you build elsewhere) |
| Web server | Nginx or Apache with HTTPS |
| Flutter | 3.4x stable, only on the machine that builds the mobile app |

PHP limits: `upload_max_filesize` and `post_max_size` must be at least `PEOPLEOS_DOCUMENT_MAX_KB` (default 10 MB) plus overhead.

## 2. Get the code and install

```bash
cd /var/www
# copy the BE/ folder here as /var/www/peopleos
cd peopleos
composer install --no-dev --optimize-autoloader
npm ci && npm run build          # web admin assets -> public/build
```

## 3. Database

```sql
CREATE DATABASE peopleos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'peopleos'@'localhost' IDENTIFIED BY '<strong password>';
GRANT ALL PRIVILEGES ON peopleos.* TO 'peopleos'@'localhost';
```

Use a dedicated user restricted to this schema, never `root`. Raw SQL can bypass the application's immutability guards for finalized payroll, so limit who holds database credentials.

## 4. Configure `.env`

```bash
cp .env.example .env
php artisan key:generate
```

| Key | Production value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` (never `true`: it prints secrets) |
| `APP_URL` | `https://hr.example.com` |
| `DB_*` | the dedicated user from step 3 |
| `PEOPLEOS_DEFAULT_TIMEZONE` | used only until the company record exists |
| `SANCTUM_TOKEN_EXPIRATION_MINUTES` | mobile token lifetime (default 43200 = 30 days) |
| `PEOPLEOS_DOCUMENT_DISK`, `PEOPLEOS_PAYSLIP_DISK`, `PEOPLEOS_SELFIE_DISK` | **private** disks only (default `local`). Point them at an S3-compatible private bucket if you prefer; never at `public` |
| `PEOPLEOS_DOCUMENT_MAX_KB` | upload limit in KB |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `database` works; use `redis` for several app servers |
| `MAIL_*` | only needed if you add an email notification channel |

Company name, timezone, currency, number formats and policies are configured in the web admin (Settings), not in `.env`.

## 5. Migrate and seed

```bash
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force    # roles + permissions only
php artisan db:seed --class=DocumentTypeSeeder --force           # default document types
```

**Do not run `php artisan db:seed` in production.** That seeds the demo company with `password` logins. Create your first admin and company in `php artisan tinker`:

```php
$company = App\Models\Company::create(['name' => 'Acme Ltd', 'timezone' => 'Asia/Jakarta']);
$user = App\Models\User::create(['name' => 'Admin', 'email' => 'admin@acme.example', 'password' => '<strong password>', 'is_active' => true]);
$user->assignRole('admin');
```

Then sign in at `/login`, finish Settings, and add branches, departments, positions, shifts, work locations, leave types and salary components from the web admin. Create the employee record for the admin (Employees → Add) if the admin also uses the mobile app.

**Upgrading later:** `git pull`/copy new code → `composer install --no-dev` → `npm run build` → `php artisan migrate --force` → `php artisan db:seed --class=RolesAndPermissionsSeeder --force` (idempotent; re-applies the permission matrix) → `php artisan config:cache route:cache view:cache` → restart PHP-FPM. Take a database backup first. Migrations are additive and never edit old migration files.

## 6. Storage

```bash
php artisan storage:link                    # exposes ONLY storage/app/public (employee photos, company logo)
chown -R www-data:www-data storage bootstrap/cache
chmod -R u+rwX,g+rwX storage bootstrap/cache
```

Everything sensitive (selfies, documents, payslips) lives in `storage/app/private` and is streamed through authorized routes. Make sure the web server's document root is `public/` only and that nothing serves `storage/` directly. Back up `storage/app` together with the database.

## 7. Web server (Nginx)

```nginx
server {
    listen 443 ssl http2;
    server_name hr.example.com;
    root /var/www/peopleos/public;
    index index.php;
    client_max_body_size 12m;

    ssl_certificate     /etc/letsencrypt/live/hr.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/hr.example.com/privkey.pem;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}
server { listen 80; server_name hr.example.com; return 301 https://$host$request_uri; }
```

**HTTPS is mandatory**: the mobile app sends bearer tokens, the admin uses session cookies. Android release builds do not allow cleartext HTTP.

## 8. Scheduler and queue

One cron entry runs all scheduled jobs:

```
* * * * * cd /var/www/peopleos && php artisan schedule:run >> /dev/null 2>&1
```

Jobs (all idempotent, safe to run twice): `attendance:build-schedules` (hourly; creates each active employee's schedule snapshot so absences can be reported), `documents:refresh-status` (hourly; expiring/expired status + notifications), `payroll:payslips` (hourly; repairs missing payslip files).

No queue worker is required in V1: payslip PDFs render synchronously and notifications are written in-process. If you later add push/email channels or move heavy work to queues, run `php artisan queue:work --tries=3` under Supervisor/systemd.

## 9. Production optimizations

```bash
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
```

Re-run after every deploy. Enable OPcache. Use `redis` for cache/session if you run several app servers (rate limiting and the settings cache are per cache store).

## 10. Mobile app

1. Build against your own server: `flutter build apk --release --dart-define=PEOPLEOS_USE_API=true --dart-define=API_BASE_URL=https://hr.example.com` (or leave `API_BASE_URL` empty and let users type it on the login screen).
2. Change the application id/bundle id, name and icons (`app/android`, `app/ios`), then sign and publish through your own Play Console / App Store account. See [customization.md](customization.md).
3. Restrict CORS to your web origins if you serve the Flutter web build: `php artisan config:publish cors`, set `allowed_origins`.

## 11. Security checklist

- [ ] `APP_DEBUG=false`, unique `APP_KEY`, HTTPS everywhere, HSTS.
- [ ] Demo accounts removed or passwords changed; demo seeder never run.
- [ ] Dedicated least-privilege database user; database not exposed publicly; backups encrypted and tested.
- [ ] `storage/` not web-accessible; private disks only for documents, payslips, selfies.
- [ ] Firewall: only 80/443 (and SSH from known IPs).
- [ ] Cron scheduler running; log rotation configured (`storage/logs`).
- [ ] PHP `upload_max_filesize`/`post_max_size` consistent with `PEOPLEOS_DOCUMENT_MAX_KB`.
- [ ] Review [security.md](security.md) known limitations (no 2FA/SSO/password reset by email in V1).
- [ ] Payroll rules configured for **your** jurisdiction (see [payroll.md](payroll.md)); PeopleOS ships no statutory rules.

## 12. Backup and restore

Back up the database (`mysqldump --single-transaction peopleos`) and `storage/app` together; restoring one without the other leaves dangling document/payslip references. Test a restore on a staging copy before relying on it.
