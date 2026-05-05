<?php

namespace App\Mail\Login;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class LoginNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $user;

    public $provider;

    public $ipAddress;

    public function __construct($user, $provider, $ipAddress)
    {
        $this->user = $user;
        $this->provider = $provider;
        $this->ipAddress = $ipAddress;
    }

    public function build()
    {
        $name = ($this->user->name && $this->user->name !== 'NO NAME')
            ? $this->user->name
            : 'there';

        $location = $this->resolveLocation($this->ipAddress);

        return $this->subject('New Login Detected — SBA Reads')
            ->view('emails.login_notification')
            ->with([
                'name'      => $name,
                'provider'  => ucfirst($this->provider),
                'time'      => now()->format('M d, Y \a\t h:i A'),
                'ipAddress' => $this->ipAddress,
                'location'  => $location,
            ]);
    }

    private function resolveLocation(string $ip): string
    {
        // Skip lookup for private/local IPs
        if (in_array($ip, ['127.0.0.1', '::1']) || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')) {
            return 'Local Network';
        }

        try {
            $response = Http::timeout(3)->get("http://ip-api.com/json/{$ip}?fields=city,regionName,country,status");
            $data = $response->json();

            if (($data['status'] ?? '') === 'success') {
                return implode(', ', array_filter([$data['city'] ?? '', $data['regionName'] ?? '', $data['country'] ?? '']));
            }
        } catch (\Throwable) {
            // Silently fall through if geolocation fails
        }

        return 'Unknown';
    }
}
