{{--
    The venue has cancelled a booking (SPEC §8.3).

    No apologising on the venue's behalf and no cheer. What was cancelled, how
    much is coming back, roughly when, and a number to ring. A guest reading
    this is already annoyed; the kindest thing the message can do is be
    unambiguous.
--}}
@extends('mail.layout', ['venue' => $voucher->venue])

@section('preheader')
    {{-- The directive below must not touch the word in front of it. Blade
         only recognises one where the preceding character is not a word
         character, so a directive glued to a letter stays literal text while
         its closing partner still compiles — and the view then dies with
         "unexpected endif". Keep the line break. --}}
    {{ $voucher->when() }} · cancelled
    @if ($refundedCents > 0) · {{ \App\Support\Money::formatExact($refundedCents) }} refunded @endif
@endsection

@section('content')
    <h1 style="margin:0 0 4px;font-size:24px;line-height:1.25;font-weight:600;letter-spacing:-0.02em;color:#1c1917;">
        Your booking has been cancelled
    </h1>
    <p style="margin:0 0 22px;font-size:15px;color:#44403c;">
        {{ $voucher->venue->name }} has cancelled the booking below.
    </p>

    @if (filled($reason))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
               style="background-color:#fffbeb;border:1px solid #fde68a;border-radius:8px;margin-bottom:22px;">
            <tr>
                <td style="padding:14px 16px;background-color:#fffbeb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;color:#44403c;">
                    <strong style="color:#b45309;">Reason given</strong><br>
                    {{ $reason }}
                </td>
            </tr>
        </table>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        @include('mail.partials.fact', ['label' => 'Was booked for', 'value' => $voucher->when()])
        @include('mail.partials.fact', ['label' => 'What', 'value' => $voucher->what()])
        @include('mail.partials.fact', ['label' => 'Code', 'value' => $voucher->reservation->code])

        @if ($refundedCents > 0)
            @include('mail.partials.fact', [
                'label' => 'Refunded',
                'value' => \App\Support\Money::formatExact($refundedCents),
                'note' => 'Back on the card you paid with, usually within 5 to 10 working days.',
            ])
        @endif
    </table>

    @if ($refundedCents <= 0 && $voucher->amount() !== null)
        <p style="margin:18px 0 0;font-size:14px;color:#44403c;">
            Nothing has been refunded automatically. If you believe a refund is
            due, please speak to the venue
            @if (filled($voucher->venue->phone))
                on <a href="tel:{{ $voucher->venue->phone }}" style="color:{{ $voucher->venue->accent_color ?: '#0e6e6e' }};">{{ $voucher->venue->phone }}</a>
            @endif.
        </p>
    @endif

    @if (filled($voucher->venue->phone))
        <p style="margin:22px 0 0;font-size:14px;color:#44403c;">
            To rebook, call {{ $voucher->venue->name }} on
            <a href="tel:{{ $voucher->venue->phone }}" style="color:{{ $voucher->venue->accent_color ?: '#0e6e6e' }};">{{ $voucher->venue->phone }}</a>.
        </p>
    @endif
@endsection

@section('footer')
    <strong style="color:#44403c;">This notice is not a tax document.</strong><br>
    {{ $voucher->venue->name }}@if (filled($voucher->venue->phone)) · {{ $voucher->venue->phone }}@endif
@endsection
