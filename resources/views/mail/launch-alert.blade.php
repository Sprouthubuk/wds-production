@include('mail.partials.head', ['title' => $competition->title, 'preheader' => "{$competition->title} is open for entries. {$odds} odds."])
      @if ($test)<tr><td style="background:#ffcc00;color:#000;font-weight:700;padding:10px 12px;margin-top:16px">TEST: this is a preview of the launch email. Nobody else received it.</td></tr>@endif
      <tr><td style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#9a9a9a;padding-top:24px">{{ $settings['tradingName'] }} · Now live</td></tr>
      <tr><td style="font-size:32px;font-weight:700;padding:8px 0 16px">{{ $competition->title }} is open for entries.</td></tr>
      <tr><td style="border-top:1px solid #222;padding:16px 0;font-size:16px;line-height:1.6">
        {{ $price }} · only {{ number_format((int) $competition->total_tickets) }} {{ $competition->isFree() ? 'entries' : 'tickets' }} · <strong>{{ $odds }} odds</strong><br>
        @if ($closes)Entries close: <strong>{{ $closes }}</strong> (UK time).<br>@endif
        @if ($draw)Live draw: <strong>{{ $draw }}</strong> (UK time).<br>@endif
        @if ($postal){{ $postal }}@endif
      </td></tr>
      <tr><td style="padding:8px 0 24px"><a href="{{ $competitionUrl }}" style="background:#fff;color:#000;text-decoration:none;font-weight:700;padding:14px 22px;display:inline-block">Enter now</a></td></tr>
      <tr><td style="color:#9a9a9a;font-size:13px;padding:0 0 16px">You're getting this because you asked us to email you when WDS Competitions launch. This is the only launch email for this competition. <a href="{{ $unsubscribeUrl }}" style="color:#9a9a9a">Unsubscribe</a>. 18+, UK only. Free postal entry on every paid competition.</td></tr>
@include('mail.partials.foot')
