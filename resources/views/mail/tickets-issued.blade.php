<!doctype html>
<html>
<body style="margin:0;background:#000;color:#fff;font-family:Helvetica,Arial,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#000"><tr><td align="center" style="padding:32px 16px">
    <table width="560" cellpadding="0" cellspacing="0" style="max-width:560px">
      <tr><td style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#9a9a9a">Order {{ $order->reference }}</td></tr>
      <tr><td style="font-size:40px;font-weight:700;padding:8px 0 16px">You're in, {{ strtok($order->name, ' ') }}.</td></tr>
      @foreach ($order->lines as $line)
        <tr><td style="border-top:1px solid #222;padding:16px 0">
          <div style="font-size:18px;font-weight:700">{{ $line->competition->title }}</div>
          <div style="color:#9a9a9a;font-size:14px;padding:4px 0 10px">Drawn {{ $line->competition->draw_at->timezone('Europe/London')->format('l j F, g:ia') }} — live on Instagram @{{ $settings['drawsInstagram'] }}</div>
          <div style="font-size:16px;font-family:Menlo,monospace">
            @foreach ($line->tickets->sortBy('number') as $t)<span style="display:inline-block;border:1px solid #444;padding:4px 8px;margin:0 6px 6px 0">#{{ $t->display($line->competition->total_tickets) }}</span>@endforeach
          </div>
        </td></tr>
      @endforeach
      <tr><td style="border-top:1px solid #222;padding:16px 0;font-size:16px">Total paid: <strong>£{{ number_format($order->total_pence / 100, 2) }}</strong></td></tr>
      <tr><td style="padding:8px 0 24px"><a href="{{ $url }}" style="background:#fff;color:#000;text-decoration:none;font-weight:700;padding:14px 22px;display:inline-block">View your tickets</a></td></tr>
      <tr><td style="font-size:12px;color:#777;line-height:1.6">
        Please play responsibly. You can set spending limits or take a break from your account at any time.<br>
        {{ $settings['legalName'] }} trading as {{ $settings['tradingName'] }} · Company no. {{ $settings['companyNumber'] }} · {{ $settings['registeredOffice'] }}
      </td></tr>
    </table>
  </td></tr></table>
</body>
</html>
