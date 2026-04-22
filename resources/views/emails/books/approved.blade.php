@extends('emails.layout')
@section('title', 'Your Book Has Been Approved')

@section('content')
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#27ae60;text-transform:uppercase;letter-spacing:1px;">Book Approved ✓</p>
  <h1 style="margin:0 0 16px;font-size:24px;font-weight:700;color:#160c08;">Congratulations!</h1>
  <p style="margin:0 0 24px;font-size:15px;color:#4a3728;line-height:1.7;">
    Your book has been reviewed and approved by our team. It is now live on the SBA Reads platform and available to readers.
  </p>

  {{-- Book card --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;border:1px solid #e8ddd6;border-radius:10px;overflow:hidden;">
    <tr>
      <td style="background:#f0faf4;padding:20px 24px;border-bottom:1px solid #e8ddd6;">
        <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Book Title</p>
        <p style="margin:4px 0 0;font-size:18px;font-weight:700;color:#160c08;">{{ $book->title }}</p>
        @if($book->sub_title)
          <p style="margin:2px 0 0;font-size:13px;color:#6b5448;font-style:italic;">{{ $book->sub_title }}</p>
        @endif
      </td>
    </tr>
    <tr>
      <td style="background:#ffffff;padding:16px 24px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="width:50%;vertical-align:top;">
              <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Status</p>
              <span style="display:inline-block;margin-top:4px;background:#d4edda;color:#155724;font-size:12px;font-weight:700;padding:3px 10px;border-radius:20px;">Live on Platform</span>
            </td>
            <td style="width:50%;vertical-align:top;">
              <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Publisher</p>
              <p style="margin:4px 0 0;font-size:14px;font-weight:600;color:#160c08;">{{ $book->publisher ?? 'Self-published' }}</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
    <tr>
      <td style="background:#f5f0eb;border-left:3px solid #D8B99C;border-radius:0 8px 8px 0;padding:14px 18px;">
        <p style="margin:0;font-size:13px;color:#6b5448;line-height:1.6;">
          📢 Share the news with your audience! Your book is now discoverable by thousands of readers on SBA Reads.
        </p>
      </td>
    </tr>
  </table>

  <p style="margin:0;font-size:14px;color:#9e8272;">
    Congratulations again,<br/>
    <strong style="color:#160c08;">The SBA Reads Editorial Team</strong>
  </p>
@endsection
