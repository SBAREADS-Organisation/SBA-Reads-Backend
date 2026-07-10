@extends('emails.layout')
@section('title', 'Update on Your Identity Verification')

@section('content')
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#D8B99C;text-transform:uppercase;letter-spacing:1px;">Verification Update</p>
  <h1 style="margin:0 0 16px;font-size:24px;font-weight:700;color:#160c08;">Your submission is still under review</h1>
  <p style="margin:0 0 24px;font-size:15px;color:#4a3728;line-height:1.7;">
    Hi {{ ($firstName && strtoupper(trim($firstName)) !== 'NO NAME') ? $firstName : 'there' }}, thank you for your patience. We wanted to let you know that your identity verification submission has been received and is currently being reviewed by our team.
  </p>

  {{-- Status card --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;border:1px solid #e8ddd6;border-radius:10px;overflow:hidden;">
    <tr>
      <td style="background:#f5f0eb;padding:18px 24px;border-bottom:1px solid #e8ddd6;">
        <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Verification Status</p>
        <div style="margin-top:6px;display:flex;align-items:center;gap:8px;">
          <span style="display:inline-block;background:#fff3cd;color:#856404;font-size:12px;font-weight:700;padding:3px 12px;border-radius:20px;">In Review</span>
        </div>
      </td>
    </tr>
    <tr>
      <td style="background:#ffffff;padding:16px 24px;">
        @if($customMessage)
          <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Message from our team</p>
          <p style="margin:8px 0 0;font-size:14px;color:#4a3728;line-height:1.7;">{{ $customMessage }}</p>
        @else
          <p style="margin:0;font-size:14px;color:#4a3728;line-height:1.7;">
            We are working through your submission carefully. Verification typically takes 1–3 business days. You will receive a separate email as soon as a decision has been made.
          </p>
        @endif
      </td>
    </tr>
  </table>

  {{-- What to check --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
    <tr>
      <td style="background:#f5f0eb;border-radius:10px;padding:20px 24px;">
        <p style="margin:0 0 14px;font-size:13px;font-weight:700;color:#160c08;text-transform:uppercase;letter-spacing:1px;">While you wait</p>
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td width="28" style="vertical-align:top;font-size:14px;color:#D8B99C;font-weight:700;">—</td>
            <td style="padding-bottom:10px;font-size:13px;color:#4a3728;line-height:1.6;">Make sure your submitted ID document is clear and all details are readable.</td>
          </tr>
          <tr>
            <td width="28" style="vertical-align:top;font-size:14px;color:#D8B99C;font-weight:700;">—</td>
            <td style="padding-bottom:10px;font-size:13px;color:#4a3728;line-height:1.6;">Ensure your profile name matches the name on your ID document.</td>
          </tr>
          <tr>
            <td width="28" style="vertical-align:top;font-size:14px;color:#D8B99C;font-weight:700;">—</td>
            <td style="font-size:13px;color:#4a3728;line-height:1.6;">If you need to update your submission, open the SBA Reads app and go to <strong>Profile → Identity Verification</strong>.</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <p style="margin:0 0 20px;font-size:14px;color:#4a3728;line-height:1.7;">
    If you have questions or believe there has been an error, please reach out to us at <a href="mailto:support@sbareads.com" style="color:#4E342E;font-weight:600;">support@sbareads.com</a> and we will be happy to help.
  </p>

  <p style="margin:0;font-size:14px;color:#9e8272;">
    Thank you for your understanding,<br/>
    <strong style="color:#160c08;">The SBA Reads Team</strong>
  </p>
@endsection
