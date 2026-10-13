<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Payments\ExpireUnpaidReservations;
use App\Actions\Payments\ReconcilePayments;
use Illuminate\Console\Command;

final class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile {--hours=24 : How far back to look}';

    protected $description = 'Ask the gateways about payments whose webhooks never arrived';

    public function handle(ReconcilePayments $reconcile, ExpireUnpaidReservations $expire): int
    {
        $released = $expire->handle();
        $result = $reconcile->handle((int) $this->option('hours'));

        $this->info(sprintf(
            'Checked %d, settled %d, failed %d. Released %d unpaid %s.',
            $result['checked'],
            $result['settled'],
            $result['failed'],
            $released,
            str('reservation')->plural($released),
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
