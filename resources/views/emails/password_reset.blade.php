@extends('emails.layout')
@section('title', 'Password Reset Successful')

@section('content')
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#27ae60;text-transform:uppercase;letter-spacing:1px;">Success</p>
  <h1 style="margin:0 0 16px;font-size:24px;font-weight:700;color:#160c08;">Password Reset Successful</h1>
  <p style="margin:0 0 24px;font-size:15px;color:#4a3728;line-height:1.7;">
    Hi {{ $name }}, your SBA Reads password has been successfully reset. You can now log in with your new password.
  </p>

  {{-- Confirmation box --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="background:#f0faf4;border:1px solid #a8d5b5;border-radius:10px;padding:20px 24px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td width="36" style="vertical-align:top;padding-top:2px;">
              <span style="font-size:22px;">✅</span>
            </td>
            <td>
              <p style="margin:0;font-size:14px;font-weight:600;color:#1e7e34;">Password updated</p>
              <p style="margin:4px 0 0;font-size:13px;color:#4a3728;">{{ $body }}</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  {{-- Security warning --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="background:#fff5f5;border-left:3px solid #e74c3c;border-radius:0 8px 8px 0;padding:14px 18px;">
        <p style="margin:0;font-size:13px;color:#7a3030;line-height:1.6;">
          ⚠️ If you did not make this change, contact our support team immediately — your account may be compromised.
        </p>
      </td>
    </tr>
  </table>

  <p style="margin:0;font-size:14px;color:#9e8272;">
    Stay safe,<br/>
    <strong style="color:#160c08;">The SBA Reads Team</strong>
  </p>
@endsection
