@include('mail.partials.head', ['title' => $competition->title, 'preheader' => $entry->status === 'allocated' ? 'Your free postal entry is in the draw.' : "We couldn't accept your postal entry."])
      <tr><td style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#9a9a9a;padding-top:24px">{{ $settings['tradingName'] }} · Free postal entry</td></tr>
      @if ($entry->status === 'allocated')
        <tr><td style="font-size:36px;font-weight:700;padding:8px 0 16px">You're in, {{ strtok($entry->name, ' ') }}.</td></tr>
        <tr><td style="border-top:1px solid #222;padding:16px 0">
          <div style="font-size:18px;font-weight:700">{{ $competition->title }}</div>
          <div style="color:#9a9a9a;font-size:14px;padding:4px 0 10px">Drawn {{ $competition->draw_at->timezone('Europe/London')->format('l j F, g:ia') }}. Same draw, same chance of winning as online entries.</div>
          <div style="font-size:16px;font-family:Menlo,monospace"><span style="display:inline-block;border:1px dashed #666;padding:4px 8px">#{{ $ticket }}</span></div>
        </td></tr>
      @else
        <tr><td style="font-size:32px;font-weight:700;padding:8px 0 16px">We couldn't accept your entry.</td></tr>
        <tr><td style="border-top:1px solid #222;padding:16px 0;font-size:16px;line-height:1.5">
          <div style="font-size:18px;font-weight:700;padding-bottom:6px">{{ $competition->title }}</div>
          Your postal entry received on {{ $entry->receivedMoment()->timezone('Europe/London')->format('j F Y') }} was not entered into the draw.<br>
          Reason: <strong>{{ $entry->rejection_reason }}</strong>.
        </td></tr>
      @endif
      @if ($deadline)
        <tr><td style="color:#9a9a9a;font-size:14px;padding:0 0 16px">{{ $deadline }}</td></tr>
      @endif
      <tr><td style="padding:8px 0 24px"><a href="{{ $competitionUrl }}" style="background:#fff;color:#000;text-decoration:none;font-weight:700;padding:14px 22px;display:inline-block">View the competition</a></td></tr>
@include('mail.partials.foot')
