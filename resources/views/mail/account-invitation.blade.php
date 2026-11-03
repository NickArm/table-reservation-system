{{--
    The account has been opened for them — all that is missing is a password.

    Like the trial notice, deliberately not mail.layout: that one brands a
    message as the venue, and this one is from us to a person who has not seen
    the product yet. Dressing it in their own colours would be pretending it
    came from a system they do not have.

    No password anywhere in it, by design. What is here is a link that lets
    them choose one, and it stops working the moment it is used.
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
        {{ $tenant->name }} is set up — choose a password and it is yours
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
                                {{ $tenant->name }} is ready
                            </h1>

                            <p style="margin:0 0 20px;">
                                Hello {{ $user->name }} — we have set the account up for you. The last
                                thing it needs is a password, which only you should know, so choose one
                                here:
                            </p>

                            <p style="margin:0 0 28px;">
                                <a href="{{ $url }}"
                                   style="display:inline-block;padding:12px 22px;border-radius:8px;background-color:#0e6e6e;color:#ffffff;font-weight:600;text-decoration:none;">
                                    Choose your password
                                </a>
                            </p>

                            <p style="margin:0 0 20px;font-size:13px;color:#78716c;">
                                The link works once and lasts
                                {{ $days === 1 ? 'a day' : $days.' days' }}. If it has run out by the
                                time you get to it, reply to this message and we will send another —
                                nothing is lost.
                            </p>

                            <p style="margin:0 0 8px;font-size:13px;color:#78716c;">
                                If the button does not work, this is the same link:
                            </p>

                            <p style="margin:0;font-size:13px;word-break:break-all;color:#0e6e6e;">
                                {{ $url }}
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
