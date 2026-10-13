<?php

declare(strict_types=1);

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The notification settings, as typed (SPEC §10.2).
 *
 * Addresses arrive as one textarea rather than a list of inputs, because that
 * is how an owner thinks about it — three lines, paste, done — and because
 * adding a row of inputs means JavaScript this screen does not otherwise need.
 */
final class UpdateNotificationSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notify_on_booking' => ['nullable', 'boolean'],
            'notification_emails' => ['nullable', 'string', 'max:2000'],
            'daily_summary_hour' => ['nullable', 'integer', 'between:0,23'],
            'daily_summary' => ['nullable', 'boolean'],
            'quiet_hours_from' => ['nullable', 'date_format:H:i'],
            'quiet_hours_to' => ['nullable', 'date_format:H:i', 'required_with:quiet_hours_from'],
            'reminders' => ['nullable', 'boolean'],
            'reminder_hours_before' => ['nullable', 'integer', 'between:1,168'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quiet_hours_to.required_with' => 'Quiet hours need an end as well as a start.',
            'reminder_hours_before.between' => 'A reminder can be between an hour and a week before.',
        ];
    }

    /**
     * Anything that is not a valid address is dropped rather than refused: a
     * stray comma should not stop the rest of the form from saving.
     *
     * @return list<string>
     */
    public function extraEmails(): array
    {
        $raw = (string) $this->input('notification_emails', '');

        $candidates = preg_split('/[\s,;]+/', $raw) ?: [];

        $valid = array_filter(
            array_map('trim', $candidates),
            static fn (string $email): bool => $email !== ''
                && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        );

        return array_values(array_unique(array_map('strtolower', $valid)));
    }

    public function summaryHour(): ?int
    {
        if (! $this->boolean('daily_summary')) {
            return null;
        }

        return (int) ($this->input('daily_summary_hour') ?? 8);
    }

    public function reminderHours(): ?int
    {
        if (! $this->boolean('reminders')) {
            return null;
        }

        return (int) ($this->input('reminder_hours_before') ?? 24);
    }
}
