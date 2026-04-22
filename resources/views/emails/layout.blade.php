<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>@yield('title', 'SBA Reads')</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f0eb;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;">

  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f0eb;padding:40px 16px;">
    <tr>
      <td align="center">
        <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">

          {{-- Header --}}
          <tr>
            <td style="background:#160c08;border-radius:12px 12px 0 0;padding:28px 40px;text-align:center;">
              <span style="font-size:24px;font-weight:700;color:#D8B99C;letter-spacing:2px;text-transform:uppercase;">SBA Reads</span>
              <p style="margin:4px 0 0;font-size:12px;color:rgba(216,185,156,0.6);letter-spacing:1px;">Your Reading Companion</p>
            </td>
          </tr>

          {{-- Body --}}
          <tr>
            <td style="background:#ffffff;padding:40px 40px 32px;">
              @yield('content')
            </td>
          </tr>

          {{-- Footer --}}
          <tr>
            <td style="background:#f5f0eb;border-top:1px solid #e8ddd6;padding:24px 40px;text-align:center;border-radius:0 0 12px 12px;">
              <p style="margin:0;font-size:12px;color:#9e8272;line-height:1.6;">
                © {{ date('Y') }} SBA Reads. All rights reserved.
              </p>
              <p style="margin:6px 0 0;font-size:11px;color:#b8a499;">
                You're receiving this because you have an account on SBA Reads.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>

</body>
</html>
