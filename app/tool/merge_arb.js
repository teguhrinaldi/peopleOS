// Merges lib/l10n/fragments/*.{en,id}.json into lib/l10n/app_{en,id}.arb and
// runs `flutter gen-l10n`. Idempotent. Usage: node tool/merge_arb.js [--no-gen]
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const dir = path.join(__dirname, '..', 'lib', 'l10n');
const fragDir = path.join(dir, 'fragments');
let failed = false;
const merged = {};
for (const lang of ['en', 'id']) {
  const out = { '@@locale': lang };
  const owner = {};
  for (const f of fs.readdirSync(fragDir).filter(n => n.endsWith(`.${lang}.json`)).sort()) {
    let json;
    try { json = JSON.parse(fs.readFileSync(path.join(fragDir, f), 'utf8')); }
    catch (e) { console.error(`INVALID JSON ${f}: ${e.message}`); failed = true; continue; }
    for (const [k, v] of Object.entries(json)) {
      if (k in out && !k.startsWith('@@')) { console.error(`DUPLICATE key ${k} in ${f} (also in ${owner[k]})`); failed = true; continue; }
      out[k] = v; owner[k] = f;
    }
  }
  merged[lang] = out;
}
// Template (en) needs metadata for placeholders; id may omit it. Report missing translations.
const enKeys = Object.keys(merged.en).filter(k => !k.startsWith('@'));
const idKeys = new Set(Object.keys(merged.id).filter(k => !k.startsWith('@')));
const missing = enKeys.filter(k => !idKeys.has(k));
if (missing.length) { console.error(`MISSING id translations (${missing.length}): ${missing.slice(0, 15).join(', ')}${missing.length > 15 ? ' ...' : ''}`); }
for (const lang of ['en', 'id']) {
  fs.writeFileSync(path.join(dir, `app_${lang}.arb`), JSON.stringify(merged[lang], null, 2) + '\n');
}
console.log(`merged: ${enKeys.length} en keys, ${idKeys.size} id keys`);
if (failed) process.exit(1);
if (!process.argv.includes('--no-gen')) {
  execSync('flutter gen-l10n', { cwd: path.join(__dirname, '..'), stdio: 'inherit' });
}
