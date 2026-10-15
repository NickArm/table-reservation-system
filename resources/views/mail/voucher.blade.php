{{--
    The booking confirmation (SPEC §11.1).

    Ordered by what a person actually needs: the code and the QR first, because
    that is what gets them through the door; then when and what; then the
    money; then how to change it. The disclaimer is last and unmissable.
--}}
@extends('mail.layout', ['venue' => $voucher->venue])

@section('preheader')
    {{ $voucher->reservation->code }} · {{ $voucher->when() }} · {{ $voucher->what() }}
@endsection

@section('content')
    <h1 style="margin:0 0 4px;font-size:26px;line-height:1.2;font-weight:600;letter-spacing:-0.02em;color:#1c1917;">
        You are booked
    </h1>
    <p style="margin:0 0 24px;font-size:15px;color:#44403c;">
        {{ $voucher->reservation->customer_name }}, here is everything you need.
        Show this at the door.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="background-color:#fafaf9;border:1px dashed #d6d3d1;border-radius:8px;">
        <tr>
            <td align="center" style="padding:20px;background-color:#fafaf9;">
                <div style="font-size:12px;letter-spacing:0.06em;text-transform:uppercase;color:#78716c;">
                    Booking code
                </div>
                <div style="font-size:30px;font-weight:600;letter-spacing:0.14em;color:#1c1917;padding:2px 0 14px;">
                    {{ $voucher->reservation->code }}
                </div>

                <img src="{{ $message->embedData($voucher->qrPng(7), 'booking-qr.png', 'image/png') }}"
                     alt="QR code for booking {{ $voucher->reservation->code }}"
                     width="168" height="168"
                     style="display:block;width:168px;height:168px;border:0;background-color:#ffffff;">
            </td>
        </tr>
    </table>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:24px;">
        @include('mail.partials.fact', ['label' => 'When', 'value' => $voucher->when()])
        @include('mail.partials.fact', ['label' => 'What', 'value' => $voucher->what()])

        @if ($voucher->includes() !== '')
            @include('mail.partials.fact', ['label' => 'Includes', 'value' => $voucher->includes()])
        @endif

        @include('mail.partials.fact', [
            'label' => 'Guests',
            'value' => $voucher->party(),
        ])

        @if ($voucher->amount() !== null)
            @include('mail.partials.fact', [
                'label' => $voucher->amountLabel(),
                'value' => $voucher->amount(),
                'note' => $voucher->depositLabel(),
            ])
        @endif

        @if (filled($voucher->reservation->notes))
            @include('mail.partials.fact', [
                'label' => 'Your note',
                'value' => $voucher->reservation->notes,
            ])
        @endif
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:26px;">
        <tr>
            <td style="background-color:{{ $voucher->venue->accent_color ?: '#0e6e6e' }};border-radius:8px;">
                <a href="{{ $voucher->url() }}"
                   style="display:inline-block;padding:12px 22px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
                    Open your booking
                </a>
            </td>
        </tr>
    </table>

    <hr style="border:0;border-top:1px solid #e7e5e4;margin:28px 0 20px;">

    <h2 style="margin:0 0 6px;font-size:15px;font-weight:600;color:#1c1917;">
        To change or cancel
    </h2>
    <p style="margin:0 0 6px;font-size:14px;color:#44403c;">
        @if (filled($voucher->venue->phone))
            Please call us on
            <a href="tel:{{ $voucher->venue->phone }}" style="color:{{ $voucher->venue->accent_color ?: '#0e6e6e' }};">{{ $voucher->venue->phone }}</a>.
            A phone call is faster than anything we could build.
        @else
            Please contact the venue.
        @endif
    </p>
    <p style="margin:0;font-size:13px;color:#78716c;">
        {{ $voucher->cancellationPolicy() }}
    </p>

    @if ($voucher->address() !== null)
        <h2 style="margin:22px 0 6px;font-size:15px;font-weight:600;color:#1c1917;">
            Finding us
        </h2>
        <p style="margin:0;font-size:14px;color:#44403c;">
            {{ $voucher->address() }}
            @if ($voucher->mapUrl() !== null)
                <br><a href="{{ $voucher->mapUrl() }}" style="color:{{ $voucher->venue->accent_color ?: '#0e6e6e' }};">Open the map</a>
            @endif
        </p>
    @endif
@endsection

@section('footer')
    <strong style="color:#44403c;">This is a booking confirmation, not a tax document.</strong><br>
    {{ $voucher->venue->name }}@if ($voucher->address() !== null), {{ $voucher->address() }}@endif
    @if (filled($voucher->venue->phone))<br>{{ $voucher->venue->phone }}@endif
    <br><br>
    A calendar file and a printable copy are attached.
@endsection
