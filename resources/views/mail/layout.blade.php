{{--
    The shell every message in this system is built in (SPEC §27).

    Tables and inline styles, because email clients are twenty years behind
    browsers and Outlook is behind the rest of them. Two rules follow from
    that: no stylesheet is relied on, and every cell that holds text states its
    own background, so that a client forcing dark mode cannot leave dark text
    on a dark panel.

    The only colour that varies is the venue's accent, and it appears once — a
    band across the top. A voucher should look like the venue, not like us.
--}}
@php
    $accent = $venue->accent_color ?: '#0e6e6e';
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $venue->name }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f5f4;">
    <div style="display:none;font-size:1px;color:#f5f5f4;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">
        @yield('preheader')
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="background-color:#f5f5f4;">
        <tr>
            <td align="center" style="padding:24px 12px;">

                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"
                       style="width:100%;max-width:600px;background-color:#ffffff;border:1px solid #e7e5e4;border-radius:12px;overflow:hidden;">

                    <tr>
                        <td style="background-color:{{ $accent }};height:4px;line-height:4px;font-size:0;">&nbsp;</td>
                    </tr>

                    <tr>
                        <td style="padding:24px 28px 0;background-color:#ffffff;">
                            @if ($venue->logoUrl())
                                <img src="{{ $venue->logoUrl() }}" alt="{{ $venue->name }}"
                                     width="48" height="48"
                                     style="display:block;width:48px;height:48px;border-radius:8px;border:0;">
                            @endif
                            <div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:13px;line-height:1.4;color:#78716c;letter-spacing:0.04em;text-transform:uppercase;padding-top:{{ $venue->logoUrl() ? '12px' : '0' }};">
                                {{ $venue->name }}
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:8px 28px 28px;background-color:#ffffff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:16px;line-height:1.55;color:#1c1917;">
                            @yield('content')
                        </td>
                    </tr>

                </table>

                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"
                       style="width:100%;max-width:600px;">
                    <tr>
                        <td style="padding:16px 28px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:#78716c;">
                            @hasSection('footer')
                                @yield('footer')
                            @else
                                {{ $venue->name }}@if (filled($venue->address)), {{ $venue->address }}@endif
                                @if (filled($venue->phone))<br>{{ $venue->phone }}@endif
                            @endif
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>
</body>
</html>
