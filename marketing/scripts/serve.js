// Static server: Flutter web build at "/", marketing stage pages at "/__stage/".
const http = require('http'), fs = require('fs'), path = require('path');
const WEB = process.env.FLUTTER_WEB || path.join(process.env.MKT_SCRATCH, 'app/build/web');
const STAGE = path.join(__dirname, 'stage');
const types = { '.html':'text/html','.js':'text/javascript','.json':'application/json','.css':'text/css','.png':'image/png','.jpg':'image/jpeg','.svg':'image/svg+xml','.wasm':'application/wasm','.otf':'font/otf','.ttf':'font/ttf','.woff2':'font/woff2' };
http.createServer((req, res) => {
  let u = decodeURIComponent(req.url.split('?')[0]);
  let root = WEB;
  if (u.startsWith('/__pdfjs/')) { root = path.join(process.env.MKT_SCRATCH, 'node_modules/pdfjs-dist/build'); u = u.slice(8); } else if (u.startsWith('/__dl/')) { root = path.join(process.env.MKT_SCRATCH, 'downloads'); u = u.slice(5); } else if (u.startsWith('/__stage/')) { root = STAGE; u = u.slice(8); }
  if (u.endsWith('/')) u += 'index.html';
  const f = path.join(root, u);
  if (!f.startsWith(root) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('nf'); }
  res.writeHead(200, { 'Content-Type': types[path.extname(f)] || 'application/octet-stream', 'Cache-Control': 'no-store', 'Content-Length': fs.statSync(f).size });
  fs.createReadStream(f).pipe(res);
}).listen(8020, '127.0.0.1', () => console.log('serving 8020'));
