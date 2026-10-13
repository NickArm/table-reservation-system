{{--
    The printable voucher (SPEC §11.2).

    DejaVu Sans throughout: it is the one font bundled with the renderer that
    covers Greek, and a venue called Κρύσταλλο should not appear on its own
    guests' paperwork as a row of boxes.

    One page, no colour beyond the venue's accent, and laid out so that the
    code and the QR survive a bad photocopy.
--}}
@php
    $accent = $voucher->venue->accent_color ?: '#0e6e6e';
    $reservation = $voucher->reservation;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $reservation->code }} · {{ $voucher->venue->name }}</title>
    <style>
        @page { margin: 22mm 18mm; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10.5pt;
            line-height: 1.5;
            color: #1c1917;
            margin: 0;
        }

        .rule { height: 3pt; background: {{ $accent }}; margin-bottom: 14pt; }

        .venue { font-size: 9pt; color: #78716c; letter-spacing: 0.08em; text-transform: uppercase; }
        h1 { font-size: 19pt; margin: 2pt 0 2pt; font-weight: bold; }
        .sub { color: #44403c; margin: 0 0 16pt; }

        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }

        .codebox {
            border: 1pt dashed #a8a29e;
            background: #fafaf9;
            padding: 10pt;
            text-align: center;
        }
        .codebox .cap { font-size: 8pt; color: #78716c; letter-spacing: 0.08em; text-transform: uppercase; }
        .codebox .val { font-size: 20pt; font-weight: bold; letter-spacing: 0.14em; }

        .facts td { padding: 0 0 7pt; }
        .facts .k { width: 30%; color: #78716c; font-size: 9.5pt; }
        .facts .note { color: #78716c; font-size: 9pt; }

        h2 { font-size: 11pt; margin: 16pt 0 4pt; }
        p { margin: 0 0 6pt; }

        .policy { color: #44403c; font-size: 9.5pt; }

        .disclaimer {
            margin-top: 20pt;
            border-top: 0.75pt solid #e7e5e4;
            padding-top: 8pt;
            font-size: 9pt;
            color: #44403c;
        }
        .footer { font-size: 8.5pt; color: #78716c; margin-top: 4pt; }
    </style>
</head>
<body>
    <div class="rule"></div>

    <table>
        <tr>
            <td style="width: 62%;">
                @if ($voucher->logoDataUri() !== null)
                    <img src="{{ $voucher->logoDataUri() }}" alt="" style="height: 34pt; margin-bottom: 6pt;">
                @endif
                <div class="venue">{{ $voucher->venue->name }}</div>
                <h1>Booking confirmation</h1>
                <p class="sub">{{ $voucher->when() }}</p>
            </td>
            <td style="width: 38%;">
                <div class="codebox">
                    <div class="cap">Booking code</div>
                    <div class="val">{{ $reservation->code }}</div>
                    <img src="{{ $voucher->qrDataUri(6) }}" alt="" style="width: 108pt; height: 108pt; margin-top: 6pt;">
                </div>
            </td>
        </tr>
    </table>

    <table class="facts" style="margin-top: 14pt;">
        <tr><td class="k">When</td><td>{{ $voucher->when() }} · {{ $voucher->startsAt()->format('H:i') }}–{{ $voucher->endsAt()->format('H:i') }}</td></tr>
        <tr><td class="k">What</td><td>{{ $voucher->what() }}</td></tr>

        @if ($voucher->includes() !== '')
            <tr><td class="k">Includes</td><td>{{ $voucher->includes() }}</td></tr>
        @endif

        <tr><td class="k">Guests</td><td>{{ $voucher->party() }}</td></tr>
        <tr><td class="k">Booked by</td><td>
            {{ $reservation->customer_name }}
            @if (filled($reservation->customer_phone))
                <div class="note">{{ $reservation->customer_phone }}</div>
            @endif
            @if (filled($reservation->customer_email))
                <div class="note">{{ $reservation->customer_email }}</div>
            @endif
        </td></tr>

        @if ($voucher->amount() !== null)
            <tr><td class="k">{{ $voucher->amountLabel() }}</td><td>
                {{ $voucher->amount() }}
                @if ($voucher->depositLabel() !== null)
                    <div class="note">{{ $voucher->depositLabel() }}</div>
                @endif
            </td></tr>
        @endif

        @if (filled($reservation->notes))
            <tr><td class="k">Your note</td><td>{{ $reservation->notes }}</td></tr>
        @endif
    </table>

    <h2>To change or cancel</h2>
    @if (filled($voucher->venue->phone))
        <p>Please call {{ $voucher->venue->name }} on <strong>{{ $voucher->venue->phone }}</strong>.</p>
    @else
        <p>Please contact the venue.</p>
    @endif
    <p class="policy">{{ $voucher->cancellationPolicy() }}</p>

    @if ($voucher->address() !== null)
        <h2>Finding us</h2>
        <p>{{ $voucher->address() }}</p>
    @endif

    <div class="disclaimer">
        <strong>This is a booking confirmation and NOT a tax document.</strong>
        Any receipt or invoice for money paid is issued by {{ $voucher->venue->name }}.
    </div>

    <div class="footer">
        {{ $voucher->url() }}
    </div>
</body>
</html>
