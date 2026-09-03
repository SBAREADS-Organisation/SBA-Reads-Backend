@extends('emails.layout')
@section('title', 'Identity Verification Unsuccessful')

@section('content')
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#e67e22;text-transform:uppercase;letter-spacing:1px;">Verification Update</p>
  <h1 style="margin:0 0 16px;font-size:24px;font-weight:700;color:#160c08;">Verification Unsuccessful</h1>
  <p style="margin:0 0 24px;font-size:15px;color:#4a3728;line-height:1.7;">
    Hi {{ ($user->first_name && strtoupper(trim($user->first_name)) !== 'NO NAME') ? $user->first_name : 'there' }}, we were unable to complete your identity verification on SBA Reads. This is often due to one of the following reasons:
  </p>

  {{-- Reasons --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="background:#fff8f0;border:1px solid #ffe0b2;border-radius:10px;padding:20px 24px;">
        <p style="margin:0 0 12px;font-size:12px;font-weight:700;color:#e67e22;text-transform:uppercase;letter-spacing:1px;">Common Reasons</p>
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td width="28" style="vertical-align:top;font-size:14px;color:#D8B99C;font-weight:700;">—</td>
            <td style="padding-bottom:8px;font-size:13px;color:#4a3728;line-height:1.6;">The document uploaded was unclear, expired, or not accepted</td>
          </tr>
          <tr>
            <td width="28" style="vertical-align:top;font-size:14px;color:#D8B99C;font-weight:700;">—</td>
            <td style="padding-bottom:8px;font-size:13px;color:#4a3728;line-height:1.6;">The information submitted did not match your ID document</td>
          </tr>
          <tr>
            <td width="28" style="vertical-align:top;font-size:14px;color:#D8B99C;font-weight:700;">—</td>
            <td style="font-size:13px;color:#4a3728;line-height:1.6;">Your verification session was not completed within the required timeframe</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  {{-- What to do --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;border:1px solid #e8ddd6;border-radius:10px;overflow:hidden;">
    <tr>
      <td style="background:#f5f0eb;padding:14px 24px;border-bottom:1px solid #e8ddd6;">
        <p style="margin:0;font-size:13px;font-weight:700;color:#160c08;text-transform:uppercase;letter-spacing:1px;">You can reapply at any time</p>
      </td>
    </tr>
    <tr>
      <td style="background:#ffffff;padding:20px 24px;">
        <p style="margin:0 0 14px;font-size:14px;color:#4a3728;line-height:1.7;">
          Open the SBA Reads app, go to <strong>Profile → Author Verification</strong>, and start the process again. A fresh verification link will be generated for you. When reapplying, make sure to:
        </p>
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td width="28" style="vertical-align:top;font-size:14px;color:#D8B99C;font-weight:700;">—</td>
            <td style="padding-bottom:8px;font-size:13px;color:#4a3728;line-height:1.6;">Use a clear, well-lit photo of a valid government-issued ID</td>
          </tr>
          <tr>
            <td width="28" style="vertical-align:top;font-size:14px;color:#D8B99C;font-weight:700;">—</td>
            <td style="padding-bottom:8px;font-size:13px;color:#4a3728;line-height:1.6;">Ensure all details match exactly what's on your document</td>
          </tr>
          <tr>
            <td width="28" style="vertical-align:top;font-size:14px;color:#D8B99C;font-weight:700;">—</td>
            <td style="font-size:13px;color:#4a3728;line-height:1.6;">Complete the process in one session without closing the app</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  {{-- CTA --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
    <tr>
      <td align="center">
        <a href="{{ config('app.website_url', config('app.url')) }}" style="display:inline-block;background:#160c08;color:#D8B99C;text-decoration:none;font-size:14px;font-weight:700;padding:14px 36px;border-radius:8px;letter-spacing:0.5px;">
          Open SBA Reads →
        </a>
      </td>
    </tr>
  </table>

  <p style="margin:0;font-size:14px;color:#4a3728;line-height:1.7;">
    If you believe this is a mistake or need help, contact us at <a href="mailto:support@sbareads.com" style="color:#4E342E;font-weight:600;">support@sbareads.com</a> and we'll assist you.
  </p>

  <p style="margin:24px 0 0;font-size:14px;color:#9e8272;">
    Thank you for your patience,<br/>
    <strong style="color:#160c08;">The SBA Reads Team</strong>
  </p>
@endsection
