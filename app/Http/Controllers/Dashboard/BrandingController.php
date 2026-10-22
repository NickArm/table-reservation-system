<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Actions\Venues\SaveVenueBranding;
use App\Enums\VenueImage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\BrandingRequest;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * How the booking page looks (SPEC §5.5, §27).
 */
final class BrandingController extends Controller
{
    public function edit(TenantContext $context): View
    {
        return view('dashboard.branding', [
            'tenant' => $context->getOrFail(),
            'venue' => Venue::query()->firstOrFail(),
            'kinds' => VenueImage::cases(),
        ]);
    }

    public function update(
        BrandingRequest $request,
        TenantContext $context,
        SaveVenueBranding $branding,
    ): RedirectResponse {
        $tenant = $context->getOrFail();

        try {
            $branding->handle(
                Venue::query()->firstOrFail(),
                (string) $tenant->uuid,
                $request->string('accent_color')->toString() ?: null,
                $request->integer('hero_overlay'),
                [
                    VenueImage::Logo->value => $request->file('logo'),
                    VenueImage::Hero->value => $request->file('hero'),
                ],
                $request->removals(),
            );
        } catch (Throwable $exception) {
            /*
             * An unreadable file, or object storage having a bad afternoon.
             * The owner gets a sentence rather than a stack trace — and the
             * log gets the rest, because a swallowed exception here means an
             * upload that silently does nothing, which is the hardest kind
             * of bug to be told about.
             */
            Log::warning('A venue image could not be saved.', [
                'tenant' => $tenant->slug,
                'error' => $exception->getMessage(),
                'at' => $exception->getFile().':'.$exception->getLine(),
            ]);

            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Saved. Have a look at your booking page.');
    }
}
