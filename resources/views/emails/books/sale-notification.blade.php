@extends('emails.layout')
@section('title', 'New Book Sale')

@section('content')
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#D8B99C;text-transform:uppercase;letter-spacing:1px;">New Sale</p>
  <h1 style="margin:0 0 16px;font-size:24px;font-weight:700;color:#160c08;">Someone bought your book!</h1>
  <p style="margin:0 0 24px;font-size:15px;color:#4a3728;line-height:1.7;">
    Hi {{ ($authorName && $authorName !== 'NO NAME') ? $authorName : 'there' }}, great news — a reader just purchased your {{ $bookType === 'audio' ? 'audio book' : 'book' }}. Your earnings have been added to your wallet.
  </p>

  {{-- Sale summary card --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;border:1px solid #e8ddd6;border-radius:10px;overflow:hidden;">
    <tr>
      <td style="background:#f5f0eb;padding:18px 24px;border-bottom:1px solid #e8ddd6;">
        <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">{{ $bookType === 'audio' ? 'Audio Book' : 'Book' }} Title</p>
        <p style="margin:4px 0 0;font-size:18px;font-weight:700;color:#160c08;">{{ $bookTitle }}</p>
      </td>
    </tr>
    <tr>
      <td style="background:#ffffff;padding:16px 24px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="width:50%;vertical-align:top;padding-bottom:12px;">
              <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Purchased By</p>
              <p style="margin:4px 0 0;font-size:14px;font-weight:600;color:#160c08;">{{ $buyerName }}</p>
            </td>
            <td style="width:50%;vertical-align:top;padding-bottom:12px;">
              <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Your Earnings</p>
              <p style="margin:4px 0 0;font-size:18px;font-weight:700;color:#2e7d32;">{{ $amount }}</p>
            </td>
          </tr>
          <tr>
            <td colspan="2">
              <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Wallet Status</p>
              <span style="display:inline-block;margin-top:4px;background:#e8f5e9;color:#2e7d32;font-size:12px;font-weight:700;padding:3px 10px;border-radius:20px;">Credited</span>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  {{-- CTA --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
    <tr>
      <td style="background:#f5f0eb;border-radius:10px;padding:20px 24px;">
        <p style="margin:0 0 12px;font-size:13px;font-weight:700;color:#160c08;text-transform:uppercase;letter-spacing:1px;">What you can do next</p>
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td width="28" style="vertical-align:top;font-size:14px;color:#D8B99C;font-weight:700;">—</td>
            <td style="padding-bottom:10px;font-size:13px;color:#4a3728;line-height:1.6;">Open the app to see your updated wallet balance.</td>
          </tr>
          <tr>
            <td width="28" style="vertical-align:top;font-size:14px;color:#D8B99C;font-weight:700;">—</td>
            <td style="font-size:13px;color:#4a3728;line-height:1.6;">Withdraw your earnings to your bank account at any time from the Wallet section.</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <p style="margin:0;font-size:14px;color:#9e8272;">
    Keep writing and inspiring readers,<br/>
    <strong style="color:#160c08;">The SBA Reads Team</strong>
  </p>
@endsection
