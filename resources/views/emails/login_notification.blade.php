<!DOCTYPE html>
<html>
<head>
    <title>New Login Alert</title>
</head>
<body>
    <p>Hello {{ $name }},</p>
    <p>Your account was just accessed using {{ $provider }} from IP: {{ $ipAddress }} at {{ $time }}.</p>
    <p>If this was not you, please reset your password immediately.</p>
    <p>Regards,</p>
    <p>The Team</p>
</body>
</html>
