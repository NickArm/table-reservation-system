<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Venue;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records that the widget is alive on a customer's site (SPEC §9.4).
 *
 * The widget runs in the visitor's browser, so its calls to our first-party
 * endpoints arrive with the host page's domain in the Origin header. Writing
 * that down turns "I pasted it and nothing happened" into something the
 * builder page can answer by itself.
 *
 * Note the contrast with the public API, where an Origin header is a warning
 * sign: there it means a server token has ended up in client-side code. Here
 * a browser is exactly who should be calling, and the header is the evidence
 * that the installation worked.
 */
final class NoteWidgetSighting
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        if ($origin !== null) {
            $this->note($origin);
        }

        /** @var Response $response */
        $response = $next($request);

        /*
         * The widget is cross-origin by definition, so the browser will not
         * hand it a response without these. Open to any site: the data is the
         * same availability anyone sees on the public booking page, and a
         * venue's embed should work wherever the venue puts it.
         */
        return $response
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Vary', 'Origin');
    }

    private function note(string $origin): void
    {
        $venue = Venue::query()->first();

        if ($venue === null) {
            return;
        }

        // Once a minute at most. "Seen twenty minutes ago" is all the builder
        // page shows, and a busy homepage would otherwise have us writing the
        // same row on every date change a visitor makes.
        $fresh = $venue->widget_last_seen_at !== null
            && $venue->widget_last_seen_at->gt(now()->subMinute());

        if ($fresh && $venue->widget_last_origin === $origin) {
            return;
        }

        $venue->forceFill([
            'widget_last_origin' => $origin,
            'widget_last_seen_at' => now(),
        ])->save();
    }
}
