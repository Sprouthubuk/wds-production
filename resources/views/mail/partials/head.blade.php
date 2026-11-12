<!doctype html>
<html lang="en-GB">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark light"><title>{{ $title ?? $settings['tradingName'] }}</title></head>
<body style="margin:0;padding:0;background:#000;color:#fff;font-family:Helvetica,Arial,sans-serif;-webkit-text-size-adjust:100%">
  @if (! empty($preheader))<div style="display:none;max-height:0;overflow:hidden;opacity:0">{{ $preheader }}</div>@endif
  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#000"><tr><td align="center" style="padding:32px 16px">
    <table width="560" cellpadding="0" cellspacing="0" role="presentation" style="max-width:560px;width:100%">
      <tr><td style="padding-bottom:24px;border-bottom:1px solid #222"><a href="{{ rtrim(config('raffle.frontend_url'), '/') }}" style="color:#fff;text-decoration:none;font-size:15px;font-weight:700;letter-spacing:.22em;text-transform:uppercase">{{ $settings['tradingName'] }}</a></td></tr>
