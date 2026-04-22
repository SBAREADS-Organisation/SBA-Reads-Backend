@extends('emails.layout')
@section('title', 'Welcome to SBA Reads')

@section('content')
  {{-- Greeting --}}
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#D8B99C;text-transform:uppercase;letter-spacing:1px;">
    Welcome {{ $accountType === 'author' ? 'Author' : 'Reader' }}
  </p>
  <h1 style="margin:0 0 16px;font-size:26px;font-weight:700;color:#160c08;line-height:1.3;">
    Great to have you, {{ $name }}! 🎉
  </h1>
  <p style="margin:0 0 28px;font-size:15px;color:#4a3728;line-height:1.7;">
    @if($accountType === 'author')
      Your author account on SBA Reads is ready. You're one step closer to sharing your work with thousands of readers.
    @else
      Your SBA Reads account is all set. Thousands of books are waiting for you — start exploring today.
    @endif
  </p>

  {{-- What's next --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
    <tr>
      <td style="background:#f5f0eb;border-radius:12px;padding:24px;">
        <p style="margin:0 0 16px;font-size:14px;font-weight:700;color:#160c08;text-transform:uppercase;letter-spacing:1px;">What's next</p>

        @if($accountType === 'author')
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
              <td width="28" style="vertical-align:top;padding-top:1px;"><span style="font-size:16px;">📋</span></td>
              <td style="padding-bottom:12px;">
                <p style="margin:0;font-size:14px;font-weight:600;color:#160c08;">Complete your profile</p>
                <p style="margin:2px 0 0;font-size:13px;color:#6b5448;">Add your bio, photo, and KYC documents to get verified.</p>
              </td>
            </tr>
            <tr>
              <td width="28" style="vertical-align:top;padding-top:1px;"><span style="font-size:16px;">📖</span></td>
              <td style="padding-bottom:12px;">
                <p style="margin:0;font-size:14px;font-weight:600;color:#160c08;">Upload your first book</p>
                <p style="margin:2px 0 0;font-size:13px;color:#6b5448;">Submit your manuscript and cover for review.</p>
              </td>
            </tr>
            <tr>
              <td width="28" style="vertical-align:top;padding-top:1px;"><span style="font-size:16px;">🌍</span></td>
              <td>
                <p style="margin:0;font-size:14px;font-weight:600;color:#160c08;">Reach your audience</p>
                <p style="margin:2px 0 0;font-size:13px;color:#6b5448;">Once approved, your book goes live to thousands of readers.</p>
              </td>
            </tr>
          </table>
        @else
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
              <td width="28" style="vertical-align:top;padding-top:1px;"><span style="font-size:16px;">🔍</span></td>
              <td style="padding-bottom:12px;">
                <p style="margin:0;font-size:14px;font-weight:600;color:#160c08;">Browse the library</p>
                <p style="margin:2px 0 0;font-size:13px;color:#6b5448;">Discover books across genres — fiction, faith, business, and more.</p>
              </td>
            </tr>
            <tr>
              <td width="28" style="vertical-align:top;padding-top:1px;"><span style="font-size:16px;">🔖</span></td>
              <td style="padding-bottom:12px;">
                <p style="margin:0;font-size:14px;font-weight:600;color:#160c08;">Bookmark your favourites</p>
                <p style="margin:2px 0 0;font-size:13px;color:#6b5448;">Save books you love and return to them anytime.</p>
              </td>
            </tr>
            <tr>
              <td width="28" style="vertical-align:top;padding-top:1px;"><span style="font-size:16px;">🛒</span></td>
              <td>
                <p style="margin:0;font-size:14px;font-weight:600;color:#160c08;">Order physical copies</p>
                <p style="margin:2px 0 0;font-size:13px;color:#6b5448;">Order books to your door or pick up from our Lagos store.</p>
              </td>
            </tr>
          </table>
        @endif
      </td>
    </tr>
  </table>

  {{-- CTA --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
    <tr>
      <td align="center">
        <a href="{{ config('app.url') }}" style="display:inline-block;background:#160c08;color:#D8B99C;text-decoration:none;font-size:14px;font-weight:700;padding:14px 36px;border-radius:8px;letter-spacing:0.5px;">
          Open SBA Reads →
        </a>
      </td>
    </tr>
  </table>

  <p style="margin:0;font-size:14px;color:#9e8272;">
    Happy {{ $accountType === 'author' ? 'writing' : 'reading' }},<br/>
    <strong style="color:#160c08;">The SBA Reads Team</strong>
  </p>
@endsection
