<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PayPal Mode
    |--------------------------------------------------------------------------
    |
    | Specify if PayPal should use live or sandbox environment
    | Possible options: sandbox, live
    |
    */
    'mode' => env('PAYPAL_MODE', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | PayPal API Credentials
    |--------------------------------------------------------------------------
    |
    | Specify PayPal API credentials. For sandbox and live modes.
    |
    */
    'client_id' => env('PAYPAL_CLIENT_ID', ''),
    'client_secret' => env('PAYPAL_CLIENT_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | PayPal Webhook ID
    |--------------------------------------------------------------------------
    |
    | The ID of the PayPal webhook configured in the PayPal developer dashboard
    |
    */
    'webhook_id' => env('PAYPAL_WEBHOOK_ID', ''),

    /*
    |--------------------------------------------------------------------------
    | PayPal Settings
    |--------------------------------------------------------------------------
    |
    | Various settings for PayPal integration
    |
    */
    'currency' => env('PAYPAL_CURRENCY', 'EUR'),
    'return_url' => env('APP_URL') . '/payment/confirmation',
    'cancel_url' => env('APP_URL') . '/cart',
];