@include('mail.partials.head', ['title' => $competition->title, 'preheader' => "{$competition->title} has been postponed: entries now close {$closes}."])
      <tr><td style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#9a9a9a;padding-top:24px">{{ $settings['tradingName'] }} · Important</td></tr>
      <tr><td style="font-size:32px;font-weight:700;padding:8px 0 16px">{{ $competition->title }} has been postponed.</td></tr>
      <tr><td style="border-top:1px solid #222;padding:16px 0;font-size:16px;line-height:1.6">
        Entries now close: <strong>{{ $closes }}</strong> (UK time)@if ($from), instead of {{ $from }}@endif.<br>
        @if ($draw)Live draw: <strong>{{ $draw }}</strong> (UK time).<br>@endif
        Reason: <strong>{{ $reason }}</strong>
      </td></tr>
      <tr><td style="color:#9a9a9a;font-size:14px;padding:0 0 16px">Your entries stay in the draw exactly as they are: there's nothing you need to do. The change is recorded on the draw's public audit record.</td></tr>
      <tr><td style="padding:8px 0 24px"><a href="{{ $competitionUrl }}" style="background:#fff;color:#000;text-decoration:none;font-weight:700;padding:14px 22px;display:inline-block">View the competition</a></td></tr>
@include('mail.partials.foot')
