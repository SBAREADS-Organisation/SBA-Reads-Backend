@extends('emails.layout')
@section('title', 'Action Required: Please Re-upload Your Book File')

@section('content')
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#c0392b;text-transform:uppercase;letter-spacing:1px;">Action Required</p>
  <h1 style="margin:0 0 16px;font-size:24px;font-weight:700;color:#160c08;">Your Book File Needs to Be Re-uploaded</h1>

  <p style="margin:0 0 20px;font-size:15px;color:#4a3728;line-height:1.7;">
    Hi <strong>{{ $authorName }}</strong>,
  </p>

  <p style="margin:0 0 20px;font-size:15px;color:#4a3728;line-height:1.7;">
    We recently upgraded our file storage system, and unfortunately a technical issue on our end caused
    some book files not to be saved correctly during the transition. We sincerely apologise for this inconvenience —
    this was entirely our fault, not yours.
  </p>

  {{-- Force restart FIRST --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="background:#fff8f0;border:1px solid #f0c090;border-radius:10px;padding:16px 20px;">
        <p style="margin:0 0 6px;font-size:13px;font-weight:700;color:#d4651a;">Before you begin — important first step</p>
        <p style="margin:0;font-size:13px;color:#4a3728;line-height:1.6;">
          We have pushed an update to the SBA Reads app that enables the re-upload feature.
          Please <strong>force close the SBA Reads app and reopen it</strong> before following the steps below.
          Without this, the upload option may not appear.
        </p>
      </td>
    </tr>
  </table>

  {{-- Affected books --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;border:1px solid #e8ddd6;border-radius:10px;overflow:hidden;">
    <tr>
      <td style="background:#fff5f5;padding:16px 24px;border-bottom:1px solid #e8ddd6;">
        <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Affected Book{{ count($bookTitles) > 1 ? 's' : '' }}</p>
      </td>
    </tr>
    @foreach($bookTitles as $title)
    <tr>
      <td style="background:#ffffff;padding:14px 24px;{{ !$loop->last ? 'border-bottom:1px solid #f0ebe6;' : '' }}">
        <p style="margin:0;font-size:15px;font-weight:600;color:#160c08;">{{ $title }}</p>
      </td>
    </tr>
    @endforeach
  </table>

  {{-- What you need to do --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;border:1px solid #e8ddd6;border-radius:10px;overflow:hidden;">
    <tr>
      <td style="background:#f5f0eb;padding:16px 24px;border-bottom:1px solid #e8ddd6;">
        <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">How to Re-upload</p>
      </td>
    </tr>
    <tr>
      <td style="background:#ffffff;padding:20px 24px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          @foreach([
            ['1', 'Open the SBA Reads app and log in to your <strong>Author Dashboard</strong>.'],
            ['2', 'Tap <strong>My Books</strong> in the menu.'],
            ['3', 'Search for the affected book by title.'],
            ['4', '<strong>Long press</strong> on the book — you will see two options: <strong>Edit Book</strong> and <strong>Enable Visibility</strong>.'],
            ['5', 'Tap <strong>Edit Book</strong>.'],
            ['6', 'Scroll down to the <strong>Content Upload</strong> section.'],
            ['7', 'Tap <strong>Replace PDF</strong> and select your book file from your device.'],
            ['8', 'Tap <strong>Update Book</strong> to save. Your file will be live immediately.'],
          ] as [$step, $text])
          <tr>
            <td style="width:32px;vertical-align:top;padding-bottom:14px;">
              <span style="display:inline-block;width:24px;height:24px;background:#D8B99C;border-radius:50%;text-align:center;line-height:24px;font-size:12px;font-weight:700;color:#160c08;">{{ $step }}</span>
            </td>
            <td style="vertical-align:top;padding-bottom:14px;padding-left:8px;">
              <p style="margin:0;font-size:14px;color:#4a3728;line-height:1.6;">{!! $text !!}</p>
            </td>
          </tr>
          @endforeach
        </table>
      </td>
    </tr>
  </table>

  {{-- Reassurance --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;">
    <tr>
      <td style="background:#f0faf4;border-left:3px solid #27ae60;border-radius:0 8px 8px 0;padding:14px 18px;">
        <p style="margin:0;font-size:13px;color:#4a3728;line-height:1.6;">
          <strong style="color:#160c08;">Your book data is safe.</strong>
          Your book page, ratings, reviews, purchase history, and App Store product ID are all fully preserved.
          Once you re-upload the file, your book will be restored and made available to readers immediately.
          Any readers who already purchased your book will automatically regain access.
        </p>
      </td>
    </tr>
  </table>

  <p style="margin:0 0 24px;font-size:15px;color:#4a3728;line-height:1.7;">
    If you have any trouble re-uploading or have any questions, please reply to this email and we will assist you right away.
    We truly appreciate your patience and partnership with SBA Reads.
  </p>

  <p style="margin:0;font-size:14px;color:#9e8272;">
    With apologies and appreciation,<br/>
    <strong style="color:#160c08;">The SBA Reads Team</strong>
  </p>
@endsection
