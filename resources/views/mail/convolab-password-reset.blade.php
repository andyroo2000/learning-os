@include('mail.layouts.forest', [
    'heading' => __('Reset your password.'),
    'intro' => __('Choose a new password for your ConvoLab account.'),
    'actionText' => __('Reset password'),
    'expiry' => $outroLines[0],
    'safety' => $outroLines[1],
])
