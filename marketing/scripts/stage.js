// Stage driver: 1920x1080 composition page with the live Flutter app (phone) and live Web Admin (iframe).
const fs = require('fs'), path = require('path'), cp = require('child_process');
const { launch, sleep, fakeClock, offsetSec, SCRATCH } = require('./lib.js');
const ph = require('./phone.js');

function findFfmpeg() {
  const base = 'C:/Users/Teguh/AppData/Local/Microsoft/WinGet/Packages/Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe';
  const d = fs.readdirSync(base).find(x => x.startsWith('ffmpeg-'));
  return `${base}/${d}/bin/ffmpeg.exe`;
}
const FFMPEG = findFfmpeg();
const PH = { x: 430 + 21, y: 97 + 21 }, AD = { x: 40, y: 28 + 44 };
const CLIPS = path.join(SCRATCH, 'clips');
fs.mkdirSync(CLIPS, { recursive: true });
fs.mkdirSync(path.join(SCRATCH, 'downloads'), { recursive: true });

class Stage {
  async init(geo = { latitude: -6.92181, longitude: 107.60712, accuracy: 12 }) {
    this.b = await launch(); this.p = await this.b.newPage();
    await this.p.setViewport({ width: 1920, height: 1080, deviceScaleFactor: 2 });
    await this.b.defaultBrowserContext().overridePermissions('http://127.0.0.1:8020', ['geolocation', 'camera']);
    await fakeClock(this.p, offsetSec());
    await this.p.setGeolocation(geo);
    this.p.on('pageerror', e => console.log('pageerror', String(e).slice(0, 200)));
    this.b.on("targetcreated", async t => { if (!this.keepPages && t.type() === "page" && t !== this.p.target()) setTimeout(async () => { try { const pg = await t.page(); if (pg && pg !== this.p) await pg.close(); } catch (e) {} }, 600); });
    this.cdp = await this.p.createCDPSession();
    await this.cdp.send('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: path.join(SCRATCH, 'downloads') }).catch(() => {});
    // Headless Chrome has no PDF viewer: it turns application/pdf responses into empty 204 downloads before CORS,
    // which breaks the app's own Download PDF button (XHR). For '/pdf' API GETs the harness performs the same request
    // from Node (same URL, same Authorization header) and hands the real bytes back labelled as octet-stream.
    await this.cdp.send('Fetch.enable', { patterns: [{ urlPattern: '*/api/v1/*/pdf*', requestStage: 'Request' }] });
    this.cdp.on('Fetch.requestPaused', async e => {
      try {
        if (e.request.method !== 'GET') return await this.cdp.send('Fetch.continueRequest', { requestId: e.requestId });
        const hdr = { ...e.request.headers }; delete hdr.Origin; delete hdr.Referer;
        const r = await fetch(e.request.url, { headers: hdr });
        const buf = Buffer.from(await r.arrayBuffer());
        await this.cdp.send('Fetch.fulfillRequest', { requestId: e.requestId, responseCode: r.status, responseHeaders: [{ name: 'Content-Type', value: 'application/octet-stream' }, { name: 'Access-Control-Allow-Origin', value: '*' }], body: buf.toString('base64') });
      } catch (err) { console.log('fetch-intercept error', err.message); try { await this.cdp.send('Fetch.continueRequest', { requestId: e.requestId }); } catch (e2) {} }
    });
    await this.p.goto('http://127.0.0.1:8020/__stage/stage.html', { waitUntil: 'load' });
    await sleep(6000);
    this.pf = this.p.frames().find(f => f !== this.p.mainFrame() && f.url().startsWith('http://127.0.0.1:8020/') && !f.url().includes('__stage'));
    await ph.enableSemantics(this.pf);
  }
  async ev(fn, ...a) { return this.p.evaluate(fn, ...a); }
  get af() { return this.p.frames().find(f => f.url().startsWith('http://127.0.0.1:8010')); }
  async gotoAdmin(url) {
    await this.ev(u => { document.getElementById('af').src = u; }, url);
    for (let i = 0; i < 50; i++) { await sleep(300); const f = this.af; if (f && f.url().startsWith(url.split('?')[0].replace(/\/$/, ''))) break; }
    await sleep(1200);
  }
  // ---- recording: one mp4 clip per scene, frames timed from CDP screencast timestamps ----
  async rec(name, fn) {
    const dir = path.join(SCRATCH, 'frames', name); fs.rmSync(dir, { recursive: true, force: true }); fs.mkdirSync(dir, { recursive: true });
    const frames = []; let n = 0;
    const onF = async ev => {
      const f = path.join(dir, String(n++).padStart(6, '0') + '.jpg');
      fs.writeFileSync(f, Buffer.from(ev.data, 'base64')); frames.push({ f, t: ev.metadata.timestamp });
      this.cdp.send('Page.screencastFrameAck', { sessionId: ev.sessionId }).catch(() => {});
    };
    this.cdp.on('Page.screencastFrame', onF);
    await this.cdp.send('Page.startScreencast', { format: 'jpeg', quality: 88, maxWidth: 1920, maxHeight: 1080, everyNthFrame: 1 });
    await this.ev(() => fadeIn()); await sleep(500);
    let err; try { await fn(); } catch (e) { err = e; console.log('SCENE ERROR', name, e.message); }
    await this.ev(() => fadeOut()); await sleep(700);
    await this.cdp.send('Page.stopScreencast'); this.cdp.off('Page.screencastFrame', onF); await sleep(200);
    let list = '';
    for (let i = 0; i < frames.length; i++) {
      const d = i < frames.length - 1 ? frames[i + 1].t - frames[i].t : 0.3;
      list += `file '${frames[i].f.replace(/\\/g, '/')}'\nduration ${Math.max(d, 0.001).toFixed(4)}\n`;
    }
    list += `file '${frames[frames.length - 1].f.replace(/\\/g, '/')}'\n`;
    fs.writeFileSync(path.join(dir, 'list.txt'), list);
    const out = path.join(CLIPS, name + '.mp4');
    cp.execFileSync(FFMPEG, ['-y', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', path.join(dir, 'list.txt'), '-vf', 'fps=30,scale=1920:1080,format=yuv420p', '-c:v', 'libx264', '-preset', 'medium', '-crf', '18', '-r', '30', out]);
    console.log('clip', name, frames.length, 'frames', err ? 'WITH ERROR' : 'ok');
    return !err;
  }
  async scene(name, mode, cap, fn) { // cap = [kicker, headline, sub]
    return this.rec(name, async () => {
      await this.ev(m => { setMode(m); hideCard(); }, mode);
      if (cap) await this.ev(a => caption(...a), cap); else await this.ev(() => hideCaption());
      await sleep(900); await fn();
    });
  }
  async card(name, title, sub, chips, ms = 3600) {
    return this.rec(name, async () => { await this.ev(([t, s, c]) => { setMode('card'); hideCaption(); showCard(t, s, c); }, [title, sub, chips]); await sleep(ms); });
  }
  // ---- phone (Flutter web, driven through its accessibility tree) ----
  async ptap(text, o = {}) {
    const f = await ph.find(this.pf, text, o); if (!f) throw new Error('phone: not found ' + text);
    await this.ptapAt(f.x, f.y);
  }
  async ptapAt(x, y) {
    await this.ev((x, y) => tapFx(x, y), PH.x + x, PH.y + y);
    await this.p.mouse.move(PH.x + x, PH.y + y); await sleep(120); await this.p.mouse.down(); await sleep(70); await this.p.mouse.up(); await sleep(1100);
  }
  async ptype(x, y, text) { await this.ptapAt(x, y); await this.p.keyboard.type(text, { delay: 55 }); await sleep(400); }
  async pscroll(dy, steps = 10) {
    await this.p.mouse.move(PH.x + 195, PH.y + 500);
    for (let i = 0; i < steps; i++) { await this.p.mouse.wheel({ deltaY: dy / steps }); await sleep(60); }
    await sleep(600);
  }
  async selectAll() { await this.p.keyboard.down("Control"); await this.p.keyboard.press("KeyA"); await this.p.keyboard.up("Control"); }
  async plogin(email) {
    await this.ptapAt(195, 395); await this.selectAll(); await this.p.keyboard.type(email, { delay: 55 }); await this.ptapAt(195, 488); await this.selectAll(); await this.p.keyboard.type('password', { delay: 55 }); await this.ptapAt(195, 612); await sleep(3500);
  }
  // ---- admin (server-rendered Blade/Alpine in an iframe) ----
  async arect(sel, text, row) {
    return this.af.evaluate((sel, text, row) => {
      const els = [...document.querySelectorAll(sel)].filter(e => e.offsetWidth > 0 && (!text || e.textContent.replace(/\s+/g, ' ').trim().includes(text)) &&
        (!row || ((e.closest('tr,li,[data-row]') || { textContent: '' }).textContent.replace(/\s+/g, ' ').includes(row))));
      els.sort((a, b) => a.offsetWidth * a.offsetHeight - b.offsetWidth * b.offsetHeight);
      const e = els[0]; if (!e) return null; e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect();
      return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    }, sel, text, row);
  }
  async aclick(sel, text, o = {}) {
    let r = await this.arect(sel, text, o.row); if (!r) throw new Error('admin: not found ' + sel + ' ' + text);
    await sleep(250); r = await this.arect(sel, text, o.row);
    const X = AD.x + r.x, Y = AD.y + r.y;
    await this.ev((x, y) => moveCursor(x, y), X, Y); await sleep(750);
    await this.ev((x, y) => tapFx(x, y), X, Y);
    await this.p.mouse.move(X, Y); await sleep(100); await this.p.mouse.click(X, Y);
    await sleep(o.wait ?? 1800);
  }
  async aclickLast(sel, text, o = {}) {
    await this.af.evaluate((sel, text) => { document.querySelectorAll("[data-mkt]").forEach(e => e.removeAttribute("data-mkt")); const b = [...document.querySelectorAll(sel)].filter(e => e.offsetWidth && e.textContent.trim().includes(text)).pop(); if (b) b.setAttribute("data-mkt", "1"); }, sel, text);
    return this.aclick("[data-mkt]", null, o);
  }
  // Fetch a file through the admin session (headless Chrome has no PDF viewer / new-tab download), save it into downloads/.
  async fetchAdminFile(href, name, email = 'admin@peopleos.test') {
    // Node-side download with its own admin session: Chrome fails on large PDF responses from PHP's built-in
    // server on Windows (see README), curl/Node get them fine. Same endpoint, same bytes.
    const B = 'http://127.0.0.1:8010', jar = {};
    const keep = r => (r.headers.getSetCookie() || []).forEach(c => { const [kv] = c.split(';'); const i = kv.indexOf('='); jar[kv.slice(0, i)] = kv.slice(i + 1); });
    const ck = () => Object.entries(jar).map(([k, v]) => k + '=' + v).join('; ');
    let r = await fetch(B + '/login'); keep(r); const html = await r.text(); const tok = /name="_token" value="([^"]+)"/.exec(html)[1];
    r = await fetch(B + '/login', { method: 'POST', redirect: 'manual', headers: { Cookie: ck(), 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ _token: tok, email, password: 'password' }) }); keep(r);
    r = await fetch(href, { headers: { Cookie: ck() } });
    const buf = Buffer.from(await r.arrayBuffer()); if (r.status !== 200 || buf.length < 100) throw new Error('download failed ' + r.status + ' ' + href);
    const f = path.join(SCRATCH, 'downloads', name); fs.writeFileSync(f, buf); return f;
  }
  async ahover(sel, text, o = {}) { // move the cursor over an element without clicking
    const r = await this.arect(sel, text, o.row); if (!r) throw new Error("admin: not found " + sel);
    await this.ev((x, y) => moveCursor(x, y), AD.x + r.x, AD.y + r.y); await sleep(o.wait ?? 900);
  }
  async atype(sel, text) { await this.aclick(sel, null, { wait: 200 }); await this.p.keyboard.type(text, { delay: 50 }); await sleep(300); }
  async ascroll(dy, steps = 12) {
    await this.p.mouse.move(AD.x + 900, AD.y + 450);
    for (let i = 0; i < steps; i++) { await this.p.mouse.wheel({ deltaY: dy / steps }); await sleep(50); }
    await sleep(500);
  }
  async adminLogin(email, pw = 'password') {
    await this.gotoAdmin('http://127.0.0.1:8010/login');
    await this.atype('input[name=email]', email); await this.atype('input[name=password]', pw);
    await this.aclick('button[type=submit]', null, { wait: 500 });
    for (let i = 0; i < 40 && this.af.url().includes('/login'); i++) await sleep(250);
    await sleep(1500);
  }
  async clearAdminSession() { await this.cdp.send('Network.enable'); await this.cdp.send('Network.clearBrowserCookies'); }
}
module.exports = { Stage, sleep, CLIPS, FFMPEG, SCRATCH };
