# Marketing assets

Everything here was produced from the **actually running product**: the Flutter employee app (web build, API mode) and the Laravel Web Admin, driven by a real headless Chrome. Nothing is a screenshot slideshow or a mock-up. The only non-product pixels are the title/outro cards, the caption bar, the phone bezel and the browser frame (HTML/CSS in `scripts/stage/`).

| Path | What |
|---|---|
| `video/peopleos_demo.mp4` | 120 s, H.264 High, 1920x1080, 30 fps, yuv420p, silent, about 12 MB |
| `thumbnails/01_hero.png` ... `08_whats_included.png` | 1280x720 PNG, built from fresh screenshots of the running apps |
| `scripts/` | every script needed to regenerate (see "Regenerate") |

## What the video shows (all real footage)

Order: title card, Employee app sign in and Home, **GPS check-in** (Chrome geolocation emulation, inside the Head Office radius, server accepts it), attendance history, **leave request** (type, dates, live working-day/balance preview, reason, submit), **manager approves in the Web Admin** (Amanda Clark, scope-limited list, confirm dialog), **notification appears** in the app ("Leave approved"), payslip list and detail with the **PDF download button**, a short **dark-mode** moment, **strict-policy employee check-in with a selfie** (Jessica Taylor, Surabaya, GPS + selfie), Web Admin as Alex Morgan (Admin): dashboard, employees, attendance board (shows the two check-ins made earlier in the video), **payroll September: review, Approve (as admin, different person than the preparer), Finalize**, payslips list and a payslip PDF, documents, **reports** (Payroll summary run, CSV export, PDF export).

Clips were recorded one per scene (`scripts/record.js`) and are time-compressed about 2.9x by `scripts/assemble.js` to reach 120 s (typing and waits are fast-forwarded; the cards are 1x). Scene fades are real fades in the stage page.

## Data used

* A **private copy** of the dev database: DB `peopleos_marketing` (left in place on the local MySQL), cloned from `peopleos` and cleaned of QA leftovers (open stale check-ins on 30 Sep for Michael and Olivia, QA draft/cancelled leave requests). A baseline dump of that clean state is what `reset-db.ps1` restores. The shared `peopleos` DB and the repo were never written to.
* Demo accounts from the README (all password `password`): employee@ (Michael Carter), manager@ (Amanda Clark), jessica@ (selfie policy), admin@ (Alex Morgan).
* **Clock**: the machine date is Saturday 10 Oct 2026 (rest day, no check-in possible). The marketing copy of BE reads `offset.txt` on every request and shifts "now" to **Monday 12 Oct 2026, about 08:00 Asia/Jakarta**; the browser `Date` and the geolocation fix timestamps are shifted by the same offset (`lib.js fakeClock`). So the on-screen dates (12 Oct) are the shifted ones. This is a recording technique only; no product code was changed.
* GPS position: Head Office (-6.92181, 107.60712, accuracy 12 m); Surabaya Field Office for Jessica.
* Side effects inside `peopleos_marketing` only: Michael and Jessica checked in, Michael's leave 21-23 Oct approved, September payroll approved + finalized (15 payslips).

## Regenerate

Prereqs on this machine: PHP 8.3 (WinGet), MySQL 8.4 on 3306, Node 22, Chrome, ffmpeg (WinGet Gyan), Flutter. `$S` = any scratch folder outside the repo, `$env:MKT_SCRATCH = $S` for every `node` command.

```powershell
.\scripts\setup.ps1 -Scratch $S             # BE copy + clock shim, DB copy, Flutter web build, npm deps
# clean QA leftovers if present, then snapshot:  mysqldump -u root --routines peopleos_marketing > $S\baseline.sql
.\scripts\start-be.ps1 -Scratch $S           # API + Web Admin on :8010 (own php.ini, see Limitations)
node .\scripts\serve.js                      # Flutter build + stage page on :8020   (MKT_SCRATCH must be set)
.\scripts\reset-db.ps1 -Scratch $S           # restore baseline (before every full recording)
node .\scripts\set-clock.js                  # sets "now" to Mon 12 Oct 07:50 Jakarta
node .\scripts\record.js                     # all scenes -> $S\clips\*.mp4   (or: record.js login,checkin,... ; _michael / _admin only log in)
node .\scripts\assemble.js 120               # -> marketing\video\peopleos_demo.mp4
node .\scripts\shots.js                      # fresh screenshots -> $S\shots
node .\scripts\thumbs.js                     # -> marketing\thumbnails\*.png
```

Chrome is launched by `lib.js` with a fresh profile each time (password manager disabled: its save-password bubble otherwise swallows input after the Flutter login form), `--use-fake-device-for-media-stream --use-fake-ui-for-media-stream`, geolocation and camera permissions granted. The Flutter app is driven through its accessibility (semantics) tree (`phone.js`), the admin by DOM selectors; the stage page (`scripts/stage/stage.html`) embeds both as live iframes and is captured with CDP `Page.startScreencast` (device scale factor 2, timestamps turned into a constant-30-fps clip by ffmpeg).

## Limitations and honest notes

* **Selfie**: the web build of `image_picker` opens a file chooser, not the camera. The script answers that chooser with one still frame grabbed from Chrome's **fake video device** (the green test pattern), so the selfie step, preview, upload (multipart JPEG) and server acceptance are real, but the "portrait" is Chrome's test pattern, not a face.
* **Payslip PDF download (phone) and PDF exports (admin)**: headless Chrome has no PDF viewer and converts `application/pdf` responses into empty downloads, which breaks XHR/fetch of the PDF endpoints (appears as a CORS error). Workaround in the harness only: for `/api/v1/*/pdf` GETs the stage fetches the same URL from Node and returns the same bytes as `application/octet-stream` (`stage.js`, `Fetch.requestPaused`). Admin PDF links open in a new tab, which does not work headless, so the script fetches them with a separate Node admin login. The PDF pages shown in the video are the real server-generated files rendered with **pdf.js** (Apache-2.0) into the overlay. The download of the CSV is a genuine Chrome download.
* PHP's `php artisan serve` on this machine could not create upload temp files (selfie upload failed with "unable to create a temporary file"); `start-be.ps1` therefore starts `php -S` with a php.ini that sets `upload_tmp_dir`/`sys_temp_dir`. Environment issue, not a product bug.
* The video is time-compressed about 2.9x; real interactions are slower. Scenes were recorded in separate takes from the same continuous session (some scenes, notably notification, payslip, dark, selfie and the admin payslips/reports, were re-recorded after fixes), so Michael's home shows him already checked in at that point, consistent with the story.
* No audio. No camera or microphone of the real machine is used.
* Thumbnail text makes only claims taken from README/docs (modules, stack, demo/API modes). No statistics, customers, prices or compliance claims. Numbers visible inside screenshots are the demo data.

## Product observations (not changed)

* Home showed "Check Out" with a counter of 241 hours for the seeded 30 Sep open check-in (Michael): a stale open session is displayed as an ongoing shift with no auto-close or warning. It came from the seed plus QA runs; the marketing DB closes those rows.
* The web build's PDF download relies on responses being readable by the browser; verified working only through the harness shim above (in a normal desktop Chrome it is expected to work, not verified here).
* `Check-in` rejects a location fix older than the server allows ("The location fix is too old"); with a shifted clock the fix timestamp must be shifted too (handled in the harness).
