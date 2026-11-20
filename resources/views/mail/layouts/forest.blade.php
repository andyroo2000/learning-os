<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $subject }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@400;500&amp;family=Inter:wght@400;500;600&amp;display=swap" rel="stylesheet">
    <style>
        @media screen and (max-width: 420px) {
            .email-pad { padding-left: 24px !important; padding-right: 24px !important; }
            .email-heading { font-size: 37px !important; line-height: 41px !important; }
        }
    </style>
    <!--[if mso]><style>.email-serif { font-family: Georgia, serif !important; }</style><![endif]-->
</head>
<body style="margin: 0; padding: 0; background-color: #edf0e4; color: #11335c; font-family: Inter, Arial, sans-serif; color-scheme: light;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #edf0e4;">
        <tr><td align="center" style="padding: 32px 12px;">
            <!--[if mso]><table role="presentation" width="520" cellspacing="0" cellpadding="0" border="0"><tr><td><![endif]-->
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: 520px; background-color: #fffdf7; border-radius: 4px;">
                <tr><td class="email-pad" style="padding: 32px 36px 34px; background-color: #407c35; border-radius: 4px 4px 0 0;">
                    <img src="{{ $message->embed(resource_path('images/mail/convolab-wordmark.png')) }}" width="104" height="43" alt="CONVO-LAB — コンボ・ラボ" style="display: block; border: 0; width: 104px; height: 43px; color: #fff29c;">
                    <p style="margin: 30px 0 12px; color: #ffffff; font-size: 10px; line-height: 14px; font-weight: 600; letter-spacing: 1.8px; text-transform: uppercase;">{{ __('Account security') }}</p>
                    <h1 class="email-heading email-serif" style="max-width: 300px; margin: 0; color: #ffffff; font-family: 'Crimson Pro', Georgia, serif; font-size: 40px; font-weight: 500; line-height: 44px; letter-spacing: -0.7px;">{{ $heading }}</h1>
                </td></tr>
                <tr><td class="email-pad" style="padding: 32px 36px 0;">
                    <p class="email-serif" style="max-width: 370px; margin: 0; color: #48627f; font-family: 'Crimson Pro', Georgia, serif; font-size: 19px; line-height: 28px;">{{ $intro }}</p>
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-top: 25px;">
                        <tr><td bgcolor="#356edc" style="background-color: #356edc; border-radius: 5px; mso-padding-alt: 12px 24px;">
                            <a class="email-serif" href="{{ $actionUrl }}" style="display: inline-block; padding: 12px 24px; color: #ffffff; font-family: 'Crimson Pro', Georgia, serif; font-size: 19px; font-weight: 500; line-height: 25px; text-decoration: none; border-radius: 5px;">{{ $actionText }} <span aria-hidden="true">&nbsp; &rarr;</span></a>
                        </td></tr>
                    </table>
                    <p style="margin: 12px 0 0; color: #48627f; font-size: 12px; line-height: 18px;">{{ $expiry }}</p>
                    <p style="margin: 27px 0 0; padding-top: 22px; border-top: 1px solid #dddfd1; color: #48627f; font-size: 12px; line-height: 20px;">{{ $safety }}</p>
                    <p style="margin: 20px 0 5px; color: #48627f; font-size: 11px; line-height: 18px;">{{ __('Button not working? Copy this link:') }}</p>
                    <p style="margin: 0; font-size: 11px; line-height: 18px; word-break: break-all; overflow-wrap: anywhere;"><a href="{{ $actionUrl }}" style="color: #48627f; text-decoration: underline;">{{ $actionUrl }}</a></p>
                </td></tr>
                <tr><td class="email-pad" style="padding: 32px 36px 26px; color: #48627f; font-size: 11px; line-height: 17px;">&copy; {{ now()->year }} ConvoLab</td></tr>
            </table>
            <!--[if mso]></td></tr></table><![endif]-->
        </td></tr>
    </table>
</body>
</html>
