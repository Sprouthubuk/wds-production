@if ($test)
TEST: this is a preview of the launch email. Nobody else received it.

@endif
{!! $settings['tradingName'] !!}: now live

{!! $competition->title !!} is open for entries.

{!! $price !!} · only {!! number_format((int) $competition->total_tickets) !!} {!! $competition->isFree() ? 'entries' : 'tickets' !!} · {!! $odds !!} odds
@if ($closes)
Entries close: {!! $closes !!} (UK time).
@endif
@if ($draw)
Live draw: {!! $draw !!} (UK time).
@endif
@if ($postal)
{!! $postal !!}
@endif

Enter now: {!! $competitionUrl !!}

You're getting this because you asked us to email you when WDS Competitions launch. This is the only launch email for this competition.
Unsubscribe: {!! $unsubscribeUrl !!}
18+, UK only. Free postal entry on every paid competition.
@include('mail.partials.footer-text')
