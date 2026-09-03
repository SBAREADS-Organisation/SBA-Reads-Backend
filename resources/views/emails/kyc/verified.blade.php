@extends('emails.layout')
@section('title', 'Identity Verified')

@section('content')
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#27ae60;text-transform:uppercase;letter-spacing:1px;">Identity Verified ✓</p>
  <h1 style="margin:0 0 16px;font-size:24px;font-weight:700;color:#160c08;">You're fully verified!</h1>
  <p style="margin:0 0 24px;font-size:15px;color:#4a3728;line-height:1.7;">
    Hi {{ ($user->first_name && strtoupper(trim($user->first_name)) !== 'NO NAME') ? $user->first_name : 'there' }}, great news — your identity has been successfully verified on SBA Reads. Your author account is now fully active and ready to go.
  </p>

  {{-- Status card --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;border:1px solid #e8ddd6;border-radius:10px;overflow:hidden;">
    <tr>
      <td style="background:#f0faf4;padding:20px 24px;border-bottom:1px solid #e8ddd6;">
        <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Verification Status</p>
        <span style="display:inline-block;margin-top:6px;background:#d4edda;color:#155724;font-size:12px;font-weight:700;padding:3px 12px;border-radius:20px;">Verified</span>
      </td>
    </tr>
    <tr>
      <td style="background:#ffffff;padding:16px 24px;">
        <p style="margin:0;font-size:14px;color:#4a3728;line-height:1.7;">
          You can now publish books, reach readers, and receive payouts directly to your bank account.
        </p>
      </td>
    </tr>
  </table>

  {{-- What's next --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
    <tr>
      <td style="background:#f5f0eb;border-radius:10px;padding:20px 24px;">
        <p style="margin:0 0 14px;font-size:13px;font-weight:700;color:#160c08;text-transform:uppercase;letter-spacing:1px;">What you can do now</p>
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td width="28" style="vertical-align:top;padding-top:1px;"><span style="font-size:14px;color:#D8B99C;font-weight:700;">—</span></td>
            <td style="padding-bottom:12px;">
              <p style="margin:0;font-size:14px;font-weight:600;color:#160c08;">Publish your books</p>
              <p style="margin:2px 0 0;font-size:13px;color:#6b5448;">Upload your manuscripts and cover images for review.</p>
            </td>
          </tr>
          <tr>
            <td width="28" style="vertical-align:top;padding-top:1px;"><span style="font-size:14px;color:#D8B99C;font-weight:700;">—</span></td>
            <td style="padding-bottom:12px;">
              <p style="margin:0;font-size:14px;font-weight:600;color:#160c08;">Set up your payout method</p>
              <p style="margin:2px 0 0;font-size:13px;color:#6b5448;">Go to <strong>Wallet → Payout Method</strong> in the app to add your bank details.</p>
            </td>
          </tr>
          <tr>
            <td width="28" style="vertical-align:top;padding-top:1px;"><span style="font-size:14px;color:#D8B99C;font-weight:700;">—</span></td>
            <td>
              <p style="margin:0;font-size:14px;font-weight:600;color:#160c08;">Share your author profile</p>
              <p style="margin:2px 0 0;font-size:13px;color:#6b5448;">Let your readers find you on SBA Reads and grow your audience.</p>
            </td>
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
    Questions? Reach us at <a href="mailto:support@sbareads.com" style="color:#4E342E;font-weight:600;">support@sbareads.com</a> and we'll be happy to help.
  </p>

  <p style="margin:24px 0 0;font-size:14px;color:#9e8272;">
    Happy publishing,<br/>
    <strong style="color:#160c08;">The SBA Reads Team</strong>
  </p>
@endsection
