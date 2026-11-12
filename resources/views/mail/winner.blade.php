@include('mail.partials.head', ['title' => "You've won", 'preheader' => "Ticket #{$ticket} is the winner."])
      <tr><td style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#9a9a9a;padding-top:24px">{{ $winner->competition->title }}</td></tr>
      <tr><td style="font-size:40px;font-weight:700;padding:8px 0 16px">You've won, {{ $first }}.</td></tr>
      <tr><td style="padding-bottom:16px"><span style="display:inline-block;background:#fff;color:#000;font-family:Menlo,Consolas,monospace;font-size:22px;font-weight:700;padding:8px 14px">#{{ $ticket }}</span></td></tr>
      <tr><td style="font-size:16px;line-height:1.6;color:#d0d0d0;padding-bottom:20px">
        Your ticket was drawn as the winner of <strong style="color:#fff">{{ $winner->prize ?: $winner->competition->title }}</strong>. Here's what happens next:<br>
        1. We'll call and email you to arrange the handover.<br>
        2. Upload photo ID in your account so we can confirm you're 18 or over. The prize is handed over once it's approved.<br>
        3. Collect your prize, or we'll arrange delivery where that's offered.
      </td></tr>
      <tr><td style="padding:0 0 20px"><a href="{{ $accountUrl }}" style="background:#fff;color:#000;text-decoration:none;font-weight:700;padding:14px 22px;display:inline-block">Go to my account</a></td></tr>
      <tr><td style="font-size:13px;color:#9a9a9a;line-height:1.6;padding-bottom:24px">The draw is publicly verifiable: <a href="{{ $verifyUrl }}" style="color:#bbb">check the result</a>. We will never ask you to pay anything to receive your prize.</td></tr>
@include('mail.partials.foot')
