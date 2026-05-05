@extends('emails.layout')
@section('title', 'New Login Detected')

@section('content')

  {{-- Icon + heading --}}
  <div style="text-align:center;margin-bottom:28px;">
    <div style="display:inline-block;background:#fff4ec;border-radius:50%;width:64px;height:64px;line-height:64px;font-size:28px;margin-bottom:16px;">🔐</div>
    <p style="margin:0 0 6px;font-size:11px;font-weight:700;color:#e67e22;text-transform:uppercase;letter-spacing:2px;">Security Alert</p>
    <h1 style="margin:0;font-size:26px;font-weight:800;color:#160c08;letter-spacing:-0.5px;">New Login Detected</h1>
  </div>

  <p style="margin:0 0 24px;font-size:15px;color:#4a3728;line-height:1.8;text-align:center;">
    Hi <strong>{{ $name }}</strong>, we noticed a new sign-in to your<br/>SBA Reads account. Here are the details:
  </p>

  {{-- Login details card --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;border-radius:12px;overflow:hidden;border:1px solid #ede4dc;">

    <tr>
      <td style="background:#faf6f2;padding:14px 20px;border-bottom:1px solid #ede4dc;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td width="20" style="vertical-align:middle;padding-right:12px;font-size:16px;">🔑</td>
            <td style="font-size:12px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;font-weight:600;width:100px;vertical-align:middle;">Method</td>
            <td style="font-size:14px;font-weight:700;color:#160c08;vertical-align:middle;">{{ $provider }}</td>
          </tr>
        </table>
      </td>
    </tr>

    <tr>
      <td style="background:#ffffff;padding:14px 20px;border-bottom:1px solid #ede4dc;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td width="20" style="vertical-align:middle;padding-right:12px;font-size:16px;">🌐</td>
            <td style="font-size:12px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;font-weight:600;width:100px;vertical-align:middle;">IP Address</td>
            <td style="font-size:14px;font-weight:700;color:#160c08;font-family:monospace;vertical-align:middle;">{{ $ipAddress }}</td>
          </tr>
        </table>
      </td>
    </tr>

    <tr>
      <td style="background:#faf6f2;padding:14px 20px;border-bottom:1px solid #ede4dc;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td width="20" style="vertical-align:middle;padding-right:12px;font-size:16px;">📍</td>
            <td style="font-size:12px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;font-weight:600;width:100px;vertical-align:middle;">Location</td>
            <td style="font-size:14px;font-weight:700;color:#160c08;vertical-align:middle;">{{ $location }}</td>
          </tr>
        </table>
      </td>
    </tr>

    <tr>
      <td style="background:#ffffff;padding:14px 20px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td width="20" style="vertical-align:middle;padding-right:12px;font-size:16px;">🕐</td>
            <td style="font-size:12px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;font-weight:600;width:100px;vertical-align:middle;">Time</td>
            <td style="font-size:14px;font-weight:700;color:#160c08;vertical-align:middle;">{{ $time }}</td>
          </tr>
        </table>
      </td>
    </tr>

  </table>

  {{-- Warning banner --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
    <tr>
      <td style="background:#fff8f0;border:1px solid #f5c9a0;border-radius:10px;padding:16px 20px;">
        <p style="margin:0 0 4px;font-size:13px;font-weight:700;color:#b45309;">Not you?</p>
        <p style="margin:0;font-size:13px;color:#7c4b1e;line-height:1.6;">
          If you didn't sign in, reset your password immediately to secure your account.
        </p>
      </td>
    </tr>
  </table>

  {{-- CTA button --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
    <tr>
      <td align="center">
        <a href="{{ config('app.url') }}/forgot-password"
           style="display:inline-block;background:#e67e22;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;padding:14px 32px;border-radius:8px;letter-spacing:0.5px;">
          Reset My Password
        </a>
      </td>
    </tr>
  </table>

  <p style="margin:0;font-size:13px;color:#9e8272;text-align:center;line-height:1.8;">
    If this was you, no action is needed. Stay safe!<br/>
    <strong style="color:#160c08;">— The SBA Reads Team</strong>
  </p>

@endsection
