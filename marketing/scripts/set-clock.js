// Writes the clock offset (seconds) used by the marketing BE (bootstrap/mkt_clock.php) and the browser,
// so that "now" is Monday 2026-10-12 07:50 Asia/Jakarta at the moment this runs (a working day, inside the shift).
const fs = require('fs'), path = require('path');
const target = Date.parse(process.argv[2] || '2026-10-12T00:50:00Z');
const off = Math.round((target - Date.now()) / 1000);
fs.writeFileSync(path.join(process.env.MKT_SCRATCH, 'offset.txt'), String(off)); console.log('offset', off);
