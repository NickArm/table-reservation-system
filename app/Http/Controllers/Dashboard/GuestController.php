<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Actions\Guests\AnonymizeGuest;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The venue's own list of everyone who ever booked (SPEC §28.3).
 *
 * Everything here is scoped to one venue by the guests table's own key, and to
 * one account by the tenant scope. There is no screen anywhere that shows one
 * venue a guest's dealings with another.
 */
final class GuestController extends Controller
{
    public function index(Request $request, TenantContext $context): View
    {
        $filter = (string) $request->string('show', 'all');
        $search = (string) $request->string('q');

        $guests = Guest::query()
            ->matching($search)
            ->when($filter === 'repeat', fn ($query) => $query->repeat())
            ->when($filter === 'trouble', fn ($query) => $query->trouble())
            ->orderByDesc($request->string('sort')->value() === 'bookings' ? 'bookings_count' : 'last_seen_at')
            ->paginate(40)
            ->withQueryString();

        return view('dashboard.guests.index', [
            'tenant' => $context->getOrFail(),
            'venue' => Venue::query()->first(),
            'guests' => $guests,
            'search' => $search,
            'filter' => $filter,
            'sort' => (string) $request->string('sort', 'recent'),
            'totals' => $totals = [
                'all' => Guest::query()->count(),
                'repeat' => Guest::query()->repeat()->count(),
                'trouble' => Guest::query()->trouble()->count(),
            ],
            // One tap each, with the count on the chip: the count is the
            // reason an owner opens this screen at all.
            'segments' => $this->segments($request, $filter, $totals),
            'sorts' => $this->sorts($request),
        ]);
    }

    /**
     * @param  array{all: int, repeat: int, trouble: int}  $totals
     * @return list<array{label: string, count: int, href: string, on: bool}>
     */
    private function segments(Request $request, string $filter, array $totals): array
    {
        $keep = $request->only(['q', 'sort']);

        $options = [
            ['label' => 'Everyone', 'show' => 'all', 'count' => $totals['all']],
            ['label' => 'Came back', 'show' => 'repeat', 'count' => $totals['repeat']],
            ['label' => 'No-shows', 'show' => 'trouble', 'count' => $totals['trouble']],
        ];

        return array_map(
            static fn (array $option): array => [
                'label' => $option['label'],
                'count' => $option['count'],
                'href' => route('dashboard.guests.index', array_filter(
                    [...$keep, 'show' => $option['show']],
                    static fn (mixed $value): bool => $value !== null && $value !== '',
                )),
                'on' => $filter === $option['show'],
            ],
            $options,
        );
    }

    /**
     * @return list<array{label: string, href: string, on: bool}>
     */
    private function sorts(Request $request): array
    {
        $keep = $request->only(['q', 'show']);
        $current = $request->string('sort')->value() === 'bookings' ? 'bookings' : 'recent';

        $options = [
            ['label' => 'Last visit', 'sort' => 'recent'],
            ['label' => 'Most bookings', 'sort' => 'bookings'],
        ];

        return array_map(
            static fn (array $option): array => [
                'label' => $option['label'],
                'href' => route('dashboard.guests.index', array_filter(
                    [...$keep, 'sort' => $option['sort']],
                    static fn (mixed $value): bool => $value !== null && $value !== '',
                )),
                'on' => $current === $option['sort'],
            ],
            $options,
        );
    }

    public function show(Guest $guest, TenantContext $context): View
    {
        $venue = Venue::query()->firstOrFail();

        return view('dashboard.guests.show', [
            'tenant' => $context->getOrFail(),
            'venue' => $venue,
            'timezone' => (string) $venue->timezone,
            'guest' => $guest,
            // Their whole history with this venue, newest first — which is
            // the order somebody on the telephone needs it in.
            'history' => Reservation::query()
                ->where('guest_id', $guest->id)
                ->orderByDesc('starts_at')
                ->limit(100)
                ->get(),
            'canErase' => $this->isOwner($context),
        ]);
    }

    /**
     * The venue's private view of a guest: notes and tags, nothing the guest
     * ever sees.
     */
    public function update(Request $request, Guest $guest): RedirectResponse
    {
        $validated = $request->validate([
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'tags' => ['nullable', 'string', 'max:200'],
        ]);

        $tags = collect(explode(',', (string) ($validated['tags'] ?? '')))
            ->map(static fn (string $tag): string => trim($tag))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $guest->fill([
            'internal_notes' => $validated['internal_notes'] ?? null,
            'tags' => $tags === [] ? null : $tags,
        ])->save();

        return back()->with('status', 'Saved.');
    }

    /**
     * A guest asks to be forgotten (SPEC §28.5).
     *
     * The owner's decision, not a staff member's: it rewrites personal data on
     * bookings that have already been paid for, and it cannot be undone.
     */
    public function erase(Guest $guest, AnonymizeGuest $anonymize, TenantContext $context): RedirectResponse
    {
        abort_unless($this->isOwner($context), 403);

        $anonymize->handle($guest);

        return redirect()
            ->route('dashboard.guests.index')
            ->with('status', 'That guest has been anonymised. Their bookings remain, without their details.');
    }

    /**
     * The guest list as a CSV.
     *
     * Streamed rather than assembled in memory, so a venue with a few seasons
     * behind it does not need a bigger PHP memory limit to get its own data
     * out. Private notes are included: this is the venue downloading its own
     * list, and withholding them would make the export useless.
     */
    public function export(Request $request): StreamedResponse
    {
        $search = (string) $request->string('q');
        $filename = 'guests-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($search): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            // Excel on a Greek Windows machine reads a bare UTF-8 file as
            // Latin-1 and mangles every accented name. The byte order mark is
            // what stops that.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Name', 'Email', 'Phone',
                'First seen', 'Last seen',
                'Bookings', 'Completed', 'Cancelled', 'No-shows',
                'Total booked (EUR)', 'Marketing consent', 'Tags', 'Notes',
            ]);

            Guest::query()
                ->matching($search)
                ->orderByDesc('last_seen_at')
                ->chunk(500, function ($guests) use ($out): void {
                    foreach ($guests as $guest) {
                        fputcsv($out, [
                            $guest->name,
                            $guest->email,
                            $guest->phone,
                            $guest->first_seen_at?->format('Y-m-d'),
                            $guest->last_seen_at?->format('Y-m-d'),
                            $guest->bookings_count,
                            $guest->completed_count,
                            $guest->cancelled_count,
                            $guest->no_show_count,
                            number_format($guest->total_spent_cents / 100, 2, '.', ''),
                            $guest->marketing_consent ? 'yes' : 'no',
                            implode(' ', $guest->tagList()),
                            $guest->internal_notes,
                        ]);
                    }
                });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Feeds the autocomplete on the manual booking form (SPEC §28.3).
     *
     * Deliberately thin: enough to recognise somebody and fill the form in,
     * and nothing more. The private notes never leave the guest's own card.
     */
    public function suggest(Request $request): JsonResponse
    {
        $term = trim((string) $request->string('q'));

        if (mb_strlen($term) < 2) {
            return new JsonResponse(['guests' => []]);
        }

        $guests = Guest::query()
            ->matching($term)
            ->whereNull('anonymized_at')
            ->orderByDesc('last_seen_at')
            ->limit(6)
            ->get();

        return new JsonResponse([
            'guests' => $guests->map(static fn (Guest $guest): array => [
                'name' => $guest->name,
                'email' => $guest->email,
                'phone' => $guest->phone,
                'note' => $guest->warning() ?? $guest->visitLabel(),
                'warn' => $guest->warning() !== null,
            ])->all(),
        ]);
    }

    private function isOwner(TenantContext $context): bool
    {
        return auth()->user()?->roleIn($context->getOrFail()) === TenantRole::Owner;
    }
}
