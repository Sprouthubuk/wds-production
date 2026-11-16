<!doctype html>
<html lang="en-GB">
<head><meta charset="utf-8"><title>Website enquiry</title></head>
<body style="margin:0;padding:24px;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#111;background:#fff">
  <table cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse;margin-bottom:20px">
    @foreach ($details as $label => $value)
      <tr><td style="padding:4px 16px 4px 0;color:#666;vertical-align:top;white-space:nowrap">{{ $label }}</td><td style="padding:4px 0;overflow-wrap:anywhere">{{ $value }}</td></tr>
    @endforeach
  </table>
  <div style="border-top:1px solid #ddd;padding-top:16px;white-space:pre-wrap;overflow-wrap:anywhere">{{ $body }}</div>
  <p style="margin-top:24px;font-size:12px;color:#888">Sent from the website contact form. Reply to this email to answer the customer.</p>
</body>
</html>
