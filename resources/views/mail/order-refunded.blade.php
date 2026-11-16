@include('mail.partials.head', ['title' => "Order {$order->reference}", 'preheader' => $paragraphs[0]])
      <tr><td style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#9a9a9a;padding-top:24px">{{ $settings['tradingName'] }} · Order {{ $order->reference }}</td></tr>
      <tr><td style="font-size:32px;font-weight:700;padding:8px 0 16px">{{ $headline }}</td></tr>
      <tr><td style="border-top:1px solid #222;padding:16px 0 24px;font-size:16px;line-height:1.6">
        @foreach ($paragraphs as $p)
          {{ $p }}@if (! $loop->last)<br><br>@endif
        @endforeach
      </td></tr>
@include('mail.partials.foot')
