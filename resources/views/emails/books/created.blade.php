@extends('emails.layout')
@section('title', 'Book Submitted Successfully')

@section('content')
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#D8B99C;text-transform:uppercase;letter-spacing:1px;">Submission Received</p>
  <h1 style="margin:0 0 16px;font-size:24px;font-weight:700;color:#160c08;">Your Book Is Under Review</h1>
  <p style="margin:0 0 24px;font-size:15px;color:#4a3728;line-height:1.7;">
    Hi {{ $recipientName }}, thank you for submitting your book to SBA Reads. Our editorial team will review it shortly and notify you of the decision.
  </p>

  {{-- Book card --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;border:1px solid #e8ddd6;border-radius:10px;overflow:hidden;">
    <tr>
      <td style="background:#f5f0eb;padding:18px 24px;border-bottom:1px solid #e8ddd6;">
        <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Book Title</p>
        <p style="margin:4px 0 0;font-size:18px;font-weight:700;color:#160c08;">{{ $book->title }}</p>
      </td>
    </tr>
    <tr>
      <td style="background:#ffffff;padding:16px 24px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="width:50%;vertical-align:top;">
              <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Status</p>
              <span style="display:inline-block;margin-top:4px;background:#fff3cd;color:#856404;font-size:12px;font-weight:700;padding:3px 10px;border-radius:20px;">Pending Review</span>
            </td>
            <td style="width:50%;vertical-align:top;">
              <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Submitted</p>
              <p style="margin:4px 0 0;font-size:14px;font-weight:600;color:#160c08;">{{ $book->created_at->format('M d, Y') }}</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  {{-- What happens next --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
    <tr>
      <td style="background:#f5f0eb;border-radius:10px;padding:20px 24px;">
        <p style="margin:0 0 14px;font-size:13px;font-weight:700;color:#160c08;text-transform:uppercase;letter-spacing:1px;">What happens next</p>
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td width="28" style="vertical-align:top;"><span style="font-size:15px;">🔍</span></td>
            <td style="padding-bottom:10px;font-size:13px;color:#4a3728;line-height:1.6;">Our team reviews your book for quality and content guidelines.</td>
          </tr>
          <tr>
            <td width="28" style="vertical-align:top;"><span style="font-size:15px;">📧</span></td>
            <td style="padding-bottom:10px;font-size:13px;color:#4a3728;line-height:1.6;">You'll receive an email once a decision has been made.</td>
          </tr>
          <tr>
            <td width="28" style="vertical-align:top;"><span style="font-size:15px;">🚀</span></td>
            <td style="font-size:13px;color:#4a3728;line-height:1.6;">If approved, your book goes live immediately to thousands of readers.</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <p style="margin:0;font-size:14px;color:#9e8272;">
    Thank you for choosing SBA Reads,<br/>
    <strong style="color:#160c08;">The SBA Reads Editorial Team</strong>
  </p>
@endsection
