{!! $settings['tradingName'] !!}: free postal entry

@if ($entry->status === 'allocated')
You're in, {!! strtok($entry->name, ' ') !!}.

{!! $competition->title !!}
Your ticket number: #{!! $ticket !!}
Drawn {!! $competition->draw_at->timezone('Europe/London')->format('l j F, g:ia') !!}. Same draw, same chance of winning as online entries.
@else
We couldn't accept your entry.

{!! $competition->title !!}
Your postal entry received on {!! $entry->receivedMoment()->timezone('Europe/London')->format('j F Y') !!} was not entered into the draw.
Reason: {!! $entry->rejection_reason !!}.
@endif
@if ($deadline)

{!! $deadline !!}
@endif

View the competition: {!! $competitionUrl !!}
How to enter by post: {!! $postalUrl !!}

@include('mail.partials.footer-text')
