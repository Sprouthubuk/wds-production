@include('mail.partials.head', ['title' => $newAccount ? 'Set your password' : 'Reset your password', 'preheader' => 'This link works for '.$minutes.' minutes.'])
      <tr><td style="font-size:32px;font-weight:700;padding:24px 0 12px">{{ $newAccount ? "Set your password, {$name}." : "Reset your password, {$name}." }}</td></tr>
      <tr><td style="font-size:16px;line-height:1.6;color:#d0d0d0;padding-bottom:20px">
        @if ($newAccount)
          We've made your account so your tickets, results and limits are all in one place. Choose a password to sign in.
        @else
          Someone (hopefully you) asked to reset the password for this account. Choose a new one below.
        @endif
      </td></tr>
      <tr><td style="padding:0 0 20px"><a href="{{ $url }}" style="background:#fff;color:#000;text-decoration:none;font-weight:700;padding:14px 22px;display:inline-block">{{ $newAccount ? 'Set my password' : 'Choose a new password' }}</a></td></tr>
      <tr><td style="font-size:13px;color:#9a9a9a;line-height:1.6;padding-bottom:24px">The link works for {{ $minutes }} minutes. If you didn't ask for this, ignore this email and your password won't change.<br>If the button doesn't work, copy this link: <span style="word-break:break-all;color:#bbb">{{ $url }}</span></td></tr>
@include('mail.partials.foot')
