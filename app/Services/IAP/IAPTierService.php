<?php

namespace App\Services\IAP;

/**
 * Maps USD prices to pre-registered App Store / Play Store IAP product IDs.
 *
 * These tier SKUs are submitted to both stores once and shared across every
 * book at that price point.  New books become purchaseable immediately after
 * admin approval — no per-book store submission is needed.
 *
 * Tier SKUs registered in App Store Connect and Google Play Console:
 *   sba_book_tier_099   → $0.99
 *   sba_book_tier_199   → $1.99
 *   sba_book_tier_299   → $2.99
 *   sba_book_tier_499   → $4.99
 *   sba_book_tier_999   → $9.99
 *   sba_book_tier_1499  → $14.99
 *   sba_book_tier_1999  → $19.99
 *   sba_book_tier_2499  → $24.99+ (catch-all)
 *
 * Audio equivalents append "_audio" (e.g. sba_book_tier_499_audio).
 */
class IAPTierService
{
    private const TIERS = [
        0.99  => 'sba_book_tier_099',
        1.99  => 'sba_book_tier_199',
        2.99  => 'sba_book_tier_299',
        4.99  => 'sba_book_tier_499',
        9.99  => 'sba_book_tier_999',
        14.99 => 'sba_book_tier_1499',
        19.99 => 'sba_book_tier_1999',
    ];

    private const DEFAULT_TIER = 'sba_book_tier_2499';

    /**
     * Return the IAP tier SKU for a given USD price.
     */
    public static function skuForPrice(float $price): string
    {
        foreach (self::TIERS as $max => $sku) {
            if ($price <= $max) {
                return $sku;
            }
        }

        return self::DEFAULT_TIER;
    }

    /**
     * Return the audio IAP tier SKU for a given USD audio price.
     */
    public static function audioSkuForPrice(float $price): string
    {
        return self::skuForPrice($price) . '_audio';
    }

    /**
     * True when a SKU belongs to the shared tier system rather than a
     * legacy per-book product ID.
     */
    public static function isTierSku(string $sku): bool
    {
        return str_starts_with($sku, 'sba_book_tier_');
    }
}
