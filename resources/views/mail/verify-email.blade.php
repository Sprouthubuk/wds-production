@include('mail.partials.head', ['title' => 'Confirm your email', 'preheader' => 'One tap to confirm this is your email address.'])
      <tr><td style="font-size:32px;font-weight:700;padding:24px 0 12px">Confirm your email, {{ $name }}.</td></tr>
      <tr><td style="font-size:16px;line-height:1.6;color:#d0d0d0;padding-bottom:20px">
        Tap the button to confirm this is your email address. You need a confirmed email to enter our free competitions.
      </td></tr>
      <tr><td style="padding:0 0 20px"><a href="{{ $url }}" style="background:#fff;color:#000;text-decoration:none;font-weight:700;padding:14px 22px;display:inline-block">Confirm my email</a></td></tr>
      <tr><td style="font-size:13px;color:#9a9a9a;line-height:1.6;padding-bottom:24px">The link works for {{ $hours }} hours. If you didn't make an account with us, ignore this email.<br>If the button doesn't work, copy this link: <span style="word-break:break-all;color:#bbb">{{ $url }}</span></td></tr>
@include('mail.partials.foot')
