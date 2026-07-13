@extends('emails.layout')
@section('title', 'Reminder: Your Book File Still Needs to Be Re-uploaded')

@section('content')
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#d4651a;text-transform:uppercase;letter-spacing:1px;">Friendly Reminder</p>
  <h1 style="margin:0 0 16px;font-size:24px;font-weight:700;color:#160c08;">Your Book Is Still Waiting</h1>

  <p style="margin:0 0 20px;font-size:15px;color:#4a3728;line-height:1.7;">
    Hi <strong>{{ $authorName }}</strong>,
  </p>

  <p style="margin:0 0 20px;font-size:15px;color:#4a3728;line-height:1.7;">
    We noticed that the following {{ count($bookTitles) > 1 ? 'books have' : 'book has' }} not yet been re-uploaded.
    Your book {{ count($bookTitles) > 1 ? 'pages are' : 'page is' }} still hidden from readers and will remain unavailable until the file is replaced.
  </p>

  {{-- Affected books --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;border:1px solid #e8ddd6;border-radius:10px;overflow:hidden;">
    <tr>
      <td style="background:#fff8f0;padding:12px 20px;border-bottom:1px solid #e8ddd6;">
        <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Still needs re-upload</p>
      </td>
    </tr>
    @foreach($bookTitles as $title)
    <tr>
      <td style="background:#ffffff;padding:14px 20px;{{ !$loop->last ? 'border-bottom:1px solid #f0ebe6;' : '' }}">
        <p style="margin:0;font-size:15px;font-weight:600;color:#160c08;">{{ $title }}</p>
      </td>
    </tr>
    @endforeach
  </table>

  {{-- Force restart FIRST --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;">
    <tr>
      <td style="background:#fff8f0;border:1px solid #f0c090;border-radius:10px;padding:16px 20px;">
        <p style="margin:0 0 6px;font-size:13px;font-weight:700;color:#d4651a;">Before you begin — important first step</p>
        <p style="margin:0;font-size:13px;color:#4a3728;line-height:1.6;">
          Please <strong>force close the SBA Reads app and reopen it</strong> first.
          This applies the latest update and enables the re-upload option in your book editor.
        </p>
      </td>
    </tr>
  </table>

  {{-- Quick steps --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;background:#f5f0eb;border-radius:10px;">
    <tr>
      <td style="padding:18px 20px;">
        <p style="margin:0 0 10px;font-size:13px;font-weight:700;color:#160c08;">Quick steps to fix this:</p>
        <p style="margin:0;font-size:13px;color:#4a3728;line-height:2;">
          1. Open SBA Reads → <strong>Author Dashboard</strong><br/>
          2. Tap <strong>My Books</strong> → search for your book<br/>
          3. <strong>Long press</strong> the book → tap <strong>Edit Book</strong><br/>
          4. Scroll to <strong>Content Upload</strong> → tap <strong>Replace PDF</strong><br/>
          5. Select your file → tap <strong>Update Book</strong>
        </p>
      </td>
    </tr>
  </table>

  {{-- Force restart note --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="background:#f5f0eb;border-left:3px solid #D8B99C;border-radius:0 8px 8px 0;padding:14px 18px;">
        <p style="margin:0 0 4px;font-size:13px;font-weight:700;color:#160c08;">After re-uploading</p>
        <p style="margin:0;font-size:13px;color:#4a3728;line-height:1.6;">
          Once you have updated your book file, please <strong>force close the SBA Reads app and reopen it</strong>
          to confirm your book is opening correctly.
        </p>
      </td>
    </tr>
  </table>

  <p style="margin:0 0 24px;font-size:14px;color:#4a3728;line-height:1.7;">
    It only takes a couple of minutes. If you need any help, reply to this email and we will sort it out for you right away.
  </p>

  <p style="margin:0;font-size:14px;color:#9e8272;">
    Thank you,<br/>
    <strong style="color:#160c08;">The SBA Reads Team</strong>
  </p>
@endsection
