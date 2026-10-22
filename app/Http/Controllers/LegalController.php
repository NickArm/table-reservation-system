<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlatformDocumentType;
use App\Models\PlatformDocument;
use Illuminate\View\View;

/**
 * The platform's legal pages (SPEC §12.2).
 *
 * Public, indexable, and reachable without an account — they have to be, or
 * the links in a sign-up form go nowhere for exactly the person who needs to
 * read them before signing up.
 *
 * A page with nothing published yet renders anyway, saying so. The
 * alternative is a 404 on a link we print in a registration form and in
 * emails, which reads as a broken product rather than an unfinished text.
 */
final class LegalController extends Controller
{
    public function __invoke(string $type): View
    {
        $document = PlatformDocumentType::tryFrom($type);

        abort_if($document === null, 404);

        $current = PlatformDocument::current($document);

        return view('legal.show', [
            'type' => $document,
            'document' => $current,

            // The other three, for the footer of this one. Somebody reading
            // the terms usually wants the privacy notice next.
            'others' => array_values(array_filter(
                PlatformDocumentType::cases(),
                static fn (PlatformDocumentType $case): bool => $case !== $document,
            )),
        ]);
    }
}
