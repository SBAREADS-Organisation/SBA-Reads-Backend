<?php

return [
    'digital_books' => [
        'platform_fee_percent' => 0.25,
        'author_payout_percent' => 0.75,
    ],

    'audio_books' => [
        'platform_fee_percent' => 0.70,
        'author_payout_percent' => 0.30,
    ],

    'payout_delay_days' => 7,

    // Wallets: earnings wait this many days before they can be withdrawn, so a refund or a
    // card dispute can still be covered.
    'wallet_hold_days' => (int) env('WALLET_HOLD_DAYS', 7),

    // After a payout bank account is changed, withdrawals wait this many hours (and the author is emailed),
    // so someone who gets into an account can't move the money straight out.
    'bank_change_hold_hours' => (int) env('BANK_CHANGE_HOLD_HOURS', 48),
];
