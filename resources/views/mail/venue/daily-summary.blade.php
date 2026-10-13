{{--
    The morning list (SPEC §10.2).

    Grouped by booking type, in arrival order, with the guest's note inline —
    because that is the line somebody has to act on before service. Sent on an
    empty day too: silence looks exactly like a system that has stopped.
--}}
@php
    $accent = $venue->accent_color ?: '#0e6e6e';
@endphp
@extends('mail.layout', ['venue' => $venue])

@section('preheader')
    {{ $date->format('l j F') }} · {{ $total }} booked, {{ $covers }} people
@endsection

@section('content')
    <h1 style="margin:0 0 2px;font-size:22px;line-height:1.25;font-weight:600;letter-spacing:-0.02em;color:#1c1917;">
        {{ $date->format('l j F') }}
    </h1>
    <p style="margin:0 0 22px;font-size:15px;color:#44403c;">
        @if ($total === 0)
            Nothing booked yet today.
        @else
            {{ $total }} {{ \Illuminate\Support\Str::plural('booking', $total) }},
            {{ $covers }} {{ \Illuminate\Support\Str::plural('person', $covers) }} in all.
        @endif
    </p>

    @forelse ($groups as $name => $rows)
        <div style="font-size:12px;letter-spacing:0.06em;text-transform:uppercase;color:#78716c;padding:14px 0 8px;">
            {{ $name }} · {{ $rows->count() }}
        </div>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
               style="border-top:1px solid #e7e5e4;">
            @foreach ($rows as $reservation)
                @php $local = $reservation->startsAtLocal($venue->timezone); @endphp
                <tr>
                    <td width="58" valign="top"
                        style="padding:10px 10px 10px 0;border-bottom:1px solid #e7e5e4;background-color:#ffffff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;font-weight:600;color:#1c1917;">
                        {{ $local->format('H:i') }}
                    </td>
                    <td valign="top"
                        style="padding:10px 0;border-bottom:1px solid #e7e5e4;background-color:#ffffff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1c1917;">
                        {{ $reservation->customer_name }}
                        <span style="color:#78716c;">·
                            {{ $reservation->party_size }}p
                            @if (filled($reservation->resource_snapshot['label'] ?? null))
                                · {{ $reservation->resource_snapshot['label'] }}
                            @endif
                        </span>

                        @if (filled($reservation->notes))
                            <div style="font-size:13px;line-height:1.5;color:#b45309;padding-top:3px;">
                                “{{ $reservation->notes }}”
                            </div>
                        @endif

                        @if ($reservation->voucher_delivery === 'bounced')
                            <div style="font-size:13px;color:#b91c1c;padding-top:3px;">
                                Their confirmation bounced — worth a phone call.
                            </div>
                        @endif
                    </td>
                    <td width="70" valign="top" align="right"
                        style="padding:10px 0;border-bottom:1px solid #e7e5e4;background-color:#ffffff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:14px;color:#78716c;">
                        @if ((int) $reservation->amount_cents > 0)
                            {{ \App\Support\Money::format((int) $reservation->amount_cents) }}
                            @if ($reservation->payment_status !== \App\Enums\PaymentStatus::Paid)
                                <div style="font-size:12px;color:#b45309;">collect</div>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @empty
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
               style="background-color:#fafaf9;border:1px solid #e7e5e4;border-radius:8px;">
            <tr>
                <td style="padding:18px;background-color:#fafaf9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:14px;color:#78716c;">
                    An empty list is still worth sending — it means the system is
                    working and nobody has booked yet.
                </td>
            </tr>
        </table>
    @endforelse

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:24px;">
        <tr>
            <td style="background-color:{{ $accent }};border-radius:8px;">
                <a href="{{ route('dashboard.today') }}"
                   style="display:inline-block;padding:11px 20px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
                    Open today
                </a>
            </td>
        </tr>
    </table>
@endsection

@section('footer')
    Sent each day at {{ str_pad((string) $venue->daily_summary_hour, 2, '0', STR_PAD_LEFT) }}:00,
    {{ $venue->timezone }}. You can change the hour or switch this off in your
    notification settings.
@endsection
