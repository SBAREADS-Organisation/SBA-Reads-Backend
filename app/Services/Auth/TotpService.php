<?php

namespace App\Services\Auth;

/**
 * RFC 6238 TOTP implementation — no external dependency.
 *
 * Compatible with Google Authenticator, Authy, 1Password, and any
 * standards-compliant TOTP app (SHA-1, 30-second period, 6 digits).
 */
class TotpService
{
    private const CHARS  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const DIGITS = 6;
    private const PERIOD = 30;
    private const WINDOW = 1; // ±1 period tolerance for clock drift

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20)); // 160-bit secret
    }

    /**
     * Returns true if $code is valid for the given secret within the drift window.
     */
    public function verify(string $secret, string $code): bool
    {
        $step = (int) floor(time() / self::PERIOD);
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            if ($this->hotp($secret, $step + $i) === $code) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the otpauth:// URI that authenticator apps scan from a QR code.
     */
    public function otpauthUrl(string $issuer, string $account, string $secret): string
    {
        return 'otpauth://totp/'
            . rawurlencode($issuer . ':' . $account)
            . '?secret='  . rawurlencode($secret)
            . '&issuer='  . rawurlencode($issuer)
            . '&digits='  . self::DIGITS
            . '&period='  . self::PERIOD
            . '&algorithm=SHA1';
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function hotp(string $secret, int $counter): string
    {
        $key  = $this->base32Decode($secret);
        $msg  = pack('J', $counter); // 8-byte big-endian
        $hash = hash_hmac('sha1', $msg, $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $otp    = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
             (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $output   = '';
        $buffer   = 0;
        $bitsLeft = 0;

        foreach (str_split($data) as $char) {
            $buffer    = ($buffer << 8) | ord($char);
            $bitsLeft += 8;
            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $output   .= self::CHARS[($buffer >> $bitsLeft) & 31];
            }
        }

        if ($bitsLeft > 0) {
            $output .= self::CHARS[($buffer << (5 - $bitsLeft)) & 31];
        }

        return $output;
    }

    private function base32Decode(string $data): string
    {
        $output   = '';
        $buffer   = 0;
        $bitsLeft = 0;

        foreach (str_split(strtoupper($data)) as $char) {
            $pos = strpos(self::CHARS, $char);
            if ($pos === false) {
                continue;
            }
            $buffer    = ($buffer << 5) | $pos;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output   .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}
