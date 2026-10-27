<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Illuminate\Http\Response;

/**
 * One sitemap for every booking page worth indexing (SPEC §33.2).
 *
 * The last piece of the discovery work. Structured data tells a search engine
 * what a page *is*; this is what tells it the page exists at all — otherwise
 * a brand new venue waits for somebody to link to it.
 *
 * Two decisions worth stating.
 *
 * **Only venues that should be indexed.** The same rule the page itself uses:
 * a profile under 60% complete, or one whose owner has turned indexing off,
 * stays out. Listing four hundred near-identical thin pages in a sitemap is
 * the fastest way to have the whole domain treated as thin (§33.1) — which
 * would cost every venue here, not just the empty ones.
 *
 * **Built on request, not stored.** With a few hundred venues this is one
 * query and a string; a generated file would be a cron job, a stale window
 * and somewhere for it to go wrong. If it ever stops being cheap, the answer
 * is a cache entry, not a file.
 */
final class SitemapController extends Controller
{
    public function __invoke(TenantContext $context): Response
    {
        /*
         * Read across accounts: a sitemap is the platform's own document and
         * no tenant is bound on a request to /sitemap.xml. Explicit rather
         * than relying on the scope being absent, which is what the whole
         * tenancy design is built to refuse.
         */
        $venues = $context->withoutScoping(static fn () => Venue::query()
            ->acrossTenants()
            ->with('tenant')
            ->where('is_indexable', true)
            ->get()
            ->filter(static fn (Venue $venue): bool => $venue->shouldBeIndexed()));

        $urls = [];

        // The marketing page first, which is the only one of ours that wants
        // indexing at all.
        $urls[] = ['loc' => route('home'), 'priority' => '1.0'];

        foreach ($venues as $venue) {
            $tenant = $venue->tenant;

            if (! $tenant instanceof Tenant) {
                continue;
            }

            $urls[] = [
                'loc' => route('public.booking', $tenant),

                // The venue's own last change, so a search engine is not told
                // every page changed whenever any page did.
                'lastmod' => $venue->updated_at?->toAtomString(),
                'priority' => '0.8',
            ];
        }

        return response(view('public.sitemap', ['urls' => $urls])->render(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',

            // Crawlers come back often and this changes when a venue edits
            // its profile, which is rarely.
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
