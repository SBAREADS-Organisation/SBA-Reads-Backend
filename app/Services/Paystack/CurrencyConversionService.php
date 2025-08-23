<?php

namespace App\Services\Paystack;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CurrencyConversionService
{
    protected $apiKey;
    protected $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.currency.api_key');
        $this->baseUrl = config('services.currency.base_url', 'https://api.exchangerate-api.com/v4');
    }

    /**
     * Convert currency from one to another
     */
    public function convert(float $amount, string $fromCurrency, string $toCurrency): float
    {
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }

        $rate = $this->getExchangeRate($fromCurrency, $toCurrency);

        return $amount * $rate;
    }

    /**
     * Get exchange rate between two currencies
     */
    public function getExchangeRate(string $fromCurrency, string $toCurrency): float
    {
        $cacheKey = "exchange_rate_{$fromCurrency}_{$toCurrency}";

        return Cache::remember($cacheKey, 3600, function () use ($fromCurrency, $toCurrency) {
            try {
                $response = Http::get("{$this->baseUrl}/latest/{$fromCurrency}");

                if ($response->successful()) {
                    $data = $response->json();
                    return $data['rates'][$toCurrency] ?? 1;
                }

                // Fallback to 1:1 if API fails
                return 1;
            } catch (\Exception $e) {
                Log::error('Currency conversion error: ' . $e->getMessage());
                return 1;
            }
        });
    }

    /**
     * Get all available rates for a base currency
     */
    public function getAllRates(string $baseCurrency = 'USD'): array
    {
        $cacheKey = "all_rates_{$baseCurrency}";

        return Cache::remember($cacheKey, 3600, function () use ($baseCurrency) {
            try {
                $response = Http::get("{$this->baseUrl}/latest/{$baseCurrency}");

                if ($response->successful()) {
                    $data = $response->json();
                    return $data['rates'] ?? [];
                }

                return [];
            } catch (\Exception $e) {
                Log::error('Currency rates error: ' . $e->getMessage());
                return [];
            }
        });
    }

    /**
     * Format currency amount
     */
    public function formatAmount(float $amount, string $currency): string
    {
        $symbols = [
            'USD' => '$',
            'NGN' => '₦',
            'EUR' => '€',
            'GBP' => '£',
            'CAD' => 'C$',
            'AUD' => 'A$',
        ];

        $symbol = $symbols[strtoupper($currency)] ?? $currency . ' ';

        return $symbol . number_format($amount, 2);
    }

    /**
     * Get supported currencies
     */
    public function getSupportedCurrencies(): array
    {
        return [
            'USD' => 'US Dollar',
            'NGN' => 'Nigerian Naira',
            'EUR' => 'Euro',
            'GBP' => 'British Pound',
            'CAD' => 'Canadian Dollar',
            'AUD' => 'Australian Dollar',
            'GHS' => 'Ghanaian Cedi',
            'KES' => 'Kenyan Shilling',
            'ZAR' => 'South African Rand',
        ];
    }
}