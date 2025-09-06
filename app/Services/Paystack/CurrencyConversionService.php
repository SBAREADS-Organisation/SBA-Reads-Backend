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
        $this->baseUrl = config('services.currency.base_url', 'https://v6.exchangerate-api.com/v6');
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
     * Get exchange rate between two currencies with retry logic
     */
    public function getExchangeRate(string $fromCurrency, string $toCurrency): float
    {
        $cacheKey = "exchange_rate_{$fromCurrency}_{$toCurrency}";

        return Cache::remember($cacheKey, 3600, function () use ($fromCurrency, $toCurrency) {
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {

                    $url = "{$this->baseUrl}/{$this->apiKey}/latest/{$fromCurrency}";

                    // Use SSL verification in production, skip in local development
                    $http = app()->environment('local') ? Http::withoutVerifying() : Http::asJson();
                    $response = $http->timeout(10)->get($url);

                    if ($response->successful()) {
                        $data = $response->json();
                        if (isset($data['result']) && $data['result'] === 'success') {
                            $rate = $data['conversion_rates'][$toCurrency] ?? null;
                            if ($rate !== null) {
                                return $rate;
                            }
                        }
                        $rate = $data['rates'][$toCurrency] ?? null;
                        if ($rate !== null) {
                            return $rate;
                        }

                    } else {
                    }
                } catch (\Exception $e) {
                }

                if ($attempt < 3) {
                    sleep(1);
                }
            }
            throw new \Exception("Failed to get exchange rate from API after 3 attempts");
        });
    }

    /**
     * Get all available rates for a base currency with retry logic
     */
    public function getAllRates(string $baseCurrency = 'USD'): array
    {
        $cacheKey = "all_rates_{$baseCurrency}";

        return Cache::remember($cacheKey, 3600, function () use ($baseCurrency) {
            // Try up to 3 times to get all rates
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    $url = "{$this->baseUrl}/{$this->apiKey}/latest/{$baseCurrency}";

                    // Use SSL verification in production, skip in local development
                    $http = app()->environment('local') ? Http::withoutVerifying() : Http::asJson();
                    $response = $http->timeout(10)->get($url);

                    if ($response->successful()) {
                        $data = $response->json();
                        if (isset($data['result']) && $data['result'] === 'success') {
                            return $data['conversion_rates'] ?? [];
                        }
                        return $data['rates'] ?? [];
                    } else {
                    }
                } catch (\Exception $e) {
                    Log::error("Currency rates error on attempt $attempt: " . $e->getMessage());
                }
                if ($attempt < 3) {
                    sleep(1);
                }
            }
            return [];
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
