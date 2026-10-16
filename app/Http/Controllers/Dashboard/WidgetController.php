<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\BookingType;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Illuminate\View\View;

/**
 * The widget builder (SPEC §9.4).
 *
 * The screen exists so the owner never has to understand what they are
 * copying. They choose a shape, see it move, press Copy, and send one line to
 * whoever looks after their website.
 *
 * The preview is the real widget, loaded from the real /embed.js, with the
 * real availability behind it. A drawing of a widget would be easier and
 * would also be the kind of thing that looks right here and wrong on the
 * customer's homepage.
 */
final class WidgetController extends Controller
{
    public function show(TenantContext $context): View
    {
        $venue = Venue::query()->firstOrFail();

        return view('dashboard.widget', [
            'tenant' => $context->getOrFail(),
            'venue' => $venue,
            'bookingTypes' => BookingType::query()->active()->get(),

            // Defaults chosen for the common case rather than for symmetry: a
            // beach bar wants the search bar, in its own colour, going to its
            // own booking page in the same tab.
            'defaults' => [
                'mode' => 'search',
                'layout' => 'horizontal',
                'theme' => 'auto',
                'accent' => $venue->accent_color ?: '#0e6e6e',
                'people' => 2,
                'onSearch' => 'redirect',
                'bookingType' => '',
                'label' => 'Search',
            ],
        ]);
    }
}
