<?php

namespace App\Support;

/** Where Stripe's card page sends website buyers back to, always on our own website. */
class WebCheckout
{
    public static function urls(string $path): array
    {
        // A plain path on our site only (an optional simple query is allowed): no other host, no scheme, no "//".
        if (! preg_match('#^/(?!/)[A-Za-z0-9/_\-.~%]*(\?[A-Za-z0-9_\-=&]*)?$#', $path)) {
            $path = '/library';
        }
        $base = rtrim((string) config('services.web.url'), '/') . $path;
        $join = str_contains($path, '?') ? '&' : '?';

        return [
            'success_url' => $base . $join . 'paid=1',
            'cancel_url' => $base . $join . 'cancelled=1',
        ];
    }
}
