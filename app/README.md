# PeopleOS - Employee App (Flutter)

Employee-facing mobile / tablet / web app of PeopleOS: check in and out with GPS
(and selfie when required), attendance history, correction and overtime
requests, payslips (sample data) and profile. It works fully offline for
attendance and runs in two build-time modes:

| Mode | Backend | Use it for |
|---|---|---|
| **Demo** (default) | none - everything runs on the device with realistic sample data | evaluating the product, UI work, screenshots |
| **API** | your PeopleOS Laravel server (`BE/`) | real use |

The UI does not know which mode is active: both modes implement the same
repository interfaces.

## Run

Requirements: Flutter 3.47 / Dart 3.13 (any recent stable), an emulator, a
device or Chrome.

```bash
cd app
flutter pub get

# Demo mode - no backend needed (this is the default)
flutter run

# API mode
flutter run --dart-define=PEOPLEOS_USE_API=true \
            --dart-define=API_BASE_URL=http://10.0.2.2:8000
```

`--dart-define` flags:

| Flag | Default | Meaning |
|---|---|---|
| `PEOPLEOS_USE_API` | `false` | `true` = talk to the Laravel API, `false` = Demo mode |
| `API_BASE_URL` | empty | Server root **without** `/api/v1`. If empty in API mode, the login screen asks for the address and stores it; it can be changed later in *Profile > Settings > Server address*. A pasted `/api/v1` suffix is stripped. |

No server address is hardcoded anywhere in the source. Use the right host for
where the app runs:

| Where the app runs | Server address |
|---|---|
| Android emulator | `http://10.0.2.2:8000` |
| iOS simulator | `http://127.0.0.1:8000` |
| Chrome / desktop | `http://127.0.0.1:8000` (the server must allow CORS for the web origin) |
| Real phone on your Wi-Fi | `http://<your-computer-LAN-IP>:8000`, or better an HTTPS domain |
| Production | your `https://` domain |

Plain `http://` is allowed on **debug** Android builds only
(`android/app/src/debug/AndroidManifest.xml`); release builds require HTTPS
(iOS allows local-network HTTP through `NSAllowsLocalNetworking`).

Demo login: press **Demo Account** on the login screen (`employee@peopleos.test` / `password`).
In API mode use the seeded accounts, e.g. `employee@peopleos.test`,
`jessica@peopleos.test` (GPS + selfie policy), `olivia@peopleos.test`, password `password`.

Builds:

```bash
flutter build web                 # -> build/web
flutter build apk --debug         # -> build/app/outputs/flutter-apk/app-debug.apk
flutter build apk --release --dart-define=PEOPLEOS_USE_API=true --dart-define=API_BASE_URL=https://hr.example.com
```

### Demo controls

*Profile > Settings > Demo controls* (Demo mode only) lets an evaluator switch
scenarios without a backend: normal day, selfie required, not a working day, no
schedule, terminated employee; simulate being outside the work area; simulate
being offline (to watch the outbox); reset the demo data.

## Batch 1 additions (Leave, Notifications)

* **Leave** (Requests tab, first segment): balances strip, status filters, create/edit-draft form with live `POST /leave-requests/preview`, detail sheet with timeline, submit/cancel. All leave error codes map to readable copy (`leave_errors.dart`).
* **Notifications**: real inbox (`/notifications`), unread badge on the bell and Profile, mark read on open, mark all read, deep link to leave details / Requests.
* **Home**: `ON_LEAVE` state and a compact requests summary card.
* **Design tokens** are named `PeopleColors`, `PeopleTypography`, `PeopleSpacing`, `PeopleRadii`, `PeopleElevation`, `PeopleMotion`, `PeopleTheme` (`lib/core/theme/people_*.dart`); animations respect reduced motion.
* **Demo mode** runs the same leave rules locally (`features/demo/demo_leave.dart`): working days without weekends/holidays, reserve/use/release, overlap, notice, half day, states; the demo scenario list has *On approved leave today*.

## Batch 2 additions (Payslips, Documents, tablet, accessibility)

* **Payslip tab** (both modes): paginated history with year filter, status badge and payslip number; detail with company, employee, period, earnings with calculation hints ("19 days x 50,000"), deductions, gross, net and inputs (present days, overtime, unpaid leave). Money is kept as exact decimal strings (`core/utils/money.dart`) and formatted with the payslip's own currency and decimals; nothing uses floating point.
* **PDF**: fetched with the bearer header through the API client and handed to the platform (`core/platform/file_exporter*.dart`): the system share sheet on phones/desktop (save, open elsewhere, print), a browser download on web. Failures (403, 404, `FILE_MISSING`, offline) show a clear message.
* **Documents** (Profile > Documents and the Home quick action): documents the employee may see, filters by status and type, status badges ACTIVE / EXPIRING / EXPIRED / ARCHIVED with icon and "Expires in N days" text, detail and download through `download_url` (never a storage path; the token only goes to your configured server). HR-only and archived items never appear.
* **Home**: compact *Updates* strip (latest payslip, documents needing attention) next to the leave/request summary.
* **Notifications** open payslip and document details.
* **Tablet (>= 600 dp)**: persistent rail, two-column Home, master-detail for Requests, Payslip, Documents and Attendance history (`core/widgets/master_detail.dart`), width-capped content.
* **Demo mode** serves payslips (last three completed months; basic 9,000,000 from July 2026) and documents with statuses computed from the demo clock; PDFs are generated in-app by a ~40 line writer (`features/demo/demo_pdf.dart`), so no PDF package or bundled sample file is needed.
* **Accessibility**: automated WCAG AA contrast checks for every text/surface token pair in light and dark (two tokens were darkened because of it), 1.3x text-scale tests on the new screens, 48 dp chips, visible keyboard focus/hover colours, reduced-motion handling through `PeopleMotion`.
* **Performance**: paginated payslips/documents/notifications (`meta.last_page`), lazy builders, avatars and selfie thumbnails decoded at display size.

## Batch A additions (data provenance, localization)

### Data provenance (API mode honesty)
See [../docs/data-provenance-flutter.md](../docs/data-provenance-flutter.md) for the full matrix (screen -> provider -> repository -> endpoint -> server table, offline behaviour).

* `apiModeProvider` (`app/di.dart`) is the only switch between Laravel and Demo repositories; `test/provenance/provenance_test.dart` resolves every repository provider in both modes and fails if API mode yields a demo class, if an `api_*` data source imports demo code, or if non-demo code references the demo backend.
* No silent fallbacks: a failing server gives an error state with retry. Cached reads (offline) are announced by a banner with their age ("Showing saved data from 5 minutes ago") and cleared by the next live read; 4xx answers are never replaced by cache.
* Mutations show success only after the server answered. The attendance outbox is the single optimistic path and is labelled "Saved on this device. Waiting to sync."; on-device lateness/distance values are labelled as estimates.

### Languages (English, Bahasa Indonesia)
* ARB catalogue in `lib/l10n` (`app_en.arb` template, `app_id.arb`), generated with `flutter gen-l10n` (config in `l10n.yaml`). Sources are kept as feature fragments in `lib/l10n/fragments/*.{en,id}.json`; `node tool/merge_arb.js` merges them into the ARBs and runs `gen-l10n` (edit the fragments, not the ARBs).
* Use `context.l10n.key` for text and `context.fmt` for dates, numbers and money (intl). IDR uses Indonesian separators in `id` ("Rp 10.615.000") and English ones in `en` ("Rp 10,615,000"); stored amounts/timestamps never change.
* First run follows the device language with English fallback; the language selector (login screen and Profile > Settings) switches at runtime without a restart. The choice is stored locally and, when signed in, sent to the server (`PUT /auth/preferences {locale}`; the server preference returned by `/auth/me` wins after sign-in; a failure keeps the local choice and tells the user). Requests send `X-Locale`.
* Server-provided free text (notification bodies, review notes, user input, names) is displayed as received; API error *codes* are mapped to localized messages on the device.
* Tests: `test/l10n` (every en key exists in id with the same placeholders, no hard-coded English literals in `lib/`, runtime switching, persistence, server sync, localized screens).

## Architecture

```
lib/
  main.dart                     bootstrap (ProviderScope, prefs, version)
  app/                          MaterialApp.router, go_router, adaptive shell, di.dart (wiring)
  core/
    config/   AppConfig         compile-time flags
    error/    AppException      typed failures + readable messages per error.code
    network/  ApiClient         Dio wrapper: envelope, bearer token, 401 hook, GET retry, error mapper, read-through cache
    platform/ LocationService, SelfieService   (geolocator / image_picker behind interfaces)
    storage/  TokenStore (secure storage), AppSettings, JsonCache
    theme/    AppColors (light+dark tokens), AppTypography (Inter), tokens, AppTheme
    utils/    haversine, ZonedTime (company-offset time), formatters, JSON reader, responsive
    widgets/  design-system components (buttons, card, badge, text field, avatar, skeleton, states, banners, sheets, nav)
  features/<feature>/{data,domain,presentation}
    auth, home, attendance, requests, payslip, notifications, profile, common, demo
```

Layering: **UI -> controller/state (Riverpod) -> repository (abstract) -> data source**.

* **State management: Riverpod** (`Notifier` / `AsyncNotifier`-style providers, no
  code generation). Providers are overridable, which is what makes the fakes in
  `test/` trivial and lets `di.dart` be the single place where Demo vs API is
  chosen. **go_router** handles navigation, the auth guard/redirects and the
  tab shell (`StatefulShellRoute`).
* **Repositories** (`AuthRepository`, `AttendanceRepository`,
  `RequestsRepository`, plus static payslip/notification data) each have an
  `Api*` implementation and a `Demo*` implementation.
* **Demo backend** (`features/demo/demo_backend.dart`) behaves like the real
  server: same JSON shapes, geofence maths, selfie policy, idempotent
  replays, state conflicts (`ALREADY_CHECKED_IN`...), correction window, overtime
  rules. It is persisted in SharedPreferences so the demo survives restarts.
* **Errors**: HTTP status picks the family, `error.code` refines it (never the
  message): 401 (forced logout except `INVALID_CREDENTIALS`), 403, 404, 409, 422
  (field errors *and* business codes such as `OUTSIDE_GEOFENCE`), 429
  (`retry_after`), 5xx, timeout, no connection. Only idempotent GETs are
  retried; writes are never blindly retried by the HTTP layer.
* **Design system**: tokens for light and dark in `core/theme`, the Inter font is
  bundled as an asset, `ThemeData` is built from the tokens. Text scaling is
  clamped to 1.3x, tap targets are >= 48 dp, status is always icon + text.
  Below 600 dp: bottom navigation; from 600 dp: side rail, constrained content
  and a two-column Home.

### Offline attendance

```
tap -> CheckCapture (GPS fix, selfie, device time)
    -> OutboxStore  (LOCAL, persisted BEFORE any network call, UUID idempotency key)
    -> PENDING_SYNC -> POST /attendance/check-in|check-out
    -> SYNCED  |  stays PENDING_SYNC (network / timeout / 5xx / 429, exponential back-off)
               |  SYNC_FAILED (4xx business rejection, kept with a readable reason)
```

* One idempotency key per user action, re-sent on every retry; replays carry
  `is_offline_sync=true` and `device_captured_at`; a `200 replayed:true` answer
  counts as success.
* If the server rejects the action while the user is still on the check screen
  (for example `OUTSIDE_GEOFENCE`), the reason is shown immediately and nothing
  is queued. Rejections found later during a background sync become
  `SYNC_FAILED` items the user can **Retry** or **Discard** in *Sync status*.
* Home merges pending actions on top of `GET /attendance/today`
  (`mergeToday`) and labels them "Saved on this device. Waiting to sync."
  Items sync oldest first; a transient failure stops the run so a check-out never
  overtakes its check-in. Triggers: connectivity change, app resume, a 15 s timer
  (respects back-off) and *Sync now*.
* The queue lives in SharedPreferences (JSON, serialised writes; works on every
  platform incl. web). Selfies are stored as files (native) or base64 (web)
  and deleted once delivered. Items are scoped to the employee id and survive
  logout / token expiry.
* Location is requested only when a check starts, one foreground fix, never in the
  background. Elapsed working time is anchored to the server clock.

## Tests and checks

```bash
dart format .
flutter analyze
flutter test                                   # unit + widget tests (live API tests are skipped)
PEOPLEOS_API_TESTS=1 flutter test test/api/live_api_test.dart   # against a running server
# PEOPLEOS_API_SKIP_OVERTIME=1 skips only the overtime list read in the provenance suite (printed as SKIPPED);
# use it only while the backend /overtime-requests endpoint is broken.
#   optional: PEOPLEOS_API_URL=http://127.0.0.1:8000  PEOPLEOS_API_USER=jessica@peopleos.test  PEOPLEOS_API_USER2=olivia@peopleos.test
```

Tests use fakes for location, camera, connectivity, storage and repositories
(`test/helpers`) and the real bundled Inter font (`test/flutter_test_config.dart`).
The live API test writes real attendance for the chosen demo user (a second
run the same day skips the write steps).

## Platform notes

* **Android**: location (fine/coarse, foreground only), camera and internet permissions are in
  `AndroidManifest.xml`; cleartext HTTP only in the debug manifest.
  `kotlin.incremental=false` in `android/gradle.properties` works around a
  Windows problem when the project and the pub cache are on different drives.
* **iOS**: usage strings for location-when-in-use, camera and photo library are in `Info.plist`.
* **Web**: works (location via the browser, selfie via file/camera picker); the API server needs CORS.
* **Windows desktop**: the project is configured, but building needs Visual Studio
  (not available in the reference environment, so it has not been built).

## Known limitations

* "More" is a placeholder ("coming soon"); *Forgot password* explains that HR resets passwords.
* Payslip PDFs and documents open through the platform share sheet / browser download; there is no in-app PDF viewer.
* The location card is a stylised drawing, not a real map (no map SDK by design).
* No push notifications, no biometric lock, English only.
* Managers/HR review UIs are intentionally not part of this app (Web Admin).
