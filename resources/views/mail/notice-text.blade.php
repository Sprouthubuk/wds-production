{!! $settings['tradingName'] !!}

{!! $headline !!}

@foreach ($paragraphs as $p)
{!! $p !!}

@endforeach
@include('mail.partials.footer-text')
