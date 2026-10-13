<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Reservation;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

/**
 * The morning list (SPEC §10.2).
 *
 * One email, grouped by booking type, in arrival order. It exists because the
 * first thing an owner wants at nine in the morning is the shape of the day,
 * and because the alternative — opening a dashboard on a phone with one bar of
 * signal — is how a system gets abandoned in July.
 *
 * It is sent even on an empty day, with one line saying so. Silence is
 * indistinguishable from a system that has stopped working.
 */
final class VenueDailySummary extends Mailable
{
    /**
     * @param  Collection<string, Collection<int, Reservation>>  $groups
     */
    public function __construct(
        public readonly Venue $venue,
        public readonly CarbonImmutable $date,
        public readonly Collection $groups,
        public readonly int $total,
        public readonly int $covers,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->total === 0
            ? 'Today at '.$this->venue->name.' · nothing booked'
            : 'Today at '.$this->venue->name.' · '.$this->total.' booked, '.$this->covers.' people';

        return new Envelope(
            from: new Address((string) Config::string('mail.from.address'), (string) $this->venue->name),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.venue.daily-summary');
    }
}
