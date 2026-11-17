{!! $settings['tradingName'] !!}

{!! $headline !!}

@foreach ($paragraphs as $p)
{!! $p !!}

@endforeach
Unsubscribe: {!! $unsubscribeUrl !!}
@include('mail.partials.footer-text')
