@extends('emails.layout')
@section('title', 'Book Submission Update')

@section('content')
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#e67e22;text-transform:uppercase;letter-spacing:1px;">Submission Update</p>
  <h1 style="margin:0 0 16px;font-size:24px;font-weight:700;color:#160c08;">Book Requires Revision</h1>
  <p style="margin:0 0 24px;font-size:15px;color:#4a3728;line-height:1.7;">
    Hi {{ $user->name }}, thank you for submitting your book. After careful review, our editorial team has determined that it needs some revisions before it can be published.
  </p>

  {{-- Book info --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;border:1px solid #e8ddd6;border-radius:10px;overflow:hidden;">
    <tr>
      <td style="background:#f5f0eb;padding:18px 24px;border-bottom:1px solid #e8ddd6;">
        <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Book Title</p>
        <p style="margin:4px 0 0;font-size:18px;font-weight:700;color:#160c08;">{{ $book->title }}</p>
      </td>
    </tr>
    <tr>
      <td style="background:#ffffff;padding:18px 24px;">
        <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Status</p>
        <span style="display:inline-block;margin-top:4px;background:#fff3cd;color:#856404;font-size:12px;font-weight:700;padding:3px 10px;border-radius:20px;">Needs Revision</span>
      </td>
    </tr>
  </table>

  {{-- Reason --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="background:#fff8f0;border:1px solid #ffe0b2;border-radius:10px;padding:20px 24px;">
        <p style="margin:0 0 8px;font-size:12px;font-weight:700;color:#e67e22;text-transform:uppercase;letter-spacing:1px;">Editorial Feedback</p>
        <p style="margin:0;font-size:14px;color:#4a3728;line-height:1.7;">{{ $reason }}</p>
      </td>
    </tr>
  </table>

  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
    <tr>
      <td style="background:#f5f0eb;border-left:3px solid #D8B99C;border-radius:0 8px 8px 0;padding:14px 18px;">
        <p style="margin:0;font-size:13px;color:#6b5448;line-height:1.6;">
          💡 Address the feedback above, update your submission, and resubmit. We look forward to publishing your work!
        </p>
      </td>
    </tr>
  </table>

  <p style="margin:0;font-size:14px;color:#9e8272;">
    Keep writing,<br/>
    <strong style="color:#160c08;">The SBA Reads Editorial Team</strong>
  </p>
@endsection
