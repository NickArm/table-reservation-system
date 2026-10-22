{{--
    Your account will be deleted on this date (SPEC §29.3).

    Not using mail.layout, which brands a message as the venue — this one is
    from us about their account with us.

    Two facts, and nothing else: the date, and how to stop it. The thirty days
    exist so that a regretted click can be taken back, and this email is the
    only way back.
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
        Nothing is deleted yet. Reply to this email before
        {{ $purgeAt?->format('j F') }} and we will stop it.
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
                                {{ $tenant->name }} will be deleted
                            </h1>

                            <p style="margin:0 0 20px;">
                                We have received the request. Your booking page has stopped
                                taking reservations and your subscription has been cancelled,
                                so nothing further will be charged.
                            </p>

                            @if ($purgeAt !== null)
                                <p style="margin:0 0 20px;">
                                    <strong style="color:#1c1917;">Everything is deleted for good
                                    on {{ $purgeAt->format('j F Y') }}</strong> — bookings,
                                    guests, tables, settings and photographs. After that it
                                    cannot be brought back by us or by anybody else.
                                </p>
                            @endif

                            <p style="margin:0 0 20px;">
                                <strong style="color:#1c1917;">If this was a mistake, reply to
                                this email</strong> and we will stop it. Any time before that
                                date is fine.
                            </p>

                            <p style="margin:0 0 28px;">
                                Your booking page address is kept reserved permanently, so an
                                old link or a printed QR code can never lead somebody to a
                                different venue.
                            </p>

                            <p style="margin:0;font-size:13px;color:#78716c;">
                                Our own invoices for your subscription are kept for as long as
                                tax law requires. They are our accounting records rather than
                                your data.
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
