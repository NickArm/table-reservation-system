<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\StructureScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\StorePriceRuleRequest;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\PriceRule;
use App\Models\Resource;
use Illuminate\Http\RedirectResponse;

/**
 * Prices that differ by day, season or time (SPEC §7.2).
 *
 * Hidden behind "Advanced" until somebody asks for it: most venues have one
 * price and should never meet this screen (§1.1).
 */
final class PriceRuleController extends Controller
{
    public function store(StorePriceRuleRequest $request): RedirectResponse
    {
        // Resolved through the tenant-scoped models, so an id from another
        // venue simply does not exist.
        $this->assertSubjectExists($request->scope(), $request->scopeId());

        $rule = new PriceRule;
        $rule->fill($request->ruleAttributes());
        $rule->save();

        return back()->with('status', "Price rule added: {$rule->describe()}.");
    }

    public function destroy(PriceRule $priceRule): RedirectResponse
    {
        $name = $priceRule->describe();
        $priceRule->delete();

        return back()->with('status', "Removed {$name}. The usual price applies again.");
    }

    private function assertSubjectExists(StructureScope $scope, int $id): void
    {
        match ($scope) {
            StructureScope::BookingType => BookingType::query()->findOrFail($id),
            StructureScope::Area => Area::query()->findOrFail($id),
            StructureScope::Resource => Resource::query()->findOrFail($id),
        };
    }
}
