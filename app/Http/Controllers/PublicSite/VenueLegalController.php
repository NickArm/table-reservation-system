<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Enums\VenueDocumentType;
use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use App\Models\Tenant;
use App\Models\Venue;
use Illuminate\View\View;

/**
 * A venue's terms and cancellation policy, as a guest reads them (SPEC §12.1).
 *
 * Reachable without a booking in progress, because the links are on the
 * confirmation email and on the voucher as well as at checkout — and because
 * a term somebody cannot go back and read is a term that does not hold.
 *
 * Not behind the bookings-open middleware either: a venue whose subscription
 * lapsed still has guests holding confirmations that point here.
 *
 * 🔒 **`$tenant` is in the signature because it has to be.** Laravel resolves
 * a controller's class-typed arguments by name but everything else
 * **positionally**, against the route's parameters in order. This route sits
 * under the `reservation/{tenant}` prefix, so the first parameter is the
 * tenant — and a method declared as `(string $type)` is handed the *Tenant*,
 * stringified. It does not fail loudly: `VenueDocumentType::fromPath()` gets
 * a JSON blob, finds nothing, and the page answers a perfectly ordinary 404
 * that looks for all the world like a missing document.
 */
final class VenueLegalController extends Controller
{
    public function __invoke(Tenant $tenant, string $type): View
    {
        $document = VenueDocumentType::fromPath($type);

        abort_if($document === null, 404);

        $current = LegalDocument::current($document);

        abort_if($current === null, 404);

        return view('public.legal', [
            'tenant' => $tenant,
            'venue' => Venue::query()->firstOrFail(),
            'type' => $document,
            'document' => $current,
        ]);
    }
}
