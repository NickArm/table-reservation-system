{{--
    The subscription payment did not go through (SPEC §4.3).

    Not using mail.layout, which brands a message as the venue. This one is
    from us to the owner, and dressing it in their own colours would be
    pretending it came from their own system.

    Two facts, in this order: the payment failed, and the booking page keeps
    working until a named date. Both are in every one of the three emails,
    because whoever opens the third has usually not read the first two.
--}}
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
        @if ($stopped)
            Your booking page has stopped taking new reservations. Renewing brings it straight back.
        @else
            Bookings keep working until {{ $locksOn?->format('j F') }}.
        @endif
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
                                @if ($stopped)
                                    {{ $tenant->name }} has stopped taking bookings
                                @else
                                    Your payment did not go through
                                @endif
                            </h1>

                            <p style="margin:0 0 20px;">
                                The subscription payment for <strong style="color:#1c1917;">{{ $tenant->name }}</strong>
                                was not accepted, and nothing has been charged.
                                @if (filled($reason))
                                    The provider said: {{ rtrim($reason, '.') }}.
                                @endif
                            </p>

                            @if ($stopped)
                                <p style="margin:0 0 20px;">
                                    The booking page is not taking new reservations at the moment, and
                                    the dashboard is read-only. <strong style="color:#1c1917;">Nothing has
                                    been deleted.</strong> The bookings you already have are still listed
                                    and still valid, your export still works, and one payment brings the
                                    rest back exactly as it was.
                                </p>
                            @else
                                <p style="margin:0 0 20px;">
                                    @if ($locksOn !== null)
                                        <strong style="color:#1c1917;">Your booking page keeps working
                                        until {{ $locksOn->format('j F') }}.</strong>
                                    @endif
                                    Guests can book as normal in the meantime — a card that expired is
                                    not a reason to take a venue off the internet mid-season.
                                </p>

                                @if ($day >= 3)
                                    <p style="margin:0 0 20px;">
                                        After that the page stops taking new reservations until the
                                        payment goes through. Nothing is deleted at any point.
                                    </p>
                                @endif
                            @endif

                            <p style="margin:0 0 28px;">
                                <a href="{{ route('dashboard.billing') }}"
                                   style="display:inline-block;padding:12px 22px;border-radius:8px;background-color:#0e6e6e;color:#ffffff;font-weight:600;text-decoration:none;">
                                    @if ($stopped)
                                        Start taking bookings again
                                    @else
                                        Sort out the payment
                                    @endif
                                </a>
                            </p>

                            <p style="margin:0;font-size:13px;color:#78716c;">
                                If the card is fine and this keeps happening, reply to this email — it is
                                usually the bank, and we can see more from our side than you can.
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
