{{--
    The invoice list, to us.

    Plainer than the customer-facing mail on purpose: this one is a working
    list that gets read on a phone and acted on from a desk, not a message
    that has to persuade anybody of anything.
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
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="background-color:#f5f5f4;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                       style="max-width:560px;background-color:#ffffff;border-radius:12px;">
                    <tr>
                        <td style="padding:32px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;line-height:1.55;color:#44403c;">

                            <h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;font-weight:600;letter-spacing:-0.02em;color:#1c1917;">
                                {{ count($due) === 1 ? 'One account to invoice' : count($due).' accounts to invoice' }}
                            </h1>

                            <p style="margin:0 0 24px;color:#78716c;">
                                These pay you directly, so nothing will renew them on its own.
                                When the date passes, the booking page stops.
                            </p>

                            @foreach ($due as $row)
                                @php
                                    $ends = $row['subscription']->current_period_end;
                                    $days = $row['subscription']->daysLeft();
                                @endphp
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                       style="margin:0 0 12px;border:1px solid #e7e5e4;border-radius:8px;">
                                    <tr>
                                        <td style="padding:16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#44403c;">
                                            <strong style="color:#1c1917;">{{ $row['tenant']->name }}</strong>
                                            <br>
                                            {{ $row['subscription']->plan?->name }} ·
                                            {{ $row['subscription']->price() }}
                                            {{ $row['subscription']->plan?->billing_period->per() }}
                                            <br>
                                            <span style="color:{{ $days <= 0 ? '#b91c1c' : '#78716c' }};">
                                                @if ($days <= 0)
                                                    Ran out {{ $ends?->format('j M') }}
                                                @elseif ($days === 1)
                                                    Runs out tomorrow, {{ $ends?->format('j M') }}
                                                @else
                                                    Runs out in {{ $days }} days, {{ $ends?->format('j M') }}
                                                @endif
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                            @endforeach

                            <p style="margin:24px 0 0;">
                                <a href="{{ route('admin.tenants.index') }}"
                                   style="display:inline-block;padding:12px 22px;border-radius:8px;background-color:#0e6e6e;color:#ffffff;font-weight:600;text-decoration:none;">
                                    Open the accounts
                                </a>
                            </p>

                            <p style="margin:20px 0 0;font-size:13px;color:#78716c;">
                                Once the money is in, use <strong>Extend the subscription</strong> on
                                the account and put the invoice number in the reason — it goes in the
                                log and in their billing history.
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
