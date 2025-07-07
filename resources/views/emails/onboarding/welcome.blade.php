<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Welcome to Sbareads-Library</title>
</head>
<body>
<h2>Welcome {{ ucfirst($accountType) }} {{ $name }},</h2>
<p>We're thrilled to have you join our <strong>Sbareads-Library</strong> platform!</p>
@if($accountType === 'reader')
    <p>
        You can now enjoy thousands of books, bookmark your favorites, and track your reading journey.
    </p>
@else
    <p>
        You're now a step closer to becoming a published author on our platform! Once your documents are verified,
        you’ll be able to upload and share your books globally.
    </p>
@endif
<p>
    Thanks,<br>
    <strong>The Sbareads-Library Team</strong>
</p>
</body>
</html>
