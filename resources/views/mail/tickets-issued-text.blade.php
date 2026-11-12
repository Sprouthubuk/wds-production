{!! $settings['tradingName'] !!}: order {!! $order->reference !!}

You're in, {!! strtok($order->name, ' ') !!}.
@foreach ($order->lines as $line)

{!! $line->competition->title !!}
{!! $line->qty !!} {!! $line->qty === 1 ? 'ticket' : 'tickets' !!} · Drawn {!! $line->competition->draw_at->timezone('Europe/London')->format('l j F, g:ia') !!}, live on {!! \App\Http\Resources\SettingsPayload::drawnLiveOn($settings) !!}
Your numbers: {!! $line->tickets->sortBy('number')->map(fn ($t) => '#'.$t->display($line->competition->total_tickets))->implode(', ') !!}
@endforeach

Total paid: £{!! number_format($order->total_pence / 100, 2) !!}

View your tickets: {!! $url !!}
Results and draw certificates: {!! rtrim(config('raffle.frontend_url'), '/') !!}/live-draws

@include('mail.partials.footer-text')
