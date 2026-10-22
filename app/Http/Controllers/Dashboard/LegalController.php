<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Actions\Legal\SaveVenueDocument;
use App\Enums\VenueDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\VenueDocumentRequest;
use App\Legal\DefaultVenueDocuments;
use App\Models\LegalDocument;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * A venue's own terms and cancellation policy (SPEC §12.1).
 *
 * One screen, two boxes, pre-filled with text that already works. The owner
 * is a taverna owner, not a lawyer: a blank textarea headed "Terms of
 * Booking" produces either nothing or a paragraph copied off a competitor,
 * and the guest-facing checkbox at checkout then agrees to it (§1.1).
 *
 * The pre-filled text is not saved until they press Save. An account that
 * never opens this screen has no documents of its own and the booking page
 * falls back to the booking type's short policy line — which is also what
 * happens for every account that existed before this screen did.
 */
final class LegalController extends Controller
{
    public function edit(DefaultVenueDocuments $defaults, TenantContext $context): View
    {
        $tenant = $context->getOrFail();
        $venue = Venue::query()->firstOrFail();

        $documents = [];

        foreach (VenueDocumentType::cases() as $type) {
            $current = LegalDocument::current($type);

            $documents[$type->value] = [
                'type' => $type,
                'document' => $current,
                'content' => $current?->content ?? $defaults->for($type, $venue),
                'isDefault' => $current === null,
            ];
        }

        return view('dashboard.legal', [
            'tenant' => $tenant,
            'venue' => $venue,
            'documents' => $documents,
        ]);
    }

    public function update(
        VenueDocumentRequest $request,
        string $type,
        SaveVenueDocument $save,
    ): RedirectResponse {
        $document = VenueDocumentType::fromPath($type);

        abort_if($document === null, 404);

        $save->handle($document, $request->string('content')->toString());

        return back()->with(
            'status',
            $document->title().' saved. Guests see it from now on; bookings already '
            .'made keep the version they agreed to.'
        );
    }
}
