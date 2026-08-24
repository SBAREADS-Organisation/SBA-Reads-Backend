<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{{ $title }}</title>
  <style>
    body { margin:0; padding:0; background:#F2E8D9; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; }
    table { border-spacing:0; border-collapse:collapse; }
    img { border:0; display:block; }
    .wrapper { width:100%; background:#F2E8D9; padding:32px 16px; box-sizing:border-box; }
    .card { max-width:580px; margin:0 auto; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.07); }
    .header { background:#2D241F; padding:28px 40px; text-align:center; }
    .header-brand { font-size:22px; font-weight:800; color:#E8A020; letter-spacing:0.5px; }
    .header-brand span { color:#ffffff; }
    .body { padding:36px 40px 28px; }
    .greeting { font-size:17px; font-weight:700; color:#2D241F; margin:0 0 16px; }
    .content { font-size:14px; line-height:1.75; color:#4A3022; margin:0 0 8px; white-space:pre-line; }
    .divider { height:1px; background:#F0E4D0; margin:28px 0; }
    .footer { background:#F9F3EA; padding:20px 40px; text-align:center; }
    .footer-text { font-size:12px; color:#9A7355; margin:0; line-height:1.6; }
    .footer-text a { color:#E8A020; text-decoration:none; }
    .badge { display:inline-block; background:#FFF4E0; border:1px solid #E8A020; color:#4E342E; font-size:11px; font-weight:700; letter-spacing:0.5px; padding:4px 12px; border-radius:20px; margin-bottom:20px; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="card">

      <!-- Header -->
      <div class="header">
        <div class="header-brand">SBA <span>Reads</span></div>
      </div>

      <!-- Body -->
      <div class="body">
        <div class="badge">Author Notification</div>
        <p class="greeting">{{ $title }}</p>
        <p class="content">{{ $body }}</p>
        <div class="divider"></div>
      </div>

      <!-- Footer -->
      <div class="footer">
        <p class="footer-text">
          You received this email because you are a registered author on SBA Reads.<br />
          Questions? Email us at <a href="mailto:admin@sbareads.com">admin@sbareads.com</a>
        </p>
        <p class="footer-text" style="margin-top:8px;">
          &copy; {{ date('Y') }} SBA Reads. All rights reserved.
        </p>
      </div>

    </div>
  </div>
</body>
</html>
