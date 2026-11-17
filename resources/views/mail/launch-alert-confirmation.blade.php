@include('mail.partials.head', ['title' => $headline, 'preheader' => $paragraphs[0]])
      <tr><td style="font-size:32px;font-weight:700;padding:24px 0 16px">{{ $headline }}</td></tr>
      <tr><td style="border-top:1px solid #222;padding:16px 0 24px;font-size:16px;line-height:1.6">
        @foreach ($paragraphs as $p)
          {{ $p }}@if (! $loop->last)<br><br>@endif
        @endforeach
      </td></tr>
      <tr><td style="color:#9a9a9a;font-size:13px;padding:0 0 16px">Changed your mind? <a href="{{ $unsubscribeUrl }}" style="color:#9a9a9a">Unsubscribe</a>.</td></tr>
@include('mail.partials.foot')
