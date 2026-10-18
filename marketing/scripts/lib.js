const path = require('path');
const SCRATCH = process.env.MKT_SCRATCH;
const puppeteer = require(path.join(SCRATCH, 'node_modules/puppeteer-core'));
const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const sleep = ms => new Promise(r => setTimeout(r, ms));
// Fresh Chrome profile every run (no saved logins) with the password manager disabled:
// its 'save password' prompt otherwise swallows input events after the Flutter login form is submitted.
function freshProfile() {
  const fs = require('fs'), os = require('os');
  const dir = fs.mkdtempSync(path.join(SCRATCH, 'profile-'));
  fs.mkdirSync(path.join(dir, 'Default'), { recursive: true });
  fs.writeFileSync(path.join(dir, 'Default', 'Preferences'), JSON.stringify({ credentials_enable_service: false, credentials_enable_autosignin: false, profile: { password_manager_enabled: false, password_manager_leak_detection: false }, autofill: { profile_enabled: false, credit_card_enabled: false } }));
  return dir;
}
async function launch(extra = {}) {
  return puppeteer.launch({
    executablePath: CHROME, headless: 'new', defaultViewport: null, protocolTimeout: 180000, userDataDir: freshProfile(),
    args: ['--no-sandbox', '--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream',
      '--autoplay-policy=no-user-gesture-required', '--hide-scrollbars', '--disable-save-password-bubble', '--password-store=basic', '--disable-features=PasswordLeakDetection,AutofillServerCommunication,PasswordManagerOnboarding', '--disable-renderer-backgrounding', '--disable-background-timer-throttling', '--disable-backgrounding-occluded-windows', '--force-color-profile=srgb', '--font-render-hinting=none'],
    ...extra,
  });
}
module.exports = { puppeteer, launch, sleep, SCRATCH };

// Shift the page clock (matches the server's MKT_OFFSET_SEC) and use the company timezone.
async function fakeClock(page, offsetSec) {
  await page.emulateTimezone('Asia/Jakarta');
  await page.evaluateOnNewDocument((off) => {
    const D = Date, shift = off * 1000, realNow = D.now.bind(D);
    function F(...a) { return a.length === 0 ? new D(realNow() + shift) : new D(...a); }
    F.prototype = D.prototype; F.now = () => realNow() + shift; F.parse = D.parse; F.UTC = D.UTC;
    window.Date = F;
    // geolocation fixes carry the real clock; shift their timestamp too so the server does not see them as stale
    const g = navigator.geolocation, w = cb => cb && (p => cb({ coords: p.coords, timestamp: p.timestamp + shift }));
    const gc = g.getCurrentPosition.bind(g), wp = g.watchPosition.bind(g);
    g.getCurrentPosition = (ok, er, o) => gc(w(ok), er, o); g.watchPosition = (ok, er, o) => wp(w(ok), er, o);
  }, offsetSec);
}
module.exports.fakeClock = fakeClock;
module.exports.offsetSec = () => +require('fs').readFileSync(SCRATCH + '/offset.txt', 'utf8').trim();
