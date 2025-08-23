<?php

namespace App\Services\Payments;

use App\Services\Paystack\PaystackService;
use App\Services\Stripe\StripeConnectService;
use InvalidArgumentException;

class PaymentProviderFactory
{
    /**
     * Create payment provider instance
     */
    public static function create(string $provider)
    {
        switch (strtolower($provider)) {
            case 'stripe':
                return app(StripeConnectService::class);

            case 'paystack':
                return app(PaystackService::class);

            default:
                throw new InvalidArgumentException("Unsupported payment provider: {$provider}");
        }
    }

    /**
     * Get available providers
     */
    public static function getProviders(): array
    {
        return [
            'stripe' => [
                'name' => 'Stripe',
                'description' => 'Global payment processing',
                'currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD'],
                'countries' => ['US', 'CA', 'GB', 'AU', 'DE', 'FR', 'IT', 'ES', 'NL', 'BE'],
            ],
            'paystack' => [
                'name' => 'Paystack',
                'description' => 'African payment processing',
                'currencies' => ['NGN', 'USD', 'GHS', 'KES', 'ZAR'],
                'countries' => ['NG', 'GH', 'KE', 'ZA'],
            ],
        ];
    }

    /**
     * Get provider for currency
     */
    public static function getProviderForCurrency(string $currency): string
    {
        $providers = self::getProviders();

        foreach ($providers as $key => $provider) {
            if (in_array(strtoupper($currency), $provider['currencies'])) {
                return $key;
            }
        }

        return 'stripe'; // Default provider
    }

    /**
     * Check if provider supports currency
     */
    public static function supportsCurrency(string $provider, string $currency): bool
    {
        $providers = self::getProviders();

        if (!isset($providers[$provider])) {
            return false;
        }

        return in_array(strtoupper($currency), $providers[$provider]['currencies']);
    }
}