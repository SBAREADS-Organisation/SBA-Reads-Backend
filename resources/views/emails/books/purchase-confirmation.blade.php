@extends('emails.layout')
@section('title', 'Purchase Confirmed')

@section('content')
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#D8B99C;text-transform:uppercase;letter-spacing:1px;">Purchase Confirmed</p>
  <h1 style="margin:0 0 16px;font-size:24px;font-weight:700;color:#160c08;">Your {{ $bookType === 'audio' ? 'audio book is' : (count($bookTitles) > 1 ? 'books are' : 'book is') }} ready!</h1>
  <p style="margin:0 0 24px;font-size:15px;color:#4a3728;line-height:1.7;">
    Hi {{ ($readerName && $readerName !== 'NO NAME') ? $readerName : 'there' }}, your payment was successful. {{ $bookType === 'audio' ? 'Your audio book has' : (count($bookTitles) > 1 ? 'Your books have' : 'Your book has') }} been added to your library and {{ count($bookTitles) > 1 ? 'are' : 'is' }} ready to enjoy.
  </p>

  {{-- Book(s) card --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;border:1px solid #e8ddd6;border-radius:10px;overflow:hidden;">
    <tr>
      <td style="background:#f5f0eb;padding:18px 24px;border-bottom:1px solid #e8ddd6;">
        <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">{{ count($bookTitles) > 1 ? 'Books Purchased' : 'Book Purchased' }}</p>
        @foreach($bookTitles as $title)
          <p style="margin:4px 0 0;font-size:{{ count($bookTitles) > 1 ? '15px' : '18px' }};font-weight:700;color:#160c08;">{{ $title }}</p>
        @endforeach
      </td>
    </tr>
    <tr>
      <td style="background:#ffffff;padding:16px 24px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="width:50%;vertical-align:top;">
              <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Amount Paid</p>
              <p style="margin:4px 0 0;font-size:16px;font-weight:700;color:#160c08;">{{ $amount }}</p>
            </td>
            <td style="width:50%;vertical-align:top;">
              <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Status</p>
              <span style="display:inline-block;margin-top:4px;background:#e8f5e9;color:#2e7d32;font-size:12px;font-weight:700;padding:3px 10px;border-radius:20px;">In Your Library</span>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  {{-- How to access --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
    <tr>
      <td style="background:#f5f0eb;border-radius:10px;padding:20px 24px;">
        <p style="margin:0 0 12px;font-size:13px;font-weight:700;color:#160c08;text-transform:uppercase;letter-spacing:1px;">How to access your {{ $bookType === 'audio' ? 'audio book' : (count($bookTitles) > 1 ? 'books' : 'book') }}</p>
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td width="28" style="vertical-align:top;font-size:14px;color:#D8B99C;font-weight:700;">1.</td>
            <td style="padding-bottom:10px;font-size:13px;color:#4a3728;line-height:1.6;">Open the SBA Reads app on your device.</td>
          </tr>
          <tr>
            <td width="28" style="vertical-align:top;font-size:14px;color:#D8B99C;font-weight:700;">2.</td>
            <td style="padding-bottom:10px;font-size:13px;color:#4a3728;line-height:1.6;">Go to your <strong>Library</strong> tab to find {{ count($bookTitles) > 1 ? 'your new books' : 'your new book' }}.</td>
          </tr>
          <tr>
            <td width="28" style="vertical-align:top;font-size:14px;color:#D8B99C;font-weight:700;">3.</td>
            <td style="font-size:13px;color:#4a3728;line-height:1.6;">{{ $bookType === 'audio' ? 'Tap play and start listening!' : 'Tap to start reading!' }}</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <p style="margin:0;font-size:14px;color:#9e8272;">
    Happy reading,<br/>
    <strong style="color:#160c08;">The SBA Reads Team</strong>
  </p>
@endsection
