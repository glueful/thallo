// Zero-dependency static file server for the runtime-browser smoke gate.
//
// Why not `php -S` or `http-server`: PHP happens to be on this machine's PATH
// today, but the CI runner for this gate never installs PHP (it only needs
// Node + a Chromium binary), and pulling in an npm static-server package is
// one more supply-chain dependency for a two-line job. Node's own `http`
// module is guaranteed present everywhere `npm test` already runs.
//
// Serves the REPO ROOT (not this package) so fixtures can reference the real,
// uncopied theme assets and runtime.js by their real repo-relative path, e.g.
// /packages/thallo-render/runtime/runtime.js.
'use strict';

const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '..', '..');
const PORT = Number(process.env.RUNTIME_BROWSER_PORT) || 4789;

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.woff2': 'font/woff2',
  '.woff': 'font/woff'
};

const server = http.createServer((req, res) => {
  let urlPath = decodeURIComponent((req.url || '/').split('?')[0]);
  if (urlPath.endsWith('/')) { urlPath += 'index.html'; }

  // Resolve + confine to ROOT: reject any traversal outside the repo.
  const filePath = path.normalize(path.join(ROOT, urlPath));
  if (!filePath.startsWith(ROOT)) {
    res.writeHead(403);
    res.end('Forbidden');
    return;
  }

  fs.readFile(filePath, (err, data) => {
    if (err) {
      res.writeHead(404, { 'Content-Type': 'text/plain' });
      res.end('Not found: ' + urlPath);
      return;
    }
    const ext = path.extname(filePath).toLowerCase();
    res.writeHead(200, { 'Content-Type': MIME[ext] || 'application/octet-stream' });
    res.end(data);
  });
});

server.listen(PORT, '127.0.0.1', () => {
  // eslint-disable-next-line no-console
  console.log('runtime-browser static server listening on http://127.0.0.1:' + PORT);
});
