<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Vouchers\Voucher;
use App\Vouchers\VoucherPdf;
use App\Vouchers\VoucherToken;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * The voucher, as the guest sees it at the door (SPEC §11.2).
 *
 * The URL carries a booking code and a signature, and nothing else — no
 * tenant, no identifier, no session. That is what lets it live in a QR small
 * enough to scan quickly, in an email that may be opened months later on a
 * phone that has never visited the site.
 *
 * A wrong signature gets the same 404 as a code that does not exist. Telling
 * the difference would turn the page into a way of finding out whether a
 * booking code is real.
 */
final class VoucherController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(string $code, string $token): View
    {
        $voucher = $this->resolve($code, $token);

        return view('public.voucher', [
            'voucher' => $voucher,
            'tenant' => $voucher->tenant,
            'venue' => $voucher->venue,
            // A member of this venue's own staff opening the voucher gets the
            // one control a door needs. Check-in is idempotent, so a second
            // tap changes nothing (SPEC §32.3, §32.4).
            'canCheckIn' => auth()->check()
                && auth()->user()?->belongsToTenant($voucher->tenant) === true,
        ]);
    }

    public function pdf(string $code, string $token): BaseResponse
    {
        $voucher = $this->resolve($code, $token);

        $pdf = (new VoucherPdf)->render($voucher);

        // No PDF library installed, or rendering failed: the page itself is
        // still a perfectly good voucher, so send them there.
        if ($pdf === null) {
            return redirect()->route('voucher', ['code' => $code, 'token' => $token]);
        }

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$voucher->pdfFilename().'"',
        ]);
    }

    public function calendar(string $code, string $token): Response
    {
        $voucher = $this->resolve($code, $token);

        return new Response($voucher->calendar(), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$voucher->calendarFilename().'"',
        ]);
    }

    private function resolve(string $code, string $token): Voucher
    {
        $reservation = $this->context->withoutScoping(
            fn (): ?Reservation => Reservation::query()
                ->acrossTenants()
                ->where('code', strtoupper($code))
                ->first()
        );

        abort_if($reservation === null, 404);
        abort_unless(VoucherToken::matches($reservation, $token), 404);

        $this->context->set(Tenant::query()->findOrFail($reservation->tenant_id));

        return Voucher::for($reservation);
    }
}
