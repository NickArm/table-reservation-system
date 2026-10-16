<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Actions\Api\IssueApiToken;
use App\Enums\ApiTokenMode;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\BookingType;
use App\Models\Venue;
use App\Queries\ApiUsage;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Settings → Developer (SPEC §15.2).
 *
 * The one screen in the dashboard not written for the taverna owner. It is
 * written for whoever they forward it to, which is why it carries working
 * curl lines rather than prose: the first thing a developer does is paste one
 * and see a response, and everything after that is easier.
 *
 * The token is revealed only on an explicit POST. A GET that printed it would
 * put a live secret into the browser history, the proxy log and whatever
 * screen recorder happened to be running.
 */
final class DeveloperController extends Controller
{
    public function __construct(private readonly IssueApiToken $tokens) {}

    public function show(Request $request, TenantContext $context): View
    {
        $tenant = $context->getOrFail();

        $live = $this->tokens->existing(ApiTokenMode::Live);
        $test = $this->tokens->existing(ApiTokenMode::Test);

        $usage = new ApiUsage;

        return view('dashboard.developer', [
            'tenant' => $tenant,
            'venue' => Venue::query()->firstOrFail(),
            'live' => $live,
            'test' => $test,

            // Only ever populated by the reveal POST, which redirects here
            // with it flashed. Never read from a query string.
            'revealed' => $this->revealed($request, $live, $test),

            'bookingType' => BookingType::query()->active()->first(),
            'totals' => $usage->totals(),
            'endpoints' => $usage->byEndpoint(),
            'daily' => $usage->daily(),
        ]);
    }

    /**
     * Shows the full token once, in the response to the owner's own click.
     */
    public function reveal(Request $request, string $mode): RedirectResponse
    {
        $token = $this->tokens->existing($this->mode($mode));

        return back()->with('revealed', [
            'mode' => $token->mode->value,
            'token' => $token->token,
        ]);
    }

    /**
     * Replaces a token. The old one stops working immediately, which is the
     * whole point and also the reason the button asks first.
     */
    public function regenerate(string $mode): RedirectResponse
    {
        $wanted = $this->mode($mode);

        $token = $this->tokens->handle($wanted);

        return back()
            ->with('revealed', ['mode' => $token->mode->value, 'token' => $token->token])
            ->with('status', $wanted->label().' token replaced. The previous one no longer works — '
                .'paste the new one wherever the old one was.');
    }

    private function mode(string $mode): ApiTokenMode
    {
        $resolved = ApiTokenMode::tryFrom($mode);

        abort_if($resolved === null, 404);

        return $resolved;
    }

    /**
     * @return array{mode: string, token: string}|null
     */
    private function revealed(Request $request, ApiToken $live, ApiToken $test): ?array
    {
        /** @var array{mode: string, token: string}|null $flashed */
        $flashed = $request->session()->get('revealed');

        if ($flashed === null) {
            return null;
        }

        // Checked against the account's own tokens rather than trusted from
        // the session, so a stale flash from a since-regenerated token cannot
        // print a value that no longer means anything.
        $current = $flashed['mode'] === ApiTokenMode::Live->value ? $live : $test;

        return $flashed['token'] === $current->token ? $flashed : null;
    }
}
