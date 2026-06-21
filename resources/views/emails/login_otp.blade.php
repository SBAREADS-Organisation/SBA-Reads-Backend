@extends('emails.layout')
@section('title', 'Sign-in Verification Code')

@section('content')
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#D8B99C;text-transform:uppercase;letter-spacing:1px;">Sign-in Verification</p>
  <h1 style="margin:0 0 16px;font-size:24px;font-weight:700;color:#160c08;">Verify Your Sign-in</h1>
  <p style="margin:0 0 24px;font-size:15px;color:#4a3728;line-height:1.7;">
    Hi {{ $name }}, a sign-in attempt was made to your SBA Reads account. Use the code below to complete your login.
  </p>

  {{-- OTP Box --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td align="center" style="background:#f5f0eb;border:2px dashed #D8B99C;border-radius:12px;padding:28px;">
        <p style="margin:0 0 6px;font-size:12px;color:#9e8272;text-transform:uppercase;letter-spacing:2px;">Your Verification Code</p>
        <span style="font-size:32px;font-weight:700;color:#160c08;letter-spacing:8px;">{{ $otp }}</span>
        <p style="margin:10px 0 0;font-size:12px;color:#9e8272;">Expires in <strong style="color:#c0392b;">10 minutes</strong></p>
      </td>
    </tr>
  </table>

  <p style="margin:0 0 24px;font-size:14px;color:#6b5448;line-height:1.7;">
    Enter this code in the app to complete your sign-in. Do not share this code with anyone.
  </p>

  {{-- Warning --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
      <td style="background:#fff5f5;border:1px solid #f5c6c6;border-radius:10px;padding:16px 20px;">
        <p style="margin:0 0 4px;font-size:13px;font-weight:700;color:#c0392b;">Not you?</p>
        <p style="margin:0;font-size:13px;color:#7c2d12;line-height:1.6;">
          If you did not attempt to sign in, reset your password immediately to protect your account.
        </p>
      </td>
    </tr>
  </table>

  <p style="margin:28px 0 0;font-size:14px;color:#9e8272;">
    Stay secure,<br/>
    <strong style="color:#160c08;">The SBA Reads Team</strong>
  </p>
@endsection
