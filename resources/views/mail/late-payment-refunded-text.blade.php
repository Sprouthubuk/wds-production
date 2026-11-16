{!! $settings['tradingName'] !!}: order {!! $order->reference !!}

Your payment of {!! $amount !!} has been refunded.

{!! $explanation !!}

{!! $timescale !!}

No tickets were issued for this order.

See the competitions: {!! $competitionsUrl !!}
@include('mail.partials.footer-text')
