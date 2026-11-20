@include('mail.layouts.forest', [
    'subject' => __('Verify your ConvoLab email'),
    'heading' => __('Verify your email.'),
    'intro' => __('Hello :name, verify your email address to finish setting up your ConvoLab account.', ['name' => $name]),
    'actionUrl' => $verificationUrl,
    'actionText' => __('Verify email address'),
    'expiry' => __('This link expires in 24 hours.'),
    'safety' => __('If you did not create a ConvoLab account, you can ignore this email.'),
])
