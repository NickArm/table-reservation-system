<?php

declare(strict_types=1);

namespace App\Vouchers;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Throwable;

/**
 * The voucher as a PDF, for the guest who prints it or keeps it in their
 * downloads folder.
 *
 * Two deliberate decisions here.
 *
 * It is optional. If dompdf is not installed, or rendering fails, the
 * confirmation email still goes out — with the booking code, the QR and the
 * calendar file — and the failure is logged rather than thrown. An attachment
 * is worth having; it is not worth losing a confirmation over.
 *
 * It writes its font cache under storage. Shared hosting rarely lets a vendor
 * directory be written to, and a PDF library that cannot cache its fonts fails
 * on the first booking of the season rather than in testing.
 */
final class VoucherPdf
{
    public static function available(): bool
    {
        return class_exists(Dompdf::class);
    }

    public function render(Voucher $voucher): ?string
    {
        if (! self::available()) {
            Log::info('No PDF library installed, so the voucher goes out without one.', [
                'install' => 'composer require dompdf/dompdf',
            ]);

            return null;
        }

        try {
            return $this->build($voucher);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function build(Voucher $voucher): string
    {
        $cache = storage_path('app/pdf-fonts');

        File::ensureDirectoryExists($cache);

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('fontDir', $cache);
        $options->set('fontCache', $cache);
        $options->set('tempDir', storage_path('app/pdf-temp'));
        $options->set('chroot', base_path());

        // Everything the document needs is inlined before it gets here, so
        // the renderer never reaches out to the network.
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        File::ensureDirectoryExists(storage_path('app/pdf-temp'));

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4');
        $dompdf->loadHtml(View::make('pdf.voucher', ['voucher' => $voucher])->render(), 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
