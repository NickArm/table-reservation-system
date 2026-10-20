{{--
    The trial is nearly over (SPEC §4.2).

    Deliberately not using mail.layout, which brands a message as the venue.
    This one is from us to the owner, and dressing it in their own colours
    would be pretending it came from their own system.

    Short, and led by their own numbers. The only argument that works on
    somebody in the middle of a season is what the fortnight actually
    produced — and if that is nothing, saying so and offering help is more
    use than a deadline.
--}}
@php
    $has = $sofar['bookings'] > 0;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ config('app.name') }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f5f4;">
    <div style="display:none;max-height:0;overflow:hidden;">
        @if ($has)
            {{ $sofar['bookings'] }} bookings, {{ \App\Support\Money::format($sofar['revenue_cents']) }} —
        @endif
        {{ $daysLeft <= 1 ? 'your trial ends tomorrow' : 'your trial ends in '.$daysLeft.' days' }}
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="background-color:#f5f5f4;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                       style="max-width:560px;background-color:#ffffff;border-radius:12px;">
                    <tr>
                        <td style="padding:32px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;line-height:1.55;color:#44403c;">

                            <h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;font-weight:600;letter-spacing:-0.02em;color:#1c1917;">
                                @if ($daysLeft <= 1)
                                    Your trial ends tomorrow
                                @else
                                    Your trial ends in {{ $daysLeft }} days
                                @endif
                            </h1>

                            @if ($has)
                                <p style="margin:0 0 20px;">
                                    In the time you have been trying it, {{ $tenant->name }} has taken
                                    <strong style="color:#1c1917;">{{ $sofar['bookings'] }}</strong>
                                    {{ \Illuminate\Support\Str::plural('booking', $sofar['bookings']) }}@if ($sofar['revenue_cents'] > 0),
                                        worth <strong style="color:#1c1917;">{{ \App\Support\Money::format($sofar['revenue_cents']) }}</strong>@endif.
                                </p>

                                <p style="margin:0 0 20px;">
                                    Those bookings, and everything you have set up, stay exactly as they
                                    are when you pick a plan. If the trial runs out first, nothing is
                                    deleted — the booking page simply stops taking new ones until you
                                    come back.
                                </p>
                            @else
                                <p style="margin:0 0 20px;">
                                    No bookings have come through yet, which usually means the setup is
                                    not quite finished — most often the booking page has not been linked
                                    from the venue's own site.
                                </p>

                                <p style="margin:0 0 20px;">
                                    Reply to this email and we will look at it with you. It takes a few
                                    minutes and it is the difference between the next fortnight being
                                    worth anything and not.
                                </p>
                            @endif

                            <p style="margin:0 0 28px;">
                                <a href="{{ route('dashboard.billing') }}"
                                   style="display:inline-block;padding:12px 22px;border-radius:8px;background-color:#0e6e6e;color:#ffffff;font-weight:600;text-decoration:none;">
                                    See the plans
                                </a>
                            </p>

                            <p style="margin:0;font-size:13px;color:#78716c;">
                                Your data is kept for twelve months whatever you decide, so a venue that
                                closes for the winter comes back in April to everything it had.
                            </p>
                        </td>
                    </tr>
                </table>

                <p style="margin:16px 0 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:12px;color:#a8a29e;">
                    {{ config('app.name') }}
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
