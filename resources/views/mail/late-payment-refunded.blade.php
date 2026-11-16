@include('mail.partials.head', ['title' => "Order {$order->reference}", 'preheader' => "Your payment of {$amount} has been refunded."])
      <tr><td style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#9a9a9a;padding-top:24px">{{ $settings['tradingName'] }} · Order {{ $order->reference }}</td></tr>
      <tr><td style="font-size:32px;font-weight:700;padding:8px 0 16px">Your payment of {{ $amount }} has been refunded.</td></tr>
      <tr><td style="border-top:1px solid #222;padding:16px 0;font-size:16px;line-height:1.6">{{ $explanation }}</td></tr>
      <tr><td style="color:#9a9a9a;font-size:14px;padding:0 0 16px">No tickets were issued for this order.</td></tr>
      <tr><td style="padding:8px 0 24px"><a href="{{ $competitionsUrl }}" style="background:#fff;color:#000;text-decoration:none;font-weight:700;padding:14px 22px;display:inline-block">See the competitions</a></td></tr>
@include('mail.partials.foot')
