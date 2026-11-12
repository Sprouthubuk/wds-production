@include('mail.partials.head', ['title' => 'Test email'])
      <tr><td style="font-size:32px;font-weight:700;padding:24px 0 12px">It works.</td></tr>
      <tr><td style="font-size:16px;line-height:1.6;color:#d0d0d0;padding-bottom:24px">This test email was sent by the WDS Competitions API through the <strong style="color:#fff">{{ $mailer }}</strong> mailer at {{ $sentAt }} UK time. If it arrived in your inbox (not spam) from the right sender, customer emails are set up correctly.</td></tr>
@include('mail.partials.foot')
