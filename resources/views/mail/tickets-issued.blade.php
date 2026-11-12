@include('mail.partials.head', ['title' => "Order {$order->reference}", 'preheader' => 'Your ticket numbers are inside.'])
      <tr><td style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#9a9a9a;padding-top:24px">Order {{ $order->reference }}</td></tr>
      <tr><td style="font-size:36px;font-weight:700;padding:8px 0 16px">You're in, {{ strtok($order->name, ' ') }}.</td></tr>
      @foreach ($order->lines as $line)
        <tr><td style="border-top:1px solid #222;padding:16px 0">
          <div style="font-size:18px;font-weight:700">{{ $line->competition->title }}</div>
          <div style="color:#9a9a9a;font-size:14px;padding:4px 0 10px">{{ $line->qty }} {{ $line->qty === 1 ? 'ticket' : 'tickets' }} · Drawn {{ $line->competition->draw_at->timezone('Europe/London')->format('l j F, g:ia') }}, live on {{ \App\Http\Resources\SettingsPayload::drawnLiveOn($settings) }}</div>
          <div style="font-size:16px;font-family:Menlo,Consolas,monospace">
            @foreach ($line->tickets->sortBy('number') as $t)<span style="display:inline-block;border:1px solid #444;padding:4px 8px;margin:0 6px 6px 0">#{{ $t->display($line->competition->total_tickets) }}</span>@endforeach
          </div>
        </td></tr>
      @endforeach
      <tr><td style="border-top:1px solid #222;padding:16px 0;font-size:16px">Total paid: <strong>£{{ number_format($order->total_pence / 100, 2) }}</strong></td></tr>
      <tr><td style="padding:8px 0 24px"><a href="{{ $url }}" style="background:#fff;color:#000;text-decoration:none;font-weight:700;padding:14px 22px;display:inline-block">View your tickets</a></td></tr>
      <tr><td style="font-size:13px;color:#9a9a9a;padding:0 0 24px;line-height:1.6">Keep this email: your ticket numbers are also in your account. After the draw, the result and its certificate are on <a href="{{ rtrim(config('raffle.frontend_url'), '/') }}/live-draws" style="color:#bbb">Live draws</a>.</td></tr>
@include('mail.partials.foot')
