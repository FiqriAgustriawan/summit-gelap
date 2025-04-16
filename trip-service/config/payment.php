<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Guide Payment Settings
    |--------------------------------------------------------------------------
    |
    | These settings control how payments are distributed to guides
    |
    */

    // Percentage of the payment that goes to the guide (80% means guide gets 80%, platform keeps 20%)
    'guide_share_percentage' => env('GUIDE_SHARE_PERCENTAGE', 80),

    // Minimum withdrawal amount for guides
    'minimum_withdrawal' => env('GUIDE_MINIMUM_WITHDRAWAL', 100000),

    // Processing period in days
    'processing_period_days' => env('GUIDE_PAYMENT_PROCESSING_DAYS', 3),
];
