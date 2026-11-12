{!! $settings['tradingName'] !!}: you've won

You've won, {!! $first !!}.

{!! $winner->competition->title !!}
Winning ticket: #{!! $ticket !!}

Your ticket was drawn as the winner of {!! $winner->prize ?: $winner->competition->title !!}. Here's what happens next:
1. We'll call and email you to arrange the handover.
2. Upload photo ID in your account so we can confirm you're 18 or over. The prize is handed over once it's approved.
3. Collect your prize, or we'll arrange delivery where that's offered.

Your account: {!! $accountUrl !!}
Check the result: {!! $verifyUrl !!}

We will never ask you to pay anything to receive your prize.

@include('mail.partials.footer-text')
