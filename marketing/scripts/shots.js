// Fresh screenshots of the running marketing instances (used by the thumbnails).
// Usage: MKT_SCRATCH=<scratch> node shots.js   -> <scratch>/shots/*.png
const fs = require('fs'), path = require('path');
const { launch, sleep, fakeClock, offsetSec, SCRATCH } = require('./lib.js');
const ph = require('./phone.js');
const OUT = path.join(SCRATCH, 'shots'); fs.mkdirSync(OUT, { recursive: true });
const ADMIN = 'http://127.0.0.1:8010', APP = 'http://127.0.0.1:8020/';

async function adminSession(b, email) {
  const ctx = await b.createBrowserContext(); const p = await ctx.newPage();
  await p.setViewport({ width: 1440, height: 900, deviceScaleFactor: 2 });
  await p.goto(ADMIN + '/login'); await p.type('input[name=email]', email); await p.type('input[name=password]', 'password');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle2' }), p.click('button[type=submit]')]);
  return p;
}
async function adminShot(p, url, name, scroll = 0) {
  await p.goto(ADMIN + url, { waitUntil: 'networkidle2' }); await sleep(900);
  if (scroll) { await p.evaluate(y => window.scrollTo(0, y), scroll); await sleep(400); }
  await p.screenshot({ path: path.join(OUT, name + '.png') }); console.log('shot', name);
}
async function phoneSession(b, email, geo) {
  const ctx = await b.createBrowserContext(); await ctx.overridePermissions("http://127.0.0.1:8020", ["geolocation"]);
  const p = await ctx.newPage();
  await p.setViewport({ width: 390, height: 844, deviceScaleFactor: 2 });
  await fakeClock(p, offsetSec()); await p.setGeolocation(geo);
  await p.goto(APP, { waitUntil: 'networkidle2' }); await sleep(3500); await ph.enableSemantics(p);
  const tap = async (x, y) => { await p.mouse.move(x, y); await sleep(100); await p.mouse.down(); await sleep(60); await p.mouse.up(); await sleep(1200); };
  await tap(195, 395); await p.keyboard.type(email, { delay: 20 }); await tap(195, 488); await p.keyboard.type('password', { delay: 20 }); await tap(195, 612); await sleep(4500);
  p.tapText = async (t, o = { exact: true }) => { const f = await ph.find(p, t, o); if (!f) throw new Error('nf ' + t); await tap(f.x, f.y); };
  p.tapAt = tap;
  p.shot = async n => { await p.screenshot({ path: path.join(OUT, n + '.png') }); console.log('shot', n); };
  return p;
}

(async () => {
  const b = await launch();
  await b.defaultBrowserContext().overridePermissions('http://127.0.0.1:8020', ['geolocation']);
  const only = process.argv[2];
  const run = async (n, fn) => { if (only && !only.split(',').includes(n)) return; try { await fn(); } catch (e) { console.log('FAIL', n, e.message); } };

  await run('admin', async () => {
    const p = await adminSession(b, 'admin@peopleos.test');
    await adminShot(p, '/admin', 'admin_dashboard');
    await adminShot(p, '/admin/employees', 'admin_employees');
    await adminShot(p, '/admin/attendance', 'admin_attendance');
    await adminShot(p, '/admin/payroll/3', 'admin_payroll_period');
    await adminShot(p, '/admin/payroll', 'admin_payroll_list');
    await adminShot(p, '/admin/reports', 'admin_reports_catalogue');
    await adminShot(p, '/admin/reports/payroll.summary?from=2026-07-01&to=2026-09-30', 'admin_report_payroll');
    await adminShot(p, '/admin/reports/attendance.daily', 'admin_report_attendance');
    await adminShot(p, '/admin/documents', 'admin_documents');
    await p.close();
  });
  await run('manager', async () => {
    const p = await adminSession(b, 'manager@peopleos.test');
    await adminShot(p, '/admin/leave-requests?tab=history', 'admin_leave_history');
    await adminShot(p, '/admin/leave-requests', 'admin_leave_review');
    await p.close();
  });
  await run('michael', async () => {
    const p = await phoneSession(b, 'employee@peopleos.test', { latitude: -6.92181, longitude: 107.60712, accuracy: 12 });
    await p.shot('app_home');
    await p.tapText('Attendance'); await p.shot('app_attendance');
    await p.tapText('Requests'); await sleep(800); await p.shot('app_requests');
    await p.tapText('Request leave'); await p.tapAt(195, 135); await p.tapText('Annual Leave', { exact: false });
    await p.tapAt(104, 321); await p.tapText('28, Wednesday, October 28', { exact: false }); await p.tapText('OK');
    await p.tapAt(285, 321); await p.tapText('30, Friday, October 30', { exact: false }); await p.tapText('OK'); await sleep(1500); await p.shot('app_leave_form');
    await p.close();
    const q = await phoneSession(b, 'employee@peopleos.test', { latitude: -6.92181, longitude: 107.60712, accuracy: 12 });
    await q.tapText('Payslip'); await q.shot('app_payslips');
    await q.tapText('August 2026, net pay', { exact: false }); await sleep(1200); await q.shot('app_payslip_detail');
    await q.close();
  });
  await run('olivia', async () => {
    const p = await phoneSession(b, 'olivia@peopleos.test', { latitude: -6.92181, longitude: 107.60712, accuracy: 12 });
    await p.shot('app_home_olivia');
    await p.tapText('Check In'); await sleep(2500); await p.shot('app_confirm_location');
    await p.close();
  });
  await b.close();
})();
