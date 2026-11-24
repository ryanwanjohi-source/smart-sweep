<?php
// ============================================================
//  Smart Sweep — Payments API settings (SAMPLE)
//
//  Copy this file to config/payments.php and fill in the
//  real details to take the payments API live. Until then
//  the API runs in test mode: no real money moves and every
//  response says "test_mode": true.
//
//  Keep the real config/payments.php OUT of GitHub — it
//  holds gateway secrets. (It is listed in .gitignore.)
//
//  M-Pesa (Daraja): register an app at
//  https://developer.safaricom.co.ke to get the consumer
//  key and secret; the shortcode and passkey come from your
//  paybill/till. The callback URL must be a public https
//  address where api/mpesa-callback.php can be reached.
// ============================================================

return [
    // Daraja details present => the API goes live by itself.
    'daraja' => [
        'environment'     => 'sandbox',  // 'sandbox' while testing, then 'live'
        'consumer_key'    => '',
        'consumer_secret' => '',
        'shortcode'       => '',         // e.g. '174379' on the sandbox
        'passkey'         => '',
        'callback_url'    => '',         // e.g. 'https://your-domain/smart-sweep/api/mpesa-callback.php'
    ],

    // M-Pesa PayBill — the business number residents pay to on their
    // phones (Lipa na M-Pesa → PayBill). The account number they quote
    // is their invoice code. Register the C2B confirmation URL with
    // Safaricom (api/mpesa-c2b.php) for paybill payments to complete
    // themselves; until then the Accountant confirms them.
    'paybill' => [
        'business_number' => '123456',
    ],

    // Where Bank Transfer payments are sent.
    'bank' => [
        'bank_name'      => 'Co-operative Bank',
        'account_name'   => 'Smart Sweep Collections',
        'account_number' => '01134567891000',
    ],
];
