// Records the demo scenes (one mp4 clip each) from the running marketing instances.
// Usage: MKT_SCRATCH=<scratch> node record.js [scene,scene,...]   (default: all, in video order)
// Scenes share one browser session, so later scenes assume the state earlier ones create
// (e.g. 'leave' needs the phone logged in as Michael) -- run contiguous ranges.
const fs = require('fs'), path = require('path');
const { Stage, sleep } = require('./stage.js');
const { SCRATCH } = require('./lib.js');

const S = {};
const A = (id) => `a[href$="${id}"]`;
const click = (s, text, o = {}) => s.ptap(text, { exact: true, ...o });
const adm = (s, kicker, h, sub) => ['Web Admin · ' + kicker, h, sub];

// hidden helpers (not recorded): bring the shared browser to a logged-in state when re-recording single scenes
S._michael = async s => { await s.ev(() => { setMode('phone'); fadeIn(); }); await s.plogin('employee@peopleos.test'); await sleep(1500); };
S._admin = async s => { await s.ev(() => { setMode('admin'); fadeIn(); }); await s.clearAdminSession(); await s.adminLogin('admin@peopleos.test'); };

S.title = s => s.card('01_title', 'Manage people. Simplify work.', 'Workforce &amp; HR management you host yourself', ['Flutter app', 'Laravel API + Web Admin', 'MySQL'], 4200);

S.login = s => s.scene('02_login_home', 'phone', ['Employee app', 'Sign in, land on Home', "Today's shift, attendance status, leave and payslips at a glance."], async () => {
  await s.plogin('employee@peopleos.test');
  await sleep(2200);
  await s.pscroll(360, 8); await sleep(700); await s.pscroll(-360, 8); await sleep(500);
});

S.checkin = s => s.scene('03_checkin', 'phone', ['Attendance', 'GPS check-in inside the work area', 'The app sends the location; the server checks it against the work-location radius.'], async () => {
  await click(s, 'Check In'); await sleep(2600);
  await click(s, 'Confirm Check In'); await sleep(3200);
  await click(s, 'Done'); await sleep(2000);
});

S.history = s => s.scene('04_history', 'phone', ['Attendance', 'History and totals', 'Daily records with on-time / late status.'], async () => {
  await click(s, 'Attendance'); await sleep(2000);
  await s.pscroll(300, 10); await sleep(600);
});

S.leave = s => s.scene('05_leave_request', 'phone', ['Leave', 'Request leave with a live preview', 'Working days and the remaining balance are calculated before you submit.'], async () => {
  await click(s, 'Requests'); await sleep(1200);
  await click(s, 'Request leave'); await sleep(1000);
  await s.ptapAt(195, 135); await sleep(600);
  await s.ptap('Annual Leave 6 days'); await sleep(700);
  await s.ptapAt(104, 321); await sleep(400);
  await s.ptap('21, Wednesday, October 21'); await sleep(500); await s.ptap('OK', { exact: true });
  await s.ptapAt(285, 321); await sleep(400);
  await s.ptap('23, Friday, October 23'); await sleep(500); await s.ptap('OK', { exact: true });
  await sleep(2200); await s.ptapAt(195, 400); await s.p.keyboard.type('Family event', { delay: 60 }); await sleep(1200);
  await click(s, 'Submit'); await sleep(3000);
});

S.approve = s => s.scene('06_manager_approves', 'admin', adm(s, 'Manager', 'Manager approves the request', 'Amanda sees only her own team. The balance rules are enforced by the server.'), async () => {
  await s.adminLogin('manager@peopleos.test'); await sleep(900);
  await s.aclick(A('/admin/leave-requests'), 'Leave Requests'); await sleep(1500);
  await s.aclick('button', 'Approve', { row: 'Family event', wait: 1300 });
  await s.aclickLast('button', 'Approve', { wait: 2200 });
});

S.notify = s => s.scene('07_notification', 'phone', ['Notifications', 'The employee is notified', 'Approval shows up in the in-app inbox.'], async () => {
  await click(s, 'Home'); await sleep(1500);
  await click(s, 'Notifications'); await sleep(3000);
  await s.pscroll(200, 6); await sleep(600);
});

S.payslip = s => s.scene('08_payslip', 'phone', ['Payslips', 'Payslips with line-by-line detail', 'Earnings and deductions add up to net pay; download the PDF.'], async () => {
  await s.ptapAt(37, 24); await sleep(900);         // back from notifications
  await click(s, 'Payslip'); await sleep(2200);
  await s.ptap('August 2026, net pay'); await sleep(2200);
  await s.pscroll(420, 10); await sleep(1500);
  await s.pscroll(200, 6); await sleep(900);
  const dl = path.join(SCRATCH, 'downloads'); fs.rmSync(dl, { recursive: true, force: true }); fs.mkdirSync(dl, { recursive: true });
  await click(s, 'Download PDF'); await sleep(4000);
  const f = fs.readdirSync(dl).filter(x => x.endsWith('.pdf'))[0];
  if (f) {
    const kb = Math.round(fs.statSync(path.join(dl, f)).size / 1024);
    await s.ev((n, kb) => { hideCaption(); toast('Downloaded ' + n + ' (' + kb + ' KB), shown with pdf.js', 5500); }, f, kb);
    await s.ev(b => showPdf(b, 'payslip PDF'), fs.readFileSync(path.join(dl, f)).toString('base64')); await sleep(5500); await s.ev(() => hidePdf());
  } else console.log('NO PDF DOWNLOADED');
});

S.dark = s => s.scene('09_dark', 'phone', ['Theme', 'Light and dark', 'Follows the system, or choose in Settings.'], async () => {
  await s.ptapAt(37, 24); await sleep(900);
  await click(s, 'Profile'); await sleep(1500);
  await click(s, 'Settings'); await sleep(1200);
  await click(s, 'Dark'); await sleep(2800);
  await click(s, 'Light'); await sleep(1200);
});

S.selfie = s => s.scene('09b_selfie', 'phone', ['Attendance policy', 'GPS plus selfie where required', 'A strict-policy site (Surabaya) asks for a photo with each check-in.'], async () => {
  const cam = path.join(SCRATCH, 'fakecam.jpg');
  if (!fs.existsSync(cam)) { // one frame from Chrome's fake video device (--use-fake-device-for-media-stream)
    s.keepPages = true; const pg = await s.b.newPage(); await pg.goto('http://127.0.0.1:8020/__stage/brand.css');
    const d = await pg.evaluate(async () => { const st = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 } }); const v = document.createElement('video'); v.srcObject = st; v.muted = true; await v.play(); await new Promise(r => setTimeout(r, 1500)); const c = document.createElement('canvas'); c.width = v.videoWidth; c.height = v.videoHeight; c.getContext('2d').drawImage(v, 0, 0); st.getTracks().forEach(t => t.stop()); return c.toDataURL('image/jpeg', 0.92); });
    fs.writeFileSync(cam, Buffer.from(d.split(',')[1], 'base64')); await pg.close(); s.keepPages = false; await s.p.bringToFront();
  }
  await s.p.setGeolocation({ latitude: -7.2575, longitude: 112.7521, accuracy: 10 });
  await s.ptapAt(37, 24); await sleep(900);
  await click(s, 'Profile'); await sleep(1200);
  await click(s, 'Logout'); await sleep(1500); await click(s, 'Sign out'); await sleep(2500);
  await s.plogin('jessica@peopleos.test'); await sleep(1500);
  await click(s, 'Check In'); await sleep(3000);
  const [fc] = await Promise.all([s.p.waitForFileChooser({ timeout: 15000 }), click(s, 'Take')]);
  await fc.accept([cam]); await sleep(3000); await click(s, 'Use photo'); await sleep(2200);
  await click(s, 'Confirm Check In'); await sleep(3500);
});

S.dashboard = s => s.scene('10_dashboard', 'admin', adm(s, 'Admin', 'Dashboard', 'Headcount, leave, corrections, payroll status and expiring documents.'), async () => {
  await s.clearAdminSession(); await s.adminLogin('admin@peopleos.test'); await sleep(2500);
  await s.ascroll(300); await sleep(900); await s.ascroll(-300); await sleep(500);
});

S.employees = s => s.scene('11_employees', 'admin', adm(s, 'People', 'Employees', 'Dated assignments: department, position, manager, location, shift.'), async () => {
  await s.aclick(A('/admin/employees'), 'Employees'); await sleep(1800);
  await s.ascroll(300); await sleep(800);
  await s.aclick('a', 'Michael Carter', { wait: 2500 });
});

S.attendance = s => s.scene('12_attendance', 'admin', adm(s, 'Attendance', 'Daily attendance board', "Today's check-ins, including the one made from the phone."), async () => {
  await s.aclick(A('/admin/attendance'), 'Attendance', { wait: 2500 });
  await s.ascroll(250); await sleep(900);
});

S.payroll = s => s.scene('13_payroll', 'admin', adm(s, 'Payroll', 'Review, approve, finalize', 'HR prepares, a different person approves, then finalize makes it immutable.'), async () => {
  await s.aclick(A('/admin/payroll'), 'Payroll', { wait: 2000 });
  await s.aclick('a', 'September 2026', { wait: 2500 });
  await s.ascroll(400); await sleep(700); await s.ascroll(-400);
  await s.aclick('button,a', 'Approve', { wait: 1400 });
  await s.aclickLast('button', 'Approve payroll', { wait: 2600 });
  await s.aclick('button,a', 'Finalize', { wait: 1400 });
  await s.aclickLast('button', 'Finalize', { wait: 3500 });
});

S.payslips = s => s.scene('14_payslips_admin', 'admin', adm(s, 'Payroll', 'Payslips are generated on finalize', 'A PDF payslip per employee, stored privately.'), async () => {
  await s.aclick(A('/admin/payslips'), 'Payslips', { wait: 2500 });
  await s.ascroll(250); await sleep(600); await s.ascroll(-250);
  const href = await s.af.evaluate(() => document.querySelector('main a[href$="/pdf"]').href);
  await s.ahover('main a[href$="/pdf"]', null, { wait: 900 });
  const f = await s.fetchAdminFile(href, 'payslip.pdf');
  await s.ev(() => { hideCaption(); toast('Payslip PDF generated by the server (rendered here with pdf.js)', 5200); });
  await s.ev(b => showPdf(b, 'payslip.pdf'), fs.readFileSync(f).toString('base64')); await sleep(5500); await s.ev(() => hidePdf());
});

S.documents = s => s.scene('15_documents', 'admin', adm(s, 'Documents', 'Documents with expiry tracking', 'Private storage; expiring and expired items are flagged.'), async () => {
  await s.aclick(A('/admin/documents'), 'Documents', { wait: 2500 });
  await s.ascroll(250); await sleep(900);
});

S.reports = s => s.scene('16_reports', 'admin', adm(s, 'Reports', 'Run a report, export CSV or PDF', 'Attendance, leave, payroll and document reports.'), async () => {
  await s.aclick(A('/admin/reports'), 'Reports', { wait: 2000 });
  await s.aclick(A('/admin/reports/payroll.summary'), 'Payroll summary', { wait: 2500 });
  await s.af.evaluate(() => { const set = (n, v) => { const e = document.querySelector('input[name=' + n + ']'); e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); }; set('from', '2026-07-01'); set('to', '2026-09-30'); });
  await sleep(900);
  await s.aclick('button', 'Run report', { wait: 3000 });
  await s.ascroll(300); await sleep(600); await s.ascroll(-300);
  const dl = path.join(SCRATCH, 'downloads'); fs.rmSync(dl, { recursive: true, force: true }); fs.mkdirSync(dl, { recursive: true });
  const csv = await s.af.evaluate(() => document.querySelector('main a[href*="/export/csv"]').href);
  const pdf = await s.af.evaluate(() => document.querySelector('main a[href*="/export/pdf"]').href);
  await s.aclick('main a[href*="/export/csv"]', null, { wait: 2200 });
  const fc = fs.readdirSync(dl).filter(x => x.endsWith('.csv'))[0] || (await s.fetchAdminFile(csv, 'payroll-summary.csv'), 'payroll-summary.csv');
  await s.ev((n, kb) => toast('Exported ' + n + ' (' + kb + ' KB)', 2600), fc, Math.round(fs.statSync(path.join(dl, fc)).size / 102.4) / 10); await sleep(2800);
  await s.ahover('main a[href*="/export/pdf"]', null, { wait: 900 });
  await s.fetchAdminFile(pdf, 'payroll-summary.pdf');
  await s.ev(() => { hideCaption(); toast('PDF export (rendered here with pdf.js)', 5200); });
  await s.ev(b => showPdf(b, 'payroll-summary.pdf', 0.38), fs.readFileSync(path.join(dl, 'payroll-summary.pdf')).toString('base64')); await sleep(5500); await s.ev(() => hidePdf());
  console.log('EXPORTS', fs.readdirSync(dl));
});

S.outro = s => s.card('17_outro', 'Source code, yours to host', 'Employee app · Web Admin · REST API · documentation', ['Flutter', 'Laravel', 'MySQL', 'Demo and API modes'], 4200);

module.exports = { S };
if (require.main === module) (async () => {
  const names = process.argv[2] ? process.argv[2].split(',') : Object.keys(S).filter(k => !k.startsWith('_'));
  const s = new Stage(); await s.init();
  for (const n of names) { console.log('== scene', n); await S[n](s); }
  await s.b.close();
})();
