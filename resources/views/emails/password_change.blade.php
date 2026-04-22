@extends('emails.layout')
@section('title', 'Password Changed')

@section('content')
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#D8B99C;text-transform:uppercase;letter-spacing:1px;">Account Security</p>
  <h1 style="margin:0 0 16px;font-size:24px;font-weight:700;color:#160c08;">Your Password Was Changed</h1>
  <p style="margin:0 0 24px;font-size:15px;color:#4a3728;line-height:1.7;">
    Hi {{ $name }}, this is a confirmation that your SBA Reads account password was recently changed.
  </p>

  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="background:#f5f0eb;border-radius:10px;padding:20px 24px;">
        <p style="margin:0 0 4px;font-size:12px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Details</p>
        <p style="margin:0;font-size:14px;color:#4a3728;line-height:1.7;">{{ $body }}</p>
      </td>
    </tr>
  </table>

  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="background:#fff5f5;border-left:3px solid #e74c3c;border-radius:0 8px 8px 0;padding:14px 18px;">
        <p style="margin:0;font-size:13px;color:#7a3030;line-height:1.6;">
          ⚠️ If you did not make this change, please reset your password immediately and contact our support team.
        </p>
      </td>
    </tr>
  </table>

  <p style="margin:0;font-size:14px;color:#9e8272;">
    Regards,<br/>
    <strong style="color:#160c08;">The SBA Reads Team</strong>
  </p>
@endsection
