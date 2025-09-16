<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Paystack\CurrencyConversionService;

class TestCurrencyConversion extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:currency-conversion {amount=100} {from=USD} {to=NGN}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the CurrencyConversionService convert method';

    protected $currencyConversionService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(CurrencyConversionService $currencyConversionService)
    {
        parent::__construct();
        $this->currencyConversionService = $currencyConversionService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $amount = (float) $this->argument('amount');
        $from = strtoupper($this->argument('from'));
        $to = strtoupper($this->argument('to'));

        $this->info("Testing conversion of {$amount} from {$from} to {$to}...");

        try {
            $convertedAmount = $this->currencyConversionService->convert($amount, $from, $to);
            $formatted = $this->currencyConversionService->formatAmount($convertedAmount, $to);
            $this->info("Converted amount: {$formatted}");
        } catch (\Exception $e) {
            $this->error("Conversion failed: " . $e->getMessage());
        }

        return 0;
    }
}
