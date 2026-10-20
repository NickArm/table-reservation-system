/*
 * A stand-in for the application, for checking public/embed.js in a browser.
 *
 * It answers the two endpoints the widget calls, with the shapes
 * app/Api/V1/Payload.php promises, and pretends to be the booking page so the
 * redirect can be inspected.
 *
 * embed.js is read from public/ on every request rather than copied here. A
 * copy would drift, and a browser check running against a stale copy of the
 * thing it is meant to be checking is worse than no check at all.
 */
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const projectRoot = join(here, '..', '..', '..');

export const PORT = 8799;

const api = {
    '/api/internal/demo-beach-bar/booking-types': {
        data: [
            { slug: 'sunbeds', name: 'Sunbeds' },
            { slug: 'tables', name: 'Tables' },
        ],
    },
    '/api/internal/demo-beach-bar/times': {
        data: [
            {
                time: '10:00',
                starts_at: '2026-07-04T07:00:00+00:00',
                ends_at: '2026-07-04T16:00:00+00:00',
                available: 3,
                from_price_cents: 3000,
                from_price: '€30',
            },
            {
                time: '10:30',
                starts_at: '2026-07-04T07:30:00+00:00',
                ends_at: '2026-07-04T16:00:00+00:00',
                available: 2,
                from_price_cents: 3000,
                from_price: '€30',
            },
        ],
    },
};

/*
 * A venue whose subscription has run out. EnsureBookingsAreOpen answers every
 * first-party endpoint like this, and the widget is supposed to show the
 * sentence rather than an empty box (SPEC §4.4).
 */
const closed = {
    error: { code: 'bookings_closed', message: 'Online reservations are temporarily unavailable.' },
};

const types = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8' };

const server = createServer(async (req, res) => {
    const url = new URL(req.url, 'http://x');

    if (url.pathname.startsWith('/api/internal/lapsed-taverna/')) {
        res.writeHead(503, {
            'content-type': 'application/json',
            'access-control-allow-origin': '*',
        });
        res.end(JSON.stringify(closed));

        return;
    }

    if (api[url.pathname]) {
        res.writeHead(200, {
            'content-type': 'application/json',
            'access-control-allow-origin': '*',
        });
        res.end(JSON.stringify(api[url.pathname]));

        return;
    }

    // The booking page, reduced to the one thing worth asserting: what the
    // widget put in the address.
    if (url.pathname.startsWith('/reservation/')) {
        res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
        res.end('<!doctype html><title>Booking page</title><p id="landed">' + url.search + '</p>');

        return;
    }

    // Answered rather than left to 404, so the console stays empty and a
    // real error has nowhere to hide.
    if (url.pathname === '/favicon.ico') {
        res.writeHead(204);
        res.end();

        return;
    }

    // The real file, every time.
    if (url.pathname === '/embed.js') {
        res.writeHead(200, { 'content-type': types['.js'] });
        res.end(await readFile(join(projectRoot, 'public', 'embed.js')));

        return;
    }

    const file = url.pathname === '/' ? '/host.html' : url.pathname;

    try {
        const body = await readFile(join(here, file));
        const ext = file.slice(file.lastIndexOf('.'));

        res.writeHead(200, { 'content-type': types[ext] ?? 'text/plain; charset=utf-8' });
        res.end(body);
    } catch {
        res.writeHead(404);
        res.end('not here');
    }
});

server.listen(PORT, () => console.log('serving the harness on http://127.0.0.1:' + PORT));
