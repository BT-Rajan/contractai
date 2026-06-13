<?php
/**
 * ContractAI – Recharge Package Configuration ("pay config")
 *
 * Defines the recharge packages offered on the dashboard "Recharge" button.
 * Edit this file to add/remove/reprice packages — no code changes needed
 * in api/payments.php or the frontend.
 *
 * amount: in the smallest currency unit (e.g. paise for INR, cents for USD)
 *         Razorpay requires amounts in subunits: ₹500.00 = 50000
 *
 * Add or modify entries below. The 'key' must be unique and is used to
 * identify the package when creating an order.
 */
declare(strict_types=1);

return [

    'starter' => [
        'label'         => 'Starter Top-up',
        'description'   => '+50 contract generations / +200 AI calls',
        'amount'        => 99900,      // ₹999.00
        'currency'      => 'INR',
        'add_contracts' => 50,
        'add_ai_calls'  => 200,
    ],

    'growth' => [
        'label'         => 'Growth Top-up',
        'description'   => '+150 contract generations / +600 AI calls',
        'amount'        => 249900,     // ₹2,499.00
        'currency'      => 'INR',
        'add_contracts' => 150,
        'add_ai_calls'  => 600,
    ],

    'scale' => [
        'label'         => 'Scale Top-up',
        'description'   => '+500 contract generations / +2000 AI calls',
        'amount'        => 749900,     // ₹7,499.00
        'currency'      => 'INR',
        'add_contracts' => 500,
        'add_ai_calls'  => 2000,
    ],

];
