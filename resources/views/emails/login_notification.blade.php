@extends('emails.layout')
@section('title', 'New Login Detected')

@section('content')

  {{-- Alert label --}}
  <p style="margin:0 0 10px;font-size:11px;font-weight:700;color:#c0392b;text-transform:uppercase;letter-spacing:2px;text-align:center;">
    Security Alert
  </p>

  {{-- Heading --}}
  <h1 style="margin:0 0 8px;font-size:26px;font-weight:800;color:#160c08;letter-spacing:-0.5px;text-align:center;">
    New Sign-In Detected
  </h1>
  <p style="margin:0 0 32px;font-size:15px;color:#4a3728;line-height:1.8;text-align:center;">
    Hi <strong>{{ $name }}</strong>, a new sign-in to your SBA Reads account was just recorded.
  </p>

  {{-- Details card --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;border-radius:10px;overflow:hidden;border:1px solid #e8ddd6;">

    <tr>
      <td style="background:#faf6f2;padding:14px 20px;border-bottom:1px solid #e8ddd6;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;font-weight:700;width:110px;vertical-align:middle;">Sign-in Method</td>
            <td style="font-size:14px;font-weight:700;color:#160c08;vertical-align:middle;">{{ $provider }}</td>
          </tr>
        </table>
      </td>
    </tr>

    <tr>
      <td style="background:#ffffff;padding:14px 20px;border-bottom:1px solid #e8ddd6;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;font-weight:700;width:110px;vertical-align:middle;">IP Address</td>
            <td style="font-size:14px;font-weight:700;color:#160c08;font-family:monospace;vertical-align:middle;">{{ $ipAddress }}</td>
          </tr>
        </table>
      </td>
    </tr>

    <tr>
      <td style="background:#faf6f2;padding:14px 20px;border-bottom:1px solid #e8ddd6;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;font-weight:700;width:110px;vertical-align:middle;">Location</td>
            <td style="font-size:14px;font-weight:700;color:#160c08;vertical-align:middle;">{{ $location }}</td>
          </tr>
        </table>
      </td>
    </tr>

    <tr>
      <td style="background:#ffffff;padding:14px 20px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;font-weight:700;width:110px;vertical-align:middle;">Time</td>
            <td style="font-size:14px;font-weight:700;color:#160c08;vertical-align:middle;">{{ $time }}</td>
          </tr>
        </table>
      </td>
    </tr>

  </table>

  {{-- Warning --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
    <tr>
      <td style="background:#fff5f5;border:1px solid #f5c6c6;border-radius:10px;padding:16px 20px;">
        <p style="margin:0 0 4px;font-size:13px;font-weight:700;color:#c0392b;">Not you?</p>
        <p style="margin:0;font-size:13px;color:#7c2d12;line-height:1.6;">
          If you did not sign in, reset your password immediately to protect your account.
        </p>
      </td>
    </tr>
  </table>

  {{-- CTA --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
    <tr>
      <td align="center">
        <a href="{{ config('app.url') }}/forgot-password"
           style="display:inline-block;background:#c0392b;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;padding:14px 32px;border-radius:8px;letter-spacing:0.5px;">
          Reset My Password
        </a>
      </td>
    </tr>
  </table>

  <p style="margin:0;font-size:13px;color:#9e8272;text-align:center;line-height:1.8;">
    If this was you, no action is needed.<br/>
    <strong style="color:#160c08;">The SBA Reads Team</strong>
  </p>

@endsection
