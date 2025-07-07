<!DOCTYPE html>
<html>
<head>
    <title>Password Reset OTP</title>
</head>
<body>
    <p>Hello {{ $name }},</p>
    <p>Your OTP for password reset is: <strong>{{ $body }}</strong></p>
    <p>This OTP will expire in 10 minutes.</p>
    <p>If you didn't request this, please ignore this email.</p>
</body>
</html>
