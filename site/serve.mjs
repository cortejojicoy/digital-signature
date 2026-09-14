/**
 * Serves `site/dist` for local preview.
 *
 * Node built-ins only, like the builder. Directory URLs resolve to their
 * index.html so the local site behaves the way GitHub Pages will — without
 * that, every link in the nav would 404 locally and look like a build bug.
 *
 *   node site/build.mjs && node site/serve.mjs
 */

import { createReadStream } from 'node:fs';
import { stat } from 'node:fs/promises';
import { createServer } from 'node:http';
import { extname, join, normalize, resolve } from 'node:path';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const DIST = resolve(dirname(fileURLToPath(import.meta.url)), 'dist');
const PORT = Number(process.env.PORT ?? 4173);

const TYPES = {
    '.html': 'text/html; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.svg': 'image/svg+xml',
};

const server = createServer(async (req, res) => {
    const url = new URL(req.url, 'http://localhost');

    // normalize() then a prefix check: without it, `/../../etc/passwd` would
    // walk straight out of the directory being served.
    const requested = normalize(decodeURIComponent(url.pathname));
    let path = join(DIST, requested);

    if (!path.startsWith(DIST)) {
        res.writeHead(403).end('Forbidden');
        return;
    }

    try {
        const info = await stat(path);
        if (info.isDirectory()) path = join(path, 'index.html');
    } catch {
        path = join(DIST, '404.html');
        res.statusCode = 404;
    }

    try {
        await stat(path);
    } catch {
        res.writeHead(404, { 'Content-Type': 'text/plain' }).end('Not found');
        return;
    }

    res.setHeader('Content-Type', TYPES[extname(path)] ?? 'application/octet-stream');
    res.setHeader('Cache-Control', 'no-store');
    createReadStream(path).pipe(res);
});

server.listen(PORT, () => {
    console.log(`site: http://localhost:${PORT}`);
});
