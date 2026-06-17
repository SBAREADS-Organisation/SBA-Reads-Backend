@extends('emails.layout')
@section('title', "You've been invited to SBA Reads Admin")

@section('content')
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#D8B99C;text-transform:uppercase;letter-spacing:1px;">
    Admin Invitation
  </p>
  <h1 style="margin:0 0 16px;font-size:26px;font-weight:700;color:#160c08;line-height:1.3;">
    Hi {{ $name }}, you've been invited!
  </h1>
  <p style="margin:0 0 28px;font-size:15px;color:#4a3728;line-height:1.7;">
    You have been invited to join the SBA Reads admin team as a <strong>{{ ucfirst($role) }}</strong>.
    Click the button below to set your password and activate your account.
    This invite link expires in <strong>72 hours</strong>.
  </p>

  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
    <tr>
      <td align="center">
        <a href="{{ $inviteUrl }}"
           style="display:inline-block;background:#160c08;color:#D8B99C;text-decoration:none;font-size:14px;font-weight:700;padding:14px 36px;border-radius:8px;letter-spacing:0.5px;">
          Accept Invite & Set Password →
        </a>
      </td>
    </tr>
  </table>

  <p style="margin:0 0 12px;font-size:13px;color:#9e8272;">
    If the button doesn't work, copy and paste this link into your browser:
  </p>
  <p style="margin:0 0 28px;font-size:12px;color:#9e8272;word-break:break-all;">
    {{ $inviteUrl }}
  </p>

  <p style="margin:0;font-size:14px;color:#9e8272;">
    If you weren't expecting this invitation, you can safely ignore this email.<br/><br/>
    Best regards,<br/>
    <strong style="color:#160c08;">The SBA Reads Team</strong>
  </p>
@endsection
