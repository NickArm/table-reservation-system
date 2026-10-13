{{--
    What the venue gets (SPEC §10.2).

    Read on a phone, behind a bar, with one hand. So: the booking, the guest's
    phone number as a tappable link, and the note they left — which is the part
    the kitchen actually needs. Everything else can wait for the dashboard.
--}}
@php
    $local = $reservation->startsAtLocal($timezone);
    $accent = $venue->accent_color ?: '#0e6e6e';
@endphp
@extends('mail.layout', ['venue' => $venue])

@section('preheader')
    {{ $local->format('D j M H:i') }} · {{ $reservation->resource_snapshot['label'] ?? 'booking' }} · {{ $reservation->customer_name }}
@endsection

@section('content')
    <h1 style="margin:0 0 18px;font-size:22px;line-height:1.25;font-weight:600;letter-spacing:-0.02em;color:#1c1917;">
        New booking
    </h1>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        @include('mail.partials.fact', [
            'label' => 'When',
            'value' => $local->format('l j F').', '.$local->format('H:i'),
        ])
        @include('mail.partials.fact', [
            'label' => 'What',
            'value' => ($reservation->resource_snapshot['label'] ?? '—')
                .(filled($reservation->resource_snapshot['area'] ?? null) ? ' · '.$reservation->resource_snapshot['area'] : ''),
            'note' => $reservation->snapshotContents() !== '' ? $reservation->snapshotContents() : null,
        ])
        @include('mail.partials.fact', [
            'label' => 'Guests',
            'value' => $reservation->party_size.' '.\Illuminate\Support\Str::plural('person', (int) $reservation->party_size),
        ])
        @include('mail.partials.fact', ['label' => 'Name', 'value' => $reservation->customer_name])

        @if (filled($reservation->customer_phone))
            <tr>
                <td width="110" valign="top" style="padding:0 12px 10px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:13px;color:#78716c;background-color:#ffffff;">
                    Phone
                </td>
                <td valign="top" style="padding:0 0 10px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;background-color:#ffffff;">
                    <a href="tel:{{ $reservation->customer_phone }}" style="color:{{ $accent }};">{{ $reservation->customer_phone }}</a>
                </td>
            </tr>
        @endif

        @include('mail.partials.fact', ['label' => 'Code', 'value' => $reservation->code])

        @if ((int) $reservation->amount_cents > 0)
            @include('mail.partials.fact', [
                'label' => $reservation->payment_status === \App\Enums\PaymentStatus::Paid ? 'Paid online' : 'To collect',
                'value' => \App\Support\Money::formatExact((int) $reservation->amount_cents),
                'note' => filled($reservation->deposit_label_snapshot) ? $reservation->deposit_label_snapshot : null,
            ])
        @endif
    </table>

    @if (filled($reservation->notes))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
               style="background-color:#fffbeb;border:1px solid #fde68a;border-radius:8px;margin-top:16px;">
            <tr>
                <td style="padding:14px 16px;background-color:#fffbeb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:14px;line-height:1.55;color:#44403c;">
                    <strong style="color:#b45309;">The guest wrote</strong><br>
                    {{ $reservation->notes }}
                </td>
            </tr>
        </table>
    @endif

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:22px;">
        <tr>
            <td style="background-color:{{ $accent }};border-radius:8px;">
                <a href="{{ route('dashboard.reservations.show', $reservation) }}"
                   style="display:inline-block;padding:11px 20px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
                    Open in your dashboard
                </a>
            </td>
        </tr>
    </table>
@endsection

@section('footer')
    Replying to this message writes to {{ $reservation->customer_name }}.<br>
    You can change who gets these, and when, in your notification settings.
@endsection
