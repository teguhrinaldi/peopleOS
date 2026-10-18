# Flutter app: data provenance (API mode)

Every business value the employee app shows, where it comes from, and what happens when the server is unreachable. Scope: `app/lib` in **API mode** (`PEOPLEOS_USE_API=true`). Demo mode is a separate, deterministic in-app backend (`features/demo`) that is selected only by `apiModeProvider = false`.

Legend: **Live** = fetched from the endpoint on each load. **Cached** = may be served from the local read cache when the server is unreachable (network error, timeout, 5xx); the app then shows the *stale-data banner* "Showing saved data from N minutes ago" (age from the cache write time) until the next successful live read. A 4xx answer (403, 404, 409, 422 …) is never replaced by cached data. **UX hint** = computed on the device, labelled as an estimate, never authoritative.

## How honesty is enforced

| Rule | Mechanism | Verified by |
|---|---|---|
| API mode resolves API repositories only; Demo repositories only in Demo mode | `apiModeProvider` in `app/di.dart` is the single switch; every repository provider branches on it | `test/provenance/provenance_test.dart` (resolves each provider in both modes and asserts the concrete type; scans that `api_*` files never import demo code and that only DI, the demo feature and two demo-only widgets reference demo code) |
| No silent fallback to demo/fake data | Repositories never call demo code; failures surface as `AppException` -> error state with retry | provenance test "dead server shows an error, never data" (real `ApiAttendanceRepository` against a refusing server -> Home shows the error state, no shift/hero data) |
| Cached data is never presented as fresh | `readThrough` marks `JsonCache.staleSince` (cache write time) when it serves a cached payload; `StaleDataBanner` (in the app shell) shows the age; any live read clears it | provenance test "cached data is marked stale with its age"; `read_through` behaviour |
| 4xx business errors are not masked | `readThrough` only falls back for transient failures | provenance test |
| Mutations show success only after the server confirms | create/update/cancel/submit leave, correction, overtime, language preference: UI waits for the response, then invalidates providers. Notification "mark read" reverts the row if the server refuses; "mark all" only flips rows after the server answered | widget + live tests |
| Only allowed optimistic path | Attendance check-in/out: stored in the outbox first and labelled "Saved on this device. Waiting to sync." until the server confirms (`replayed:true` counts as confirmed) | outbox tests, live offline-replay test |

## Matrix

| Screen / value | Provider -> repository -> endpoint | Server table (from docs) | Offline behaviour | Notes |
|---|---|---|---|---|
| Login, session, profile name/number/department/position/branch/manager/shift/work location | `AuthController` -> `ApiAuthRepository` -> `POST /auth/login`, `GET /auth/me` | `users`, `employees` + assignments | Session cached only for *restore after a transient failure* (stale banner); login itself never uses cache | personal fields (`date_of_birth`, `phone`, `address`) may be `null` by privacy and render as "-" |
| Home: attendance state, schedule, shift, location, policy | `serverTodayProvider` -> `ApiAttendanceRepository.today` -> `GET /attendance/today` | `attendances`, schedule services | Cached + stale banner + "last known schedule" line; cached state from a previous day is rolled over to an open working day (`asStaleAt`) and is flagged stale | the server remains the authority on `can_check_in` |
| Home hero: elapsed timer | derived from `server_time` anchor + `check_in_at` | - | - | display-only ticking |
| Home hero: pending local check | `OutboxController` (device) merged by `mergeToday` | - | labelled "Saved on this device. Waiting to sync." | the only optimistic state; lateness of a *pending* check is an on-device **estimate** and is labelled as such |
| Check flow: distance, "within work area" | computed on device (haversine) vs `schedule.location` | - | - | **UX hint**, captioned "Estimated on this device"; the server's `OUTSIDE_GEOFENCE` etc. is shown as the result |
| Attendance history rows, detail, geofence badges, selfie | `attendanceHistoryProvider` -> `GET /attendance`, `GET /attendance/{id}`, selfie bytes via bearer | `attendances`, `attendance_events` | Cached + stale banner | month summary (Present/Late/Hours) = sums of server fields of the loaded month (no other maths) |
| Sync status | outbox (device) | - | - | local queue, states LOCAL/PENDING_SYNC/SYNCED/SYNC_FAILED |
| Requests: corrections, overtime lists, create, cancel | `requests_providers` -> `ApiRequestsRepository` -> `/attendance-corrections`, `/overtime-requests` | `attendance_corrections`, `overtime_requests` | lists cached + banner; writes require the server | cancel conflicts (`REQUEST_NOT_PENDING`) are mapped to a message and refresh the list |
| Leave: types, policy hints, balances, requests, detail | `leave_providers` -> `ApiLeaveRepository` -> `/leave-types`, `/leave-balances`, `/leave-requests` | `leave_types`, `leave_policies`, `leave_balances`, `leave_requests` | lists cached + banner; create/submit/cancel need the server | draft day count is `0.0` from the API and shown as "Counted when submitted" |
| Leave preview (working days, balance after) | `POST /leave-requests/preview` | same | on failure the summary card shows the mapped error, never a client estimate | no client-side day counting or balance maths in API mode |
| Home summary card: next leave / pending count | leave + correction + overtime lists | as above | hidden while loading or on error (supplementary card) | counts are `status` filters over server lists |
| Payslips list/detail | `payslipsProvider` -> `ApiPayslipRepository` -> `GET /payslips`, `/payslips/{id}` | `payslips`, `payroll_items`, `payroll_item_lines` | cached + banner | money kept as decimal **strings** (`Money`), formatted only; sums are never recomputed for display |
| Payslip PDF | `GET /payslips/{id}/pdf` with bearer | private disk | needs the server | no cached PDFs |
| Documents list/detail/download | `documentsProvider` -> `ApiDocumentsRepository` -> `/documents`, `/documents/{id}`, `download_url` | `documents`, `document_types` | list cached + banner; download needs the server | status and "days until expiry" come from the server; no storage paths are ever built |
| Home Updates strip | latest payslip + expiring/expired documents | as above | hidden on error (supplementary) | |
| Notifications inbox, unread badge | `inboxControllerProvider`, `unreadCountProvider` -> `/notifications`, `/unread-count`, `/read`, `/read-all` | `notifications` | not cached (error state with retry) | titles of known types are translated on the device; bodies are server text |
| Settings: language, theme, server address | local preferences; language also `PUT /auth/preferences` | `users.locale` | choice applies on the device even if the server cannot store it (snackbar says so) | |
| Quick actions, tab labels | static navigation | - | - | contain no business data |

## Known remaining approximations (honest list)

* The stale banner is global (one oldest-cache age for everything on screen), not per widget.
* `TodayAttendance.asStaleAt` guesses "open working day" when a cached read is from a previous day while offline; the banner says the data is old and the server re-validates every check.
* Supplementary cards (Home summary, Updates strip) hide on failure instead of showing an error; the primary screens (Home, lists, details) show real error states with retry.
* Loading further pages (`loadMore`) that fails keeps the loaded items and retries on the next scroll without a message.
