{!! $settings['tradingName'] !!}: order {!! $order->reference !!}

{!! $headline !!}

@foreach ($paragraphs as $p)
{!! $p !!}

@endforeach
@include('mail.partials.footer-text')
