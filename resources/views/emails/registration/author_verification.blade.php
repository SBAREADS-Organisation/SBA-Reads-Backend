@extends('emails.layout')
@section('title', 'Verify Your Author Account')

@section('content')
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#D8B99C;text-transform:uppercase;letter-spacing:1px;">Author Registration</p>
  <h1 style="margin:0 0 16px;font-size:24px;font-weight:700;color:#160c08;">Verify Your Email Address</h1>
  <p style="margin:0 0 24px;font-size:15px;color:#4a3728;line-height:1.7;">
    Welcome! To complete your author registration on SBA Reads, please use the verification code below.
  </p>

  {{-- OTP Box --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td align="center" style="background:#f5f0eb;border:2px dashed #D8B99C;border-radius:12px;padding:28px;">
        <p style="margin:0 0 6px;font-size:12px;color:#9e8272;text-transform:uppercase;letter-spacing:2px;">Your Verification Code</p>
        <span style="font-size:48px;font-weight:700;color:#160c08;letter-spacing:14px;">{{ $token }}</span>
        <p style="margin:10px 0 0;font-size:12px;color:#9e8272;">Expires in <strong style="color:#c0392b;">10 minutes</strong></p>
      </td>
    </tr>
  </table>

  <p style="margin:0 0 24px;font-size:14px;color:#6b5448;line-height:1.7;">
    Enter this code in the app to complete your email verification and activate your author account.
  </p>

  {{-- Steps --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="background:#f5f0eb;border-radius:10px;padding:20px 24px;">
        <p style="margin:0 0 12px;font-size:13px;font-weight:600;color:#160c08;">What happens next?</p>
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="padding:4px 0;">
              <span style="font-size:13px;color:#4a3728;">✅ &nbsp;Enter the code to verify your email</span>
            </td>
          </tr>
          <tr>
            <td style="padding:4px 0;">
              <span style="font-size:13px;color:#4a3728;">📝 &nbsp;Complete your author profile</span>
            </td>
          </tr>
          <tr>
            <td style="padding:4px 0;">
              <span style="font-size:13px;color:#4a3728;">📚 &nbsp;Start uploading your books</span>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  {{-- Warning --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
      <td style="background:#fff8f0;border-left:3px solid #D8B99C;border-radius:0 8px 8px 0;padding:14px 18px;">
        <p style="margin:0;font-size:13px;color:#7a5c46;line-height:1.6;">
          🔒 If you did not create an author account on SBA Reads, you can safely ignore this email.
        </p>
      </td>
    </tr>
  </table>

  <p style="margin:28px 0 0;font-size:14px;color:#9e8272;">
    Welcome aboard,<br/>
    <strong style="color:#160c08;">The SBA Reads Team</strong>
  </p>
@endsection
