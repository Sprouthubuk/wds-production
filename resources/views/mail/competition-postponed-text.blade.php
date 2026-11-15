{!! $settings['tradingName'] !!}: important

{!! $competition->title !!} has been postponed.

Entries now close: {!! $closes !!} (UK time)@if ($from), instead of {!! $from !!}@endif.
@if ($draw)
Live draw: {!! $draw !!} (UK time).
@endif
Reason: {!! $reason !!}

Your entries stay in the draw exactly as they are: there's nothing you need to do. The change is recorded on the draw's public audit record.

View the competition: {!! $competitionUrl !!}
@include('mail.partials.footer-text')
