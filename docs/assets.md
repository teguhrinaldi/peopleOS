# External assets

Every third-party asset bundled with PeopleOS, with its source and licence. Nothing is loaded from a CDN at runtime.

## Web Admin (`BE/`)

| Asset | Source | Purpose | Licence |
|---|---|---|---|
| Inter variable font (via npm `@fontsource-variable/inter` 5.3.0, woff2 files copied into `public/build` by `npm run build`) | https://fontsource.org/fonts/inter · upstream https://github.com/rsms/inter | Product typeface for the Web Admin, matching the design reference | SIL Open Font License 1.1 |
| Lucide icons, 96 SVG files (79 copied from npm `lucide-static` 1.49.0; 17 added in Web Admin batch 2 — refresh-cw, send, undo-2, banknote, ban, archive, archive-restore, check-check, paperclip, file-spreadsheet, file-image, circle-x, external-link, percent, minus, bell-off, copy, file-plus — drawn by hand after Lucide geometry because the package copy was not available offline; replace them with the package originals when convenient) in `BE/resources/svg/lucide/` , inlined by the `<x-icon>` Blade component | https://lucide.dev · https://github.com/lucide-icons/lucide | Navigation, buttons, status and empty-state icons | ISC (licence text kept in `BE/resources/svg/lucide/LICENSE.txt`; parts derived from Feather are MIT) |
| Alpine.js 3.17.4 (`alpinejs`, `@alpinejs/focus`, `@alpinejs/anchor`), bundled by Vite | https://alpinejs.dev | Dropdowns, modals, drawer, sidebar state | MIT |
| Tailwind CSS v4 (`tailwindcss`, `@tailwindcss/vite`), compiled at build time | https://tailwindcss.com | Utility CSS | MIT |

## Created for this project (no third-party licence)

| Asset | Location | Notes |
|---|---|---|
| PeopleOS mark (three-person logo) | `BE/resources/views/components/logo.blade.php`, `BE/public/favicon.svg` | Drawn as inline SVG for this project. |
| Login backdrop wave | `BE/resources/views/layouts/guest.blade.php` | Inline SVG path. |
| Employee avatars | generated initials in `<x-avatar>` | No stock photos are bundled. Uploaded employee photos are stored on the `public` disk and never shipped with the source. |

## Photography

The Web Admin currently ships **no photography**. The reference design shows portrait photos on the employee list and detail; these are rendered as coloured initials avatars until real employee photos are uploaded (Unsplash/Pexels portraits can be added here later with their licence notes).

## Flutter employee app (`app/`)

| Asset | Source | Purpose | Licence |
|---|---|---|---|
| Inter 4.1 static fonts `Inter-Regular.ttf`, `Inter-Medium.ttf`, `Inter-SemiBold.ttf`, `Inter-Bold.ttf` in `app/assets/fonts/` (from `extras/ttf/` of the release zip) | https://github.com/rsms/inter/releases/tag/v4.1 (`Inter-4.1.zip`) | Product typeface for the whole app (declared in `pubspec.yaml`; no runtime `google_fonts` download) | SIL Open Font License 1.1 (`app/assets/fonts/LICENSE.txt`) |
| Material Icons (`MaterialIcons-Regular`, tree-shaken by the Flutter build) | Bundled with the Flutter SDK (`uses-material-design: true`) · https://fonts.google.com/icons | Navigation, buttons, status and list icons | Apache License 2.0 |
| PeopleOS mark (three-person logo) | Drawn in code: `app/lib/core/widgets/peopleos_logo.dart` (`CustomPaint`) | Splash, login, side rail | Created for this project |
| Splash waves, static "map" location card | Drawn in code: `splash_screen.dart`, `location_map_card.dart` | Splash background; check-in location card (no map SDK, no map tiles, no third-party imagery) | Created for this project |

**Photography.** The Flutter app ships no photographs. The reference design shows portrait photos on Home, the check-in selfie step and Profile; the app renders **initials avatars** instead, and shows the employee's own `photo_url` when the API provides one. In Demo mode on web/desktop, where no camera exists, the selfie step uses a small vector portrait generated at runtime (`app/lib/features/demo/demo_services.dart`).

## Payroll (`BE/`)

| Asset | Source | Purpose | Licence |
|---|---|---|---|
| `barryvdh/laravel-dompdf` with `dompdf/dompdf` and its bundled DejaVu Sans fonts (Composer, no runtime download) | https://github.com/barryvdh/laravel-dompdf | Renders payslip PDFs (`resources/views/payroll/payslip.blade.php`) | MIT (library); DejaVu fonts: Bitstream Vera licence, freely redistributable |

## Marketing (`marketing/`)

| Asset | Source | Purpose | Licence |
|---|---|---|---|
| Inter variable woff2 (`marketing/scripts/stage/inter.woff2`, copy of `BE/public/build/assets/inter-latin-wght-normal-*.woff2`) | Already shipped with the Web Admin (Fontsource build of Inter) | Typeface of title cards, captions and thumbnails | SIL Open Font License 1.1 |
| pdf.js 3.11.174 (`pdfjs-dist`, installed from npm into the scratch folder only, **not committed**) | https://github.com/mozilla/pdf.js | Renders the real generated PDFs inside the video overlay (headless Chrome has no PDF viewer) | Apache License 2.0 |
| Selfie still for the strict-policy check-in | One frame captured from Chrome's built-in fake video device (`--use-fake-device-for-media-stream`) at record time, not committed | Stand-in for a camera in the web build | Generated by the browser, no third-party content |
| Video and thumbnails | Screenshots and screen recordings of the running PeopleOS apps | Marketing | Created for this project; no stock footage, no music (the video is silent) |
