{{--
    The nudge before the table is due (SPEC §10.1). Four facts and a phone
    number — anyone wanting more still has the original confirmation.
--}}
@extends('mail.layout', ['venue' => $voucher->venue])

@section('preheader')
    {{ $voucher->when() }} · {{ $voucher->what() }} · code {{ $voucher->reservation->code }}
@endsection

@section('content')
    <h1 style="margin:0 0 4px;font-size:24px;line-height:1.25;font-weight:600;letter-spacing:-0.02em;color:#1c1917;">
        @if ($voucher->startsAt()->isSameDay($voucher->venue->now()))
            See you today at {{ $voucher->startsAt()->format('H:i') }}
        @else
            {{ $voucher->startsAt()->format('l') }} at {{ $voucher->startsAt()->format('H:i') }}
        @endif
    </h1>
    <p style="margin:0 0 22px;font-size:15px;color:#44403c;">
        A short reminder of your booking at {{ $voucher->venue->name }}.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        @include('mail.partials.fact', ['label' => 'When', 'value' => $voucher->when()])
        @include('mail.partials.fact', ['label' => 'What', 'value' => $voucher->what()])
        @include('mail.partials.fact', ['label' => 'Guests', 'value' => $voucher->party()])
        @include('mail.partials.fact', ['label' => 'Code', 'value' => $voucher->reservation->code])

        @if ($voucher->amount() !== null && $voucher->amountLabel() === 'To pay on arrival')
            @include('mail.partials.fact', [
                'label' => 'To pay on arrival',
                'value' => $voucher->amount(),
                'note' => $voucher->depositLabel(),
            ])
        @endif
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:22px;">
        <tr>
            <td style="background-color:{{ $voucher->venue->accent_color ?: '#0e6e6e' }};border-radius:8px;">
                <a href="{{ $voucher->url() }}"
                   style="display:inline-block;padding:11px 20px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
                    Open your booking
                </a>
            </td>
        </tr>
    </table>

    @if (filled($voucher->venue->phone))
        <p style="margin:22px 0 0;font-size:14px;color:#44403c;">
            Running late or need to change something? Call us on
            <a href="tel:{{ $voucher->venue->phone }}" style="color:{{ $voucher->venue->accent_color ?: '#0e6e6e' }};">{{ $voucher->venue->phone }}</a>.
        </p>
    @endif
@endsection
