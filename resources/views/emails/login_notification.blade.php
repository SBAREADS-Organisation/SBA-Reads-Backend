@extends('emails.layout')
@section('title', 'New Login Detected')

@section('content')
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#e67e22;text-transform:uppercase;letter-spacing:1px;">Security Alert</p>
  <h1 style="margin:0 0 16px;font-size:24px;font-weight:700;color:#160c08;">New Login Detected</h1>
  <p style="margin:0 0 24px;font-size:15px;color:#4a3728;line-height:1.7;">
    Hi {{ $name }}, we noticed a new sign-in to your SBA Reads account. Here are the details:
  </p>

  {{-- Login details --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;border:1px solid #e8ddd6;border-radius:10px;overflow:hidden;">
    <tr>
      <td style="background:#f5f0eb;padding:14px 20px;border-bottom:1px solid #e8ddd6;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="font-size:13px;color:#9e8272;width:120px;">Method</td>
            <td style="font-size:14px;font-weight:600;color:#160c08;">{{ $provider }}</td>
          </tr>
        </table>
      </td>
    </tr>
    <tr>
      <td style="background:#ffffff;padding:14px 20px;border-bottom:1px solid #e8ddd6;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="font-size:13px;color:#9e8272;width:120px;">IP Address</td>
            <td style="font-size:14px;font-weight:600;color:#160c08;font-family:monospace;">{{ $ipAddress }}</td>
          </tr>
        </table>
      </td>
    </tr>
    <tr>
      <td style="background:#f5f0eb;padding:14px 20px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="font-size:13px;color:#9e8272;width:120px;">Time</td>
            <td style="font-size:14px;font-weight:600;color:#160c08;">{{ $time }}</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="background:#fff5f5;border-left:3px solid #e74c3c;border-radius:0 8px 8px 0;padding:14px 18px;">
        <p style="margin:0;font-size:13px;color:#7a3030;line-height:1.6;">
          🔒 If this wasn't you, reset your password immediately. Someone else may have access to your account.
        </p>
      </td>
    </tr>
  </table>

  <p style="margin:0;font-size:14px;color:#9e8272;">
    Stay secure,<br/>
    <strong style="color:#160c08;">The SBA Reads Team</strong>
  </p>
@endsection
