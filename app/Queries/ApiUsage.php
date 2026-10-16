<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\ApiRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the account's token has been doing (SPEC §15.2.1).
 *
 * Three questions, each a single grouped query rather than a row-by-row walk:
 * how much traffic, which endpoints, and how much of it we refused. The last
 * one is the useful one — an integration that is quietly getting 422s looks
 * healthy in every other measure.
 */
final readonly class ApiUsage
{
    public function __construct(private int $days = 7) {}

    /**
     * @return array{calls: int, rejected: int, median_ms: int, since: string}
     */
    public function totals(): array
    {
        $since = $this->since();

        /** @var object{calls: int|null, rejected: int|null, average: float|null}|null $row */
        $row = ApiRequest::query()
            ->since($since)
            ->reorder()
            ->selectRaw('count(*) as calls')
            ->selectRaw('sum(case when status >= 400 then 1 else 0 end) as rejected')
            ->selectRaw('avg(duration_ms) as average')
            ->toBase()
            ->first();

        return [
            'calls' => (int) ($row->calls ?? 0),
            'rejected' => (int) ($row->rejected ?? 0),
            'median_ms' => (int) round((float) ($row->average ?? 0)),
            'since' => $since,
        ];
    }

    /**
     * Busiest endpoints first, with how many of each were refused.
     *
     * @return Collection<int, object>
     */
    public function byEndpoint(): Collection
    {
        return ApiRequest::query()
            ->since($this->since())
            ->reorder()
            ->selectRaw('endpoint')
            ->selectRaw('count(*) as calls')
            ->selectRaw('sum(case when status >= 400 then 1 else 0 end) as rejected')
            ->groupBy('endpoint')
            ->orderByDesc('calls')
            ->toBase()
            ->get();
    }

    /**
     * Calls per day, oldest first, with the quiet days filled in.
     *
     * A gap in a chart reads as "no data" rather than "no calls", and the
     * difference between those two matters when somebody is working out
     * whether their integration ever ran.
     *
     * @return array<string, int>
     */
    public function daily(): array
    {
        $rows = ApiRequest::query()
            ->since($this->since())
            ->reorder()
            ->selectRaw('date(occurred_at) as day')
            ->selectRaw('count(*) as calls')
            ->groupBy('day')
            ->toBase()
            ->get()
            ->keyBy('day');

        $out = [];

        for ($i = $this->days - 1; $i >= 0; $i--) {
            $day = CarbonImmutable::now()->subDays($i)->toDateString();

            $out[$day] = (int) ($rows[$day]->calls ?? 0);
        }

        return $out;
    }

    private function since(): string
    {
        return CarbonImmutable::now()->subDays($this->days)->startOfDay()->toDateTimeString();
    }
}
