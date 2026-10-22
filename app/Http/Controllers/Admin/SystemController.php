<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Platform\PlatformHealth;
use Illuminate\View\View;

/**
 * The parts nobody watches (SPEC §13.2).
 */
final class SystemController extends Controller
{
    public function __invoke(PlatformHealth $health): View
    {
        return view('admin.system', [
            'health' => $health,
            'scheduled' => PlatformHealth::SCHEDULED,
            'runs' => $health->scheduledRuns(),
            'failures' => $health->recentFailures(),
        ]);
    }
}
