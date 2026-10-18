// Concatenates the scene clips into marketing/video/peopleos_demo.mp4 (H.264, 1920x1080, 30 fps, silent).
// Footage clips are time-compressed uniformly so the total lands near TARGET seconds; title cards stay 1x.
// Usage: MKT_SCRATCH=<scratch> node assemble.js [targetSeconds=112]
const fs = require('fs'), path = require('path'), cp = require('child_process');
const { SCRATCH } = require('./lib.js');
const { FFMPEG } = require('./stage.js');
const FFPROBE = FFMPEG.replace('ffmpeg.exe', 'ffprobe.exe');
const CLIPS = path.join(SCRATCH, 'clips'), OUT = path.join(__dirname, '..', 'video'), WORK = path.join(SCRATCH, 'assemble');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(WORK, { recursive: true });
const TARGET = +(process.argv[2] || 112);
const dur = f => +cp.execFileSync(FFPROBE, ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', f]).toString().trim();
const clips = fs.readdirSync(CLIPS).filter(f => f.endsWith('.mp4')).sort().map(f => ({ f, p: path.join(CLIPS, f), d: dur(path.join(CLIPS, f)), card: /title|outro/.test(f) }));
const cardT = clips.filter(c => c.card).reduce((a, c) => a + c.d, 0), footT = clips.filter(c => !c.card).reduce((a, c) => a + c.d, 0);
const speed = Math.max(1, footT / (TARGET - cardT));
console.log('clips', clips.length, 'cards', cardT.toFixed(1), 'footage', footT.toFixed(1), 'speed', speed.toFixed(2));
const parts = [];
for (const c of clips) {
  const out = path.join(WORK, c.f), sp = c.card ? 1 : speed;
  cp.execFileSync(FFMPEG, ['-y', '-loglevel', 'error', '-i', c.p, '-vf', `setpts=PTS/${sp},fps=30,scale=in_range=full:out_range=limited,format=yuv420p`, '-an', '-c:v', 'libx264', '-preset', 'medium', '-crf', '17', '-r', '30', out]);
  parts.push(out);
}
fs.writeFileSync(path.join(WORK, 'list.txt'), parts.map(p => `file '${p.replace(/\\/g, '/')}'`).join('\n'));
const final = path.join(OUT, 'peopleos_demo.mp4');
cp.execFileSync(FFMPEG, ['-y', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', path.join(WORK, 'list.txt'), '-c:v', 'libx264', '-preset', 'medium', '-crf', '18', '-pix_fmt', 'yuv420p', '-r', '30', '-movflags', '+faststart', '-an', final]);
console.log('wrote', final, 'duration', dur(final).toFixed(1));
