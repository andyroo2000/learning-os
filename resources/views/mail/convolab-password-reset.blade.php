<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #FCF9F4; color: #4B1800; font-family: Arial, sans-serif; line-height: 1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #FCF9F4;">
        <tr><td align="center" style="padding: 40px 20px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 520px;">
                <tr><td style="padding-bottom: 28px; font-size: 26px; font-weight: 800; letter-spacing: 2px;">CONVO-LAB</td></tr>
                <tr><td style="padding: 32px 24px; background-color: #FFFFFF; border: 1px solid #E8DDD4; border-radius: 16px;">
                    <h1 style="margin: 0 0 20px; font-size: 24px; line-height: 1.3;">{{ __('Reset your password') }}</h1>
                    <p style="margin: 0 0 24px;">{{ __('Use the button below to choose a new password for your ConvoLab account.') }}</p>
                    <p style="margin: 0 0 28px;">
                        <a href="{{ $actionUrl }}" style="display: inline-block; background-color: #FC8155; color: #4B1800; padding: 14px 24px; border-radius: 8px; font-weight: bold; text-decoration: none;">{{ $actionText }}</a>
                    </p>
                    @foreach ($outroLines as $line)
                        <p style="margin: 0 0 16px;">{{ $line }}</p>
                    @endforeach
                    <p style="margin: 24px 0 8px; padding-top: 20px; border-top: 1px solid #E8DDD4; font-size: 13px;">{{ __('If the button does not work, copy and paste this link into your browser:') }}</p>
                    <p style="margin: 0; font-size: 13px; overflow-wrap: anywhere; word-break: break-all;"><a href="{{ $actionUrl }}" style="color: #4B1800;">{{ $actionUrl }}</a></p>
                </td></tr>
                <tr><td style="padding-top: 24px; font-size: 12px;">&copy; {{ date('Y') }} ConvoLab</td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
