<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Book Approved</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #333;
            background-color: #f9f9f9;
            padding: 2rem;
        }
        .container {
            background-color: #fff;
            border-radius: 6px;
            padding: 2rem;
            max-width: 600px;
            margin: auto;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }
        h1 {
            color: #2c3e50;
        }
        p {
            font-size: 16px;
            line-height: 1.6;
        }
        .footer {
            margin-top: 2rem;
            font-size: 14px;
            color: #888;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📘 Book Approved</h1>
        <p>Congratulations! Your book <strong>{{ $book->title }}</strong> has been approved and is now live on the platform.</p>

        <div class="footer">
            Thanks,<br>
            {{ config('app.name') }}
        </div>
    </div>
</body>
</html>
