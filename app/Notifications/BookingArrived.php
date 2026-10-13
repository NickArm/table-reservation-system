<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Notifications\Notification;

/**
 * The bell in the dashboard (SPEC §10.2).
 *
 * Database only: the email is sent separately, to addresses that may not
 * belong to any user account. This is what the owner sees when they are
 * already looking at the system.
 *
 * Everything the bell shows is copied in rather than looked up, so that a
 * cancelled or rearranged booking does not rewrite the history of what was
 * announced.
 */
final class BookingArrived extends Notification
{
    public function __construct(
        private readonly Reservation $reservation,
        private readonly string $timezone,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $local = $this->reservation->startsAtLocal($this->timezone);

        return [
            'kind' => 'booking',
            'reservation_uuid' => (string) $this->reservation->uuid,
            'code' => (string) $this->reservation->code,
            'guest' => (string) $this->reservation->customer_name,
            'party_size' => (int) $this->reservation->party_size,
            'resource' => $this->reservation->resource_snapshot['label'] ?? null,
            'starts_at_local' => $local->format('Y-m-d H:i'),
            'headline' => $local->format('D j M H:i').' · '
                .($this->reservation->resource_snapshot['label'] ?? 'booking').' · '
                .$this->reservation->party_size.'p',
        ];
    }
}
