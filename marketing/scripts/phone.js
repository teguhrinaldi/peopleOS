// Helpers to drive the Flutter web app through its accessibility (semantics) tree.
const { sleep } = require('./lib.js');
// Frame = the puppeteer Page or Frame that hosts the Flutter app.
async function enableSemantics(f) {
  await f.evaluate(() => document.querySelector('flt-semantics-placeholder')?.click());
  await sleep(800);
}
async function dump(f) {
  return f.evaluate(() => [...document.querySelectorAll('flt-semantics')].filter(e => e.offsetWidth > 0).map(e => {
    const r = e.getBoundingClientRect();
    return `${e.getAttribute('role') || '-'} | ${(e.getAttribute('aria-label') || e.textContent || '').replace(/\s+/g,' ').slice(0, 70)} | ${Math.round(r.x)},${Math.round(r.y)} ${Math.round(r.width)}x${Math.round(r.height)}`;
  }).join('\n'));
}
// Find the smallest semantics node whose text matches; returns its rect in the frame viewport.
async function find(f, text, { nth = 0, exact = false } = {}) {
  return f.evaluate((text, nth, exact) => {
    const els = [...document.querySelectorAll('flt-semantics')].filter(e => e.offsetWidth > 0).filter(e => {
      const t = (e.getAttribute('aria-label') || e.textContent || '').replace(/\s+/g, ' ').trim();
      return exact ? t === text : t.includes(text);
    });
    // prefer the deepest (smallest area) nodes
    els.sort((a, b) => a.offsetWidth * a.offsetHeight - b.offsetWidth * b.offsetHeight);
    const e = els[nth]; if (!e) return null;
    const r = e.getBoundingClientRect();
    return { x: r.x + r.width / 2, y: r.y + r.height / 2, w: r.width, h: r.height };
  }, text, nth, exact);
}
module.exports = { enableSemantics, dump, find };
