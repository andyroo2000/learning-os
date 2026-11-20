ConvoLab

{!! __('Reset your password') !!}

{!! __('Use the link below to choose a new password for your ConvoLab account.') !!}

{!! $actionUrl !!}

@foreach ($outroLines as $line)
{!! $line !!}

@endforeach
