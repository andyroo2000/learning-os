<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify your ConvoLab email</title>
</head>
<body style="font-family: Arial, sans-serif; color: #172033; line-height: 1.5;">
    <p>Hello {{ $name }},</p>
    <p>Verify your email address to finish setting up your ConvoLab account.</p>
    <p><a href="{{ $verificationUrl }}">Verify email address</a></p>
    <p>This link expires in 24 hours.</p>
</body>
</html>
