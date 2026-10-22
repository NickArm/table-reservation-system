<?php

declare(strict_types=1);

namespace App\Platform;

use App\Enums\PaymentStatus;
use App\Models\CommandRun;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Whether the parts nobody watches are working (SPEC §13.2).
 *
 * Every figure here answers one question: is something broken in a way that
 * does not announce itself. That is the whole category — a missing cron, a
 * queue worker that died, a webhook the gateway kept retrying into nothing.
 * None of them throws, none of them emails anybody, and all of them look
 * exactly like a quiet week until a venue rings up.
 */
final readonly class PlatformHealth
{
    /**
     * The commands that have to run, and how often.
     *
     * @var array<string, array{every: int, matters: string}>
     */
    public const SCHEDULED = [
        'subscriptions:advance' => [
            'every' => 24,
            'matters' => 'Ends trials, locks accounts that stopped paying, and sends '
                .'the payment-failure emails. Not running means nobody is ever billed '
                .'and nobody is ever chased.',
        ],
        'tenants:purge' => [
            'every' => 24,
            'matters' => 'Deletes accounts whose thirty days are up. Not running leaves '
                .'deletion requests unhonoured, which is a promise in the privacy page.',
        ],
        'payments:reconcile' => [
            'every' => 1,
            'matters' => 'Catches payments whose webhook never arrived. Not running '
                .'means a guest who paid can be left without a booking.',
        ],
        'reservations:remind' => [
            'every' => 1,
            'matters' => 'Sends guests their reminder the evening before, which is what '
                .'reduces no-shows.',
        ],
        'venues:daily-summary' => [
            'every' => 1,
            'matters' => "The day's list, emailed to each venue in the morning.",
        ],
        'holds:release' => [
            'every' => 1,
            'matters' => 'Frees seats held by somebody who closed the tab. Not running '
                .'makes a full venue look fuller than it is.',
        ],
    ];

    /**
     * Jobs waiting to be picked up.
     *
     * A number that is not zero is not a problem; a number that is not zero
     * and does not move is a dead worker.
     */
    public function pendingJobs(): int
    {
        return $this->countOf('jobs');
    }

    /**
     * How long the oldest waiting job has been waiting.
     *
     * The figure that actually distinguishes a busy queue from a stopped one.
     */
    public function oldestPendingJobMinutes(): ?int
    {
        if (! $this->tableExists('jobs')) {
            return null;
        }

        $available = DB::table('jobs')->min('available_at');

        if ($available === null) {
            return null;
        }

        return (int) floor((now()->getTimestamp() - (int) $available) / 60);
    }

    public function failedJobs(): int
    {
        return $this->countOf('failed_jobs');
    }

    /**
     * The recent failures, with the first line of each exception.
     *
     * The first line is the whole point: a page of stack traces is a page
     * nobody reads, and the class and message are what say whether twelve
     * failures are one problem or twelve.
     *
     * @return Collection<int, object{id: int, queue: string, failed_at: string, reason: string}>
     */
    public function recentFailures(int $limit = 15): Collection
    {
        if (! $this->tableExists('failed_jobs')) {
            return collect();
        }

        return DB::table('failed_jobs')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static function (object $row): object {
                $first = strtok((string) $row->exception, "\n");

                return (object) [
                    'id' => (int) $row->id,
                    'queue' => (string) $row->queue,
                    'failed_at' => (string) $row->failed_at,
                    'reason' => mb_substr($first === false ? '' : $first, 0, 300),
                ];
            });
    }

    /**
     * Guests' payments the gateway refused or never confirmed.
     *
     * Across every venue, because a gateway having a bad day shows up here
     * before any one owner notices it.
     */
    public function failedPayments(): int
    {
        return Payment::query()
            ->acrossTenants()
            ->where('status', PaymentStatus::Failed->value)
            ->where('created_at', '>=', now()->subWeek())
            ->count();
    }

    /**
     * Payments still waiting for a webhook that may never come (SPEC §24.2).
     *
     * Pending for more than an hour means the gateway said it would tell us
     * and has not. `payments:reconcile` exists to ask; a number here that
     * stays up means that command is not running either.
     */
    public function stalePendingPayments(): int
    {
        return Payment::query()
            ->acrossTenants()
            ->where('status', PaymentStatus::Pending->value)
            ->where('created_at', '<=', now()->subHour())
            ->count();
    }

    /**
     * Confirmations that bounced, across every venue (SPEC §10.3).
     */
    public function bouncedVouchers(): int
    {
        return Reservation::query()
            ->acrossTenants()
            ->where('voucher_delivery', 'bounced')
            ->where('created_at', '>=', now()->subMonth())
            ->count();
    }

    /**
     * @return array<string, CommandRun|null>
     */
    public function scheduledRuns(): array
    {
        return CommandRun::latestOf(array_keys(self::SCHEDULED));
    }

    /**
     * Is anything actually wrong?
     *
     * Used for the one line at the top of the page, because a page of green
     * figures should say "nothing to do" rather than make somebody read all
     * of them to find out.
     */
    public function looksHealthy(): bool
    {
        if ($this->failedJobs() > 0 || $this->stalePendingPayments() > 0) {
            return false;
        }

        foreach ($this->scheduledRuns() as $command => $run) {
            if ($run === null || ! $run->ok || $run->isOverdue(self::SCHEDULED[$command]['every'])) {
                return false;
            }
        }

        return true;
    }

    private function countOf(string $table): int
    {
        return $this->tableExists($table) ? (int) DB::table($table)->count() : 0;
    }

    /**
     * The queue tables only exist on the database driver.
     *
     * With `QUEUE_CONNECTION=sync` — which DEPLOY offers as the answer when a
     * host will not run a worker — there is no `jobs` table at all, and this
     * page should say so rather than throw.
     */
    private function tableExists(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
