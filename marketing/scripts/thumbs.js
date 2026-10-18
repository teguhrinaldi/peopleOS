// Renders the 1280x720 thumbnails from the fresh screenshots in <scratch>/shots.
// Usage: MKT_SCRATCH=<scratch> node thumbs.js   -> marketing/thumbnails/*.png
const fs = require('fs'), path = require('path'), url = require('url');
const { launch, sleep, SCRATCH } = require('./lib.js');
const SHOTS = path.join(SCRATCH, 'shots'), OUT = path.join(__dirname, '..', 'thumbnails'), WORK = path.join(SCRATCH, 'thumbwork');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(WORK, { recursive: true });
const img = n => url.pathToFileURL(path.join(SHOTS, n + '.png')).href;
const FONT = url.pathToFileURL(path.join(__dirname, 'stage', 'inter.woff2')).href;
const LOGO = fs.readFileSync(path.join(__dirname, 'stage', 'logo.svg.html'), 'utf8');

const phone = (n, x, y, h, rot = 0) => { const w = Math.round(h * 390 / 844); const pad = Math.round(h * 0.022);
  return `<div class="ph" style="left:${x}px;top:${y}px;width:${w + pad * 2}px;height:${h + pad * 2}px;padding:${pad}px;border-radius:${Math.round(h * .07)}px;transform:rotate(${rot}deg)"><img src="${img(n)}" style="width:${w}px;height:${h}px;border-radius:${Math.round(h * .05)}px"></div>`; };
const win = (n, x, y, w, h) => `<div class="win" style="left:${x}px;top:${y}px;width:${w}px;height:${h + 22}px"><div class="wb"><i></i><i></i><i></i></div><div class="wi" style="height:${h}px"><img src="${img(n)}" style="width:${w}px"></div></div>`;
const left = (kicker, head, sub, chips = []) => `<div class="left" style="width:${/Web Admin/.test(kicker) ? 430 : 470}px"><div class="k">${kicker}</div><h1>${head}</h1><p>${sub}</p>${chips.length ? '<div class="chips">' + chips.map(c => `<span>${c}</span>`).join('') + '</div>' : ''}</div>`;
const brand = `<div class="brand"><span class="lg">${LOGO}</span><b>PeopleOS</b></div>`;

const T = {
  '01_hero': `<div class="brand big"><span class="lg">${LOGO}</span><b>PeopleOS</b></div>
    <div class="left" style="top:230px"><h1 style="font-size:64px">Manage people.<br>Simplify work.</h1><p>Workforce &amp; HR management as source code you host yourself.</p>
    <div class="chips"><span>Flutter app</span><span>Laravel Web Admin + API</span><span>MySQL</span></div></div>
    ${win('admin_dashboard', 600, 70, 640, 400)}${phone('app_home', 840, 250, 440, 0)}`,
  '02_employee': `${brand}${left('Employee app', 'Everything an employee needs', 'Home, attendance, leave requests, payslips and documents in one Flutter app.', ['Light + dark', 'Demo or API mode'])}
    ${phone('app_home', 560, 90, 540, -3)}${phone('app_attendance', 790, 170, 500, 2)}${phone('app_payslips', 1010, 60, 500, 4)}`,
  '03_attendance': `${brand}${left('Attendance', 'GPS check-in inside the work area', 'Location checked server-side against the work-location radius. Selfie optional per policy. Offline sync.', ['Geofence', 'Selfie policy', 'Corrections'])}
    ${win('admin_attendance', 560, 330, 700, 360)}${phone('app_confirm_location', 600, 40, 420, -2)}`,
  '04_leave': `${brand}${left('Leave', 'Request, approve, notify', 'Live working-day preview in the app. Managers approve in the web admin; balances stay consistent.', ['Balances', 'Half days', 'Approval flow'])}
    ${phone('app_leave_form', 560, 70, 560, -2)}${win('admin_leave_review', 780, 360, 560, 340)}`,
  '05_payroll': `${brand}${left('Payroll', 'Review, approve, finalize, payslip', 'A separate approver, immutable after finalize, PDF payslips for every employee.', ['Configurable components', 'PDF payslips'])}
    ${win('admin_payroll_period', 560, 80, 700, 440)}${phone('app_payslip_detail', 1010, 220, 470, 3)}`,
  '06_admin': `${brand}${left('Web Admin', 'One place to run HR', 'Dashboard, employees, attendance board, leave, payroll, documents and reports.', ['Roles + permissions', 'Audit trail'])}
    ${win('admin_employees', 600, 320, 660, 380)}${win('admin_dashboard', 560, 60, 660, 420)}`,
  '07_reports': `${brand}${left('Reports', 'Run a report, export CSV or PDF', 'Attendance, leave, payroll and document reports with filters.', ['CSV', 'PDF'])}
    ${win('admin_reports_catalogue', 560, 60, 660, 400)}${win('admin_report_payroll', 620, 330, 640, 370)}`,
  '08_whats_included': `${brand}<div class="left" style="width:1100px"><div class="k">What's included</div><h1 style="font-size:52px">The complete workflow, as source code</h1></div>
    <div class="grid">${['People &amp; org structure', 'GPS attendance + geofence', 'Selfie, offline sync, corrections', 'Leave types, balances, approvals', 'Payroll review &rarr; finalize', 'PDF payslips', 'Private documents + expiry', 'In-app notifications', 'Reports with CSV / PDF export', 'Roles, permissions, audit trail'].map(t => `<div class="ck"><b>&#10003;</b>${t}</div>`).join('')}</div>
    <div class="foot"><span>Flutter</span><span>Laravel</span><span>MySQL</span><span>Demo + API modes</span><span>Full source code</span><span>Docs included</span></div>`,
};

const CSS = `@font-face{font-family:Inter;src:url('${FONT}');font-weight:100 900}
*{box-sizing:border-box;margin:0}html,body{width:1280px;height:720px;overflow:hidden}
body{font-family:Inter,sans-serif;color:#fff;position:relative;background:radial-gradient(900px 600px at 10% 0%,#1e3a8a,transparent 60%),radial-gradient(800px 500px at 100% 100%,#0c4a6e,transparent 55%),#0b1226;-webkit-font-smoothing:antialiased}
.brand{position:absolute;left:56px;top:44px;display:flex;align-items:center;gap:10px;font-size:26px}.brand b{font-weight:800;letter-spacing:-.02em}.lg svg{height:26px;width:auto;display:block}
.brand.big{font-size:40px;top:64px}.brand.big .lg svg{height:40px}
.left{position:absolute;left:56px;top:150px;width:470px}
.k{font-size:16px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#38BDF8;margin-bottom:16px}
h1{font-size:46px;line-height:1.08;font-weight:800;letter-spacing:-.025em}
p{font-size:21px;line-height:1.4;color:#CBD5E1;margin-top:18px;font-weight:500}
.chips{display:flex;flex-wrap:wrap;gap:10px;margin-top:26px}.chips span{border:1px solid #475569;border-radius:999px;padding:7px 16px;font-size:16px;font-weight:600;color:#E2E8F0}
.ph{position:absolute;background:#0a0f1f;box-shadow:0 0 0 2px #334155,0 24px 60px rgba(0,0,0,.55)}.ph img{display:block;object-fit:cover;object-position:top}
.win{position:absolute;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 0 0 1px #334155,0 24px 60px rgba(0,0,0,.5)}
.wb{height:22px;background:#E2E8F0;display:flex;align-items:center;gap:6px;padding:0 10px}.wb i{width:8px;height:8px;border-radius:50%;background:#CBD5E1;display:block}
.wi{overflow:hidden}.wi img{display:block;object-fit:cover;object-position:top}
.grid{position:absolute;left:56px;top:266px;width:1168px;display:grid;grid-template-columns:1fr 1fr;gap:12px 28px}
.ck{background:#111c3a;border:1px solid #334155;border-radius:14px;padding:11px 20px;font-size:22px;font-weight:600;display:flex;gap:14px;align-items:center}.ck b{color:#34D399;font-size:26px}
.foot{position:absolute;left:56px;right:56px;bottom:34px;display:flex;gap:12px}.foot span{background:#2563EB;border-radius:999px;padding:10px 22px;font-size:20px;font-weight:700}`;

(async () => {
  const b = await launch(); const p = await b.newPage(); await p.setViewport({ width: 1280, height: 720, deviceScaleFactor: 1 });
  const only = process.argv[2];
  for (const [name, body] of Object.entries(T)) {
    if (only && !name.startsWith(only)) continue;
    const f = path.join(WORK, name + '.html'); fs.writeFileSync(f, `<!doctype html><meta charset="utf-8"><style>${CSS}</style><body>${body}</body>`);
    await p.goto(url.pathToFileURL(f).href, { waitUntil: 'load' }); await sleep(500);
    await p.screenshot({ path: path.join(OUT, name + ".png") });
  }
  await b.close();
})();
