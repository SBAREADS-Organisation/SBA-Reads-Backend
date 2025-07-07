<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Book Submission Declined</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f9f9f9;
            padding: 2rem;
            color: #333;
        }
        .container {
            max-width: 600px;
            margin: auto;
            background: #fff;
            padding: 2rem;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        h2 {
            color: #c0392b;
        }
        p {
            font-size: 16px;
            line-height: 1.6;
        }
        .footer {
            margin-top: 2rem;
            font-size: 14px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>📕 Book Declined</h2>

        <p>Dear {{ $user->name }},</p>

        <p>We're sorry to inform you that your book titled <strong>{{ $book->title }}</strong> has been declined.</p>

        <p><strong>Reason:</strong> {{ $reason }}</p>

        <p>You may revise and resubmit it based on the feedback provided.</p>

        <div class="footer">
            Thank you,<br>
            {{ config('app.name') }}
        </div>
    </div>
</body>
</html>
