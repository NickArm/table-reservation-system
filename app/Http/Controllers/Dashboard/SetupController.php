<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\LegalDocument;
use App\Models\OpeningHour;
use App\Models\PaymentGatewaySetting;
use App\Models\Resource;
use App\Models\Tenant;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * One page for everything an owner sets once (SPEC §1.1).
 *
 * The rail holds what is touched during service. Everything else used to be
 * ten more entries beside it, which is how a menu becomes a list somebody
 * reads every time instead of remembering. They live here, grouped, each
 * card saying what it is set to now — because the question an owner arrives
 * with is almost never "where is that screen" but "what did I put there".
 *
 * Every line below is read from what is actually configured. A card that
 * claims a thing is set up while it is not would be worse than no card: it
 * is the sort of thing somebody finds out on the first Saturday.
 */
final class SetupController extends Controller
{
    public function __invoke(TenantContext $context): View
    {
        $tenant = $context->getOrFail();
        $venue = Venue::query()->first();

        $areas = Area::query()->count();
        $resources = Resource::query()->where('is_active', true)->count();
        $seats = (int) Resource::query()->where('is_active', true)->sum('capacity_max');
        $openDays = OpeningHour::query()->distinct()->count('day_of_week');
        $bookingTypes = BookingType::query()->active()->get();
        $gateways = PaymentGatewaySetting::query()->where('is_active', true)->get();
        $published = LegalDocument::query()->whereNotNull('published_at')->count();
        $completeness = $venue?->profileCompleteness();

        $emails = is_array($venue?->notification_emails) ? count($venue->notification_emails) : 0;

        return view('dashboard.setup', [
            'tenant' => $tenant,
            'venue' => $venue,
            // Amber for something unfinished, grey for something switched
            // off on purpose: the two are not the same and should not look
            // the same.
            'chipSkin' => ['todo' => 'pill--warn', 'off' => 'pill--muted', 'on' => 'pill--ok'],
            'groups' => [
                [
                    'title' => 'The place',
                    'hint' => 'What you have, and when you are open',
                    'cards' => [
                        [
                            'title' => 'Venue profile',
                            'body' => 'Name, photos, address, cuisine — what Google and your booking page show.',
                            'href' => route('dashboard.profile.edit'),
                            'state' => $completeness === null || $completeness['percent'] < 60 ? 'todo' : 'on',
                            'chip' => $completeness === null ? 'Not started' : $completeness['percent'].'% filled',
                        ],
                        [
                            'title' => 'Tables and areas',
                            'body' => $this->structureLine($bookingTypes->count(), $areas, $resources, $seats),
                            'href' => route('dashboard.booking-types.index'),
                            'state' => $resources > 0 ? 'on' : 'todo',
                            'chip' => $resources > 0 ? null : 'Nothing yet',
                        ],
                        [
                            'title' => 'Opening hours',
                            'body' => $openDays > 0
                                ? 'Open on '.$openDays.' '.($openDays === 1 ? 'day' : 'days').' a week. Closures and one-off days are here too.'
                                : 'Nothing set, so your page takes no bookings at all.',
                            'href' => route('dashboard.booking-types.index'),
                            'state' => $openDays > 0 ? 'on' : 'todo',
                            'chip' => $openDays > 0 ? null : 'Needed',
                        ],
                    ],
                ],
                [
                    'title' => 'Taking bookings',
                    'hint' => 'The rules a guest meets',
                    'cards' => [
                        [
                            'title' => 'Your booking page',
                            'body' => 'tablereserved.com/reservation/'.$tenant->slug.' — colours, logo and the photo at the top.',
                            'href' => route('dashboard.branding.edit'),
                            'state' => 'on',
                            'chip' => 'Live',
                        ],
                        [
                            'title' => 'Payments',
                            'body' => $this->paymentLine($gateways),
                            'href' => route('dashboard.payments.edit'),
                            'state' => $gateways->isEmpty() ? 'off' : 'on',
                            'chip' => $gateways->isEmpty() ? 'Off' : null,
                        ],
                        [
                            'title' => 'Website widget',
                            'body' => $venue?->widgetIsLive() === true
                                ? 'Seen working on your site in the last week.'
                                : 'The snippet for your own site. Not seen working yet.',
                            'href' => route('dashboard.widget'),
                            'state' => $venue?->widgetIsLive() === true ? 'on' : 'off',
                            'chip' => $venue?->widgetIsLive() === true ? 'Working' : 'Not seen',
                        ],
                    ],
                ],
                [
                    'title' => 'Talking to guests',
                    'hint' => 'What goes out without you',
                    'cards' => [
                        [
                            'title' => 'Alerts',
                            'body' => $emails > 0
                                ? 'New bookings go to you and '.$emails.' other '.($emails === 1 ? 'address' : 'addresses').'. Quiet hours are here.'
                                : 'New bookings go to you. Add the kitchen or the floor here.',
                            'href' => route('dashboard.notifications.edit'),
                            'state' => 'on',
                            'chip' => null,
                        ],
                        [
                            'title' => 'Terms and cancellations',
                            'body' => $published > 0
                                ? $published.' published. Every guest agrees to them when they book.'
                                : 'Nothing published. Guests see no policy, and you cannot keep a deposit without one.',
                            'href' => route('dashboard.legal.edit'),
                            'state' => $published > 0 ? 'on' : 'todo',
                            'chip' => $published > 0 ? 'Published' : 'Needed',
                        ],
                    ],
                ],
                [
                    'title' => 'Your account',
                    'hint' => 'The business side',
                    'cards' => [
                        [
                            'title' => 'Plan and invoices',
                            'body' => $this->billingLine($tenant),
                            'href' => route('dashboard.billing'),
                            'state' => $tenant->status->acceptsBookings() ? 'on' : 'todo',
                            'chip' => $tenant->status->label(),
                        ],
                        [
                            'title' => 'Account and team',
                            'body' => 'Who can sign in, what they are allowed to see, and how to leave.',
                            'href' => route('dashboard.settings'),
                            'state' => 'on',
                            'chip' => null,
                        ],
                        [
                            'title' => 'API and webhooks',
                            'body' => 'One token for your own integrations. For whoever builds your website.',
                            'href' => route('dashboard.developer'),
                            'state' => 'on',
                            'chip' => null,
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function structureLine(int $types, int $areas, int $resources, int $seats): string
    {
        if ($resources === 0) {
            return 'Nothing to book yet. This is the one thing the page cannot go live without.';
        }

        return $resources.' '.($resources === 1 ? 'spot' : 'spots').' seating '.$seats
            .' across '.$areas.' '.($areas === 1 ? 'area' : 'areas')
            .', in '.$types.' '.($types === 1 ? 'booking type' : 'booking types').'.';
    }

    /**
     * @param  Collection<int, PaymentGatewaySetting>  $gateways
     */
    private function paymentLine(Collection $gateways): string
    {
        if ($gateways->isEmpty()) {
            return 'Nothing switched on, so bookings are confirmed without paying.';
        }

        $names = $gateways
            ->map(static fn (PaymentGatewaySetting $setting): string => $setting->gateway->label()
                .($setting->isTestMode() ? ' (test mode)' : ''))
            ->implode(', ');

        return 'Through your own '.$names.'. The money never passes through us.';
    }

    private function billingLine(Tenant $tenant): string
    {
        if ($tenant->isOnTrial()) {
            $days = $tenant->trialDaysLeft();

            return 'Trial, '.$days.' '.($days === 1 ? 'day' : 'days').' left. Nothing is switched off while it runs.';
        }

        $subscription = $tenant->subscription;

        if ($subscription?->plan === null) {
            return 'No plan yet. Pick one before the trial runs out.';
        }

        return $subscription->plan->name.', renewing '
            .($subscription->current_period_end?->format('j F Y') ?? 'on an unknown date').'.';
    }
}
