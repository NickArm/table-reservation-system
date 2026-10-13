<?php

declare(strict_types=1);

namespace App\Vouchers;

use App\Enums\PaymentStatus;
use App\Models\BookingType;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\Venue;
use App\Support\IcsFile;
use App\Support\Money;
use App\Support\QrCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything a booking confirmation shows, worked out once.
 *
 * The same voucher appears in three places — an email, a PDF attachment and a
 * page the guest opens at the door — and the one thing worse than an ugly
 * voucher is three of them that disagree. So the facts are assembled here and
 * the three templates only lay them out.
 *
 * Nothing is read through a relation: strict mode forbids lazy loading, and a
 * voucher is often built inside a queued job where the relations were never
 * loaded in the first place.
 */
final readonly class Voucher
{
    private function __construct(
        public Tenant $tenant,
        public Venue $venue,
        public BookingType $bookingType,
        public Reservation $reservation,
        public string $timezone,
    ) {}

    public static function for(Reservation $reservation): self
    {
        $venue = Venue::query()->findOrFail($reservation->venue_id);

        return new self(
            tenant: Tenant::query()->findOrFail($reservation->tenant_id),
            venue: $venue,
            bookingType: BookingType::query()->findOrFail($reservation->booking_type_id),
            reservation: $reservation,
            timezone: (string) $venue->timezone,
        );
    }

    public function startsAt(): CarbonImmutable
    {
        return $this->reservation->startsAtLocal($this->timezone);
    }

    public function endsAt(): CarbonImmutable
    {
        return $this->reservation->ends_at->setTimezone($this->timezone);
    }

    /** "Saturday 19 July, 20:30" */
    public function when(): string
    {
        return $this->startsAt()->format('l j F').', '.$this->startsAt()->format('H:i');
    }

    /** What was booked, as it stood on the day (SPEC §14). */
    public function what(): string
    {
        $label = $this->reservation->resource_snapshot['label'] ?? $this->bookingType->name;
        $area = $this->reservation->resource_snapshot['area'] ?? null;

        return filled($area) ? $label.' · '.$area : (string) $label;
    }

    public function includes(): string
    {
        return $this->reservation->snapshotContents();
    }

    public function party(): string
    {
        $size = (int) $this->reservation->party_size;

        return $size.' '.Str::plural('person', $size);
    }

    /**
     * What the money was, and what the venue chose to call it. The label is
     * theirs — "deposit", "deducted from your bill", "minimum spend" — and we
     * do not second-guess it (SPEC §7.3).
     */
    public function amount(): ?string
    {
        if ((int) $this->reservation->amount_cents <= 0) {
            return null;
        }

        return Money::formatExact((int) $this->reservation->amount_cents);
    }

    public function amountLabel(): string
    {
        return match ($this->reservation->payment_status) {
            PaymentStatus::Paid => 'Paid',
            PaymentStatus::PayOnArrival => 'To pay on arrival',
            PaymentStatus::Refunded => 'Refunded',
            PaymentStatus::PartiallyRefunded => 'Partly refunded',
            default => 'Amount',
        };
    }

    public function depositLabel(): ?string
    {
        $label = $this->reservation->deposit_label_snapshot;

        return filled($label) ? (string) $label : null;
    }

    public function address(): ?string
    {
        $parts = array_filter([
            $this->venue->address,
            $this->venue->city,
            $this->venue->postal_code,
        ], static fn (?string $part): bool => filled($part));

        return $parts === [] ? null : implode(', ', $parts);
    }

    public function mapUrl(): ?string
    {
        if ($this->venue->latitude === null || $this->venue->longitude === null) {
            return null;
        }

        return 'https://www.openstreetmap.org/?mlat='.$this->venue->latitude
            .'&mlon='.$this->venue->longitude
            .'#map=17/'.$this->venue->latitude.'/'.$this->venue->longitude;
    }

    /**
     * What the guest agreed to at the time. Stored on the booking type rather
     * than typed here, so that changing the policy tomorrow does not rewrite
     * what was shown yesterday.
     */
    public function cancellationPolicy(): string
    {
        $policy = $this->bookingType->cancellation_policy;

        if (is_array($policy) && filled($policy['text'] ?? null)) {
            return (string) $policy['text'];
        }

        return filled($this->venue->phone)
            ? 'To change or cancel, please call us on '.$this->venue->phone.'.'
            : 'To change or cancel, please call the venue.';
    }

    public function url(): string
    {
        return VoucherToken::url($this->reservation);
    }

    /**
     * The QR, as a PNG. Email clients strip SVG and refuse data URIs, so this
     * travels as an attached image the template points at by name.
     */
    public function qrPng(int $scale = 6): string
    {
        return QrCode::png($this->url(), $scale);
    }

    public function qrDataUri(int $scale = 6): string
    {
        return 'data:image/png;base64,'.base64_encode($this->qrPng($scale));
    }

    /**
     * The venue's logo inlined, for the PDF.
     *
     * The renderer is deliberately kept off the network, so the bytes have to
     * be here rather than at a URL. A missing or unreadable file is not worth
     * failing a voucher over — the document simply shows the venue's name.
     */
    public function logoDataUri(): ?string
    {
        if (blank($this->venue->logo_path)) {
            return null;
        }

        try {
            $disk = Storage::disk((string) Config::string('media.disk'));
            $path = (string) $this->venue->logo_path;

            if (! $disk->exists($path)) {
                return null;
            }

            return 'data:'.($disk->mimeType($path) ?: 'image/png')
                .';base64,'.base64_encode((string) $disk->get($path));
        } catch (Throwable) {
            return null;
        }
    }

    public function calendar(): string
    {
        return (new IcsFile(
            uid: $this->reservation->uuid.'@tablereserved',
            summary: $this->venue->name.' — '.$this->bookingType->name,
            startsAt: $this->reservation->starts_at,
            endsAt: $this->reservation->ends_at,
            description: $this->calendarDescription(),
            location: $this->address() ?? $this->venue->name,
            url: $this->url(),
            reminderAt: $this->reservation->starts_at->subHours(2),
        ))->toString();
    }

    public function calendarFilename(): string
    {
        return Str::slug($this->venue->name.' '.$this->reservation->code).'.ics';
    }

    public function pdfFilename(): string
    {
        return 'booking-'.$this->reservation->code.'.pdf';
    }

    private function calendarDescription(): string
    {
        $lines = [
            'Booking code: '.$this->reservation->code,
            $this->what(),
            $this->party(),
        ];

        if (filled($this->venue->phone)) {
            $lines[] = 'Venue: '.$this->venue->phone;
        }

        $lines[] = $this->url();

        return implode("\n", $lines);
    }
}
