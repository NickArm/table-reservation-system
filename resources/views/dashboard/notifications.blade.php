{{--
    Notification settings (SPEC §10.2).

    Four questions, in the order an owner cares about them, each one already
    answered sensibly. The screen's job is to let somebody turn the night-time
    emails off and go back to work.
--}}
@extends('layouts.dashboard')

@section('title', 'Alerts — '.$tenant->name)

@section('main')
    <div class="page-head">
        <h1>Alerts</h1>
        <p>Who hears about a booking, and when.</p>
    </div>

    {{-- What is on, in one line. Somebody who opened this to check whether
         the morning email is still coming can close it again without reading
         four cards. --}}
    @php
        $on = array_values(array_filter([
            $venue->notify_on_booking ? 'an email for every booking' : null,
            $venue->daily_summary_hour !== null
                ? 'the day ahead at '.str_pad((string) $venue->daily_summary_hour, 2, '0', STR_PAD_LEFT).':00'
                : null,
            $venue->reminder_hours_before !== null
                ? 'a reminder to guests '.$venue->reminder_hours_before.'h before'
                : null,
        ]));
    @endphp

    <div class="notice" style="margin-bottom:24px;max-width:640px">
        @if ($on === [])
            <strong>Nothing is being sent.</strong>
            Bookings still arrive and the bell still fills — you just hear nothing
            about them by email.
        @else
            <strong>Right now you get {{ implode(', and ', $on) }}.</strong>
            @if ($venue->quiet_hours_from !== null)
                Anything between {{ substr((string) $venue->quiet_hours_from, 0, 5) }}
                and {{ substr((string) $venue->quiet_hours_to, 0, 5) }} waits until morning.
            @endif
        @endif
    </div>

    <form method="POST" action="{{ route('dashboard.notifications.update') }}" class="stack" style="max-width:640px">
        @csrf
        @method('PATCH')

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">New bookings</h2>
            </div>

            <label class="consent">
                <input type="checkbox" name="notify_on_booking" value="1"
                       @checked($venue->notify_on_booking)>
                <span>
                    <strong>Email me every booking</strong>
                    <small class="muted">
                        The bell beside your venue name fills in either way.
                        Turning this off only stops the emails.
                    </small>
                </span>
            </label>

            <div>
                <label class="label" for="notification_emails">Send to these addresses as well</label>
                <textarea class="field" id="notification_emails" name="notification_emails" rows="3"
                          placeholder="manager@example.com&#10;kitchen@example.com">{{ old('notification_emails', implode("\n", (array) ($venue->notification_emails ?? []))) }}</textarea>
                <p class="muted" style="margin:6px 0 0;font-size:var(--step--2)">
                    One per line. Your own address is always included — currently
                    {{ implode(', ', $recipients) }}.
                </p>
            </div>
        </section>

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">Quiet hours</h2>
            </div>

            <p class="muted" style="margin:0">
                Bookings made during these hours are emailed when they end, not
                in the middle of the night. Nothing is lost, only delayed.
            </p>

            <div class="phone">
                <div>
                    <label class="label" for="quiet_hours_from">From</label>
                    <input class="field" type="time" id="quiet_hours_from" name="quiet_hours_from"
                           value="{{ old('quiet_hours_from', $venue->quiet_hours_from ? substr((string) $venue->quiet_hours_from, 0, 5) : '') }}">
                </div>
                <div>
                    <label class="label" for="quiet_hours_to">Until</label>
                    <input class="field" type="time" id="quiet_hours_to" name="quiet_hours_to"
                           value="{{ old('quiet_hours_to', $venue->quiet_hours_to ? substr((string) $venue->quiet_hours_to, 0, 5) : '') }}">
                </div>
            </div>
        </section>

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">The morning list</h2>
            </div>

            <label class="consent">
                <input type="checkbox" name="daily_summary" value="1"
                       @checked($venue->daily_summary_hour !== null)>
                <span>
                    <strong>Email me the day ahead</strong>
                    <small class="muted">
                        Every booking for today, grouped and in arrival order,
                        with the notes guests left.
                    </small>
                </span>
            </label>

            <div>
                <label class="label" for="daily_summary_hour">At</label>
                <select class="field" id="daily_summary_hour" name="daily_summary_hour">
                    @for ($hour = 5; $hour <= 14; $hour++)
                        <option value="{{ $hour }}"
                            @selected((int) ($venue->daily_summary_hour ?? 8) === $hour)>
                            {{ str_pad((string) $hour, 2, '0', STR_PAD_LEFT) }}:00
                        </option>
                    @endfor
                </select>
                <p class="muted" style="margin:6px 0 0;font-size:var(--step--2)">
                    {{ $venue->timezone }} — your own time, including through the
                    clock change.
                </p>
            </div>
        </section>

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">Guest reminders</h2>
            </div>

            <label class="consent">
                <input type="checkbox" name="reminders" value="1"
                       @checked($venue->reminder_hours_before !== null)>
                <span>
                    <strong>Remind guests before they are due</strong>
                    <small class="muted">
                        The cheapest thing there is against a no-show. Nobody is
                        reminded about a booking they made in the last two hours.
                    </small>
                </span>
            </label>

            <div>
                <label class="label" for="reminder_hours_before">How long before</label>
                <select class="field" id="reminder_hours_before" name="reminder_hours_before">
                    @foreach ([2 => '2 hours', 4 => '4 hours', 12 => '12 hours', 24 => 'A day', 48 => 'Two days'] as $hours => $label)
                        <option value="{{ $hours }}"
                            @selected((int) ($venue->reminder_hours_before ?? 24) === $hours)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </div>
        </section>

        <div>
            <button class="btn" type="submit">Save</button>
        </div>
    </form>
@endsection
