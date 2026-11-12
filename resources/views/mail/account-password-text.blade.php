{!! $settings['tradingName'] !!}: {!! $newAccount ? 'set your password' : 'reset your password' !!}

{!! $newAccount ? "Set your password, {$name}." : "Reset your password, {$name}." !!}

@if ($newAccount)
We've made your account so your tickets, results and limits are all in one place. Choose a password to sign in:
@else
Someone (hopefully you) asked to reset the password for this account. Choose a new one here:
@endif
{!! $url !!}

The link works for {!! $minutes !!} minutes. If you didn't ask for this, ignore this email and your password won't change.

@include('mail.partials.footer-text')
