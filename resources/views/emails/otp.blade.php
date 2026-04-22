@extends('emails.layout')
@section('title', 'Password Reset OTP')

@section('content')
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#D8B99C;text-transform:uppercase;letter-spacing:1px;">Security Code</p>
  <h1 style="margin:0 0 16px;font-size:24px;font-weight:700;color:#160c08;">Password Reset Request</h1>
  <p style="margin:0 0 24px;font-size:15px;color:#4a3728;line-height:1.7;">
    Hi {{ $name }}, we received a request to reset your SBA Reads password. Use the code below to proceed.
  </p>

  {{-- OTP Box --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td align="center" style="background:#f5f0eb;border:2px dashed #D8B99C;border-radius:12px;padding:28px;">
        <p style="margin:0 0 6px;font-size:12px;color:#9e8272;text-transform:uppercase;letter-spacing:2px;">Your OTP Code</p>
        <span style="font-size:32px;font-weight:700;color:#160c08;letter-spacing:8px;">{{ $otp }}</span>
        <p style="margin:10px 0 0;font-size:12px;color:#9e8272;">Expires in <strong style="color:#c0392b;">10 minutes</strong></p>
      </td>
    </tr>
  </table>

  <p style="margin:0 0 24px;font-size:14px;color:#6b5448;line-height:1.7;">
    Enter this code in the app to continue resetting your password. Do not share this code with anyone.
  </p>

  {{-- Warning --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
      <td style="background:#fff8f0;border-left:3px solid #D8B99C;border-radius:0 8px 8px 0;padding:14px 18px;">
        <p style="margin:0;font-size:13px;color:#7a5c46;line-height:1.6;">
          🔒 If you did not request this, please ignore this email. Your account remains secure.
        </p>
      </td>
    </tr>
  </table>

  <p style="margin:28px 0 0;font-size:14px;color:#9e8272;">
    Warm regards,<br/>
    <strong style="color:#160c08;">The SBA Reads Team</strong>
  </p>
@endsection
