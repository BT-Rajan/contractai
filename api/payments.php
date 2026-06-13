<?php
/**
 * ContractAI – Payments API (Razorpay recharge)
 *
 * GET  /api/payments.php?action=config
 *      → Returns recharge packages, public key, and prefill details
 *        (name, email, contact) for the Razorpay checkout modal.
 *
 * POST /api/payments.php?action=create_order
 *      body: { package: 'starter' }
 *      → Creates a Razorpay order server-side (using key_secret) and
 *        returns order_id + amount + currency for the checkout modal.
 *
 * POST /api/payments.php?action=verify
 *      body: { razorpay_order_id, razorpay_payment_id, razorpay_signature }
 *      → Verifies the HMAC signature, marks the payment as paid, and
 *        credits the tenant's subscription quota.
 */
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';

$user   = auth_required();
$action = $_GET['action'] ?? '';

match($action) {
    'config'       => pay_config_action($user),
    'create_order' => pay_create_order($user),
    'verify'       => pay_verify($user),
    default        => api_error('Unknown action', 404),
};

// ── CONFIG ────────────────────────────────────────────────────
function pay_config_action(array $user): void {
    $packages = load_pay_packages();

    // Strip internal quota fields from the public response
    $public = [];
    foreach ($packages as $key => $p) {
        $public[$key] = [
            'key'         => $key,
            'label'       => $p['label'],
            'description' => $p['description'],
            'amount'      => $p['amount'],
            'currency'    => $p['currency'] ?? RAZORPAY_CURRENCY,
            'amount_display' => number_format($p['amount'] / 100, 2),
        ];
    }

    $tenant = db_row("SELECT name FROM tenants WHERE id = ?", [$user['tenant_id']]);

    api_ok([
        'key_id'   => RAZORPAY_KEY_ID,
        'enabled'  => RAZORPAY_KEY_ID !== '' && RAZORPAY_KEY_SECRET !== '',
        'packages' => $public,
        'prefill'  => [
            'name'    => $user['full_name'] ?? '',
            'email'   => $user['email'] ?? '',
            'contact' => $user['phone'] ?? '',
        ],
        'company'  => $tenant['name'] ?? 'ContractAI',
    ]);
}

// ── CREATE ORDER ──────────────────────────────────────────────
function pay_create_order(array $user): void {
    if (RAZORPAY_KEY_ID === '' || RAZORPAY_KEY_SECRET === '') {
        api_error('Payment gateway not configured. Add RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET to .env', 503);
    }

    // Rate limit order creation to prevent abuse
    rate_limit('payment_order:' . $user['id'], 10, 3600);

    $b = json_body();
    $key = trim($b['package'] ?? '');

    $packages = load_pay_packages();
    if (!isset($packages[$key])) api_error('Invalid package', 422);

    $pkg = $packages[$key];

    // Create order via Razorpay Orders API
    $payload = [
        'amount'   => $pkg['amount'],
        'currency' => $pkg['currency'] ?? RAZORPAY_CURRENCY,
        'receipt'  => 'cai_' . $user['tenant_id'] . '_' . time(),
        'notes'    => [
            'tenant_id' => (string)$user['tenant_id'],
            'user_id'   => (string)$user['id'],
            'package'   => $key,
        ],
    ];

    $result = razorpay_api('/v1/orders', $payload);
    if (isset($result['error'])) {
        log_error('Razorpay order creation failed', ['error' => $result['error']]);
        api_error('Could not create payment order: ' . ($result['error']['description'] ?? 'unknown error'), 502);
    }

    db_insert(
        "INSERT INTO payments
         (tenant_id, user_id, package_key, razorpay_order_id, amount, currency, add_contracts, add_ai_calls)
         VALUES (?,?,?,?,?,?,?,?)",
        [
            $user['tenant_id'], $user['id'], $key, $result['id'],
            $pkg['amount'], $pkg['currency'] ?? RAZORPAY_CURRENCY,
            $pkg['add_contracts'] ?? 0, $pkg['add_ai_calls'] ?? 0,
        ]
    );

    audit('payment.order_created', 'tenant', $user['tenant_id'], ['package' => $key, 'order_id' => $result['id']]);

    api_ok([
        'order_id' => $result['id'],
        'amount'   => $result['amount'],
        'currency' => $result['currency'],
        'key_id'   => RAZORPAY_KEY_ID,
    ]);
}

// ── VERIFY ────────────────────────────────────────────────────
function pay_verify(array $user): void {
    if (RAZORPAY_KEY_SECRET === '') api_error('Payment gateway not configured', 503);

    $b         = json_body();
    $orderId   = trim($b['razorpay_order_id']   ?? '');
    $paymentId = trim($b['razorpay_payment_id'] ?? '');
    $signature = trim($b['razorpay_signature']  ?? '');

    if (!$orderId || !$paymentId || !$signature) api_error('Missing payment verification fields', 422);

    $payment = db_row(
        "SELECT * FROM payments WHERE razorpay_order_id = ? AND tenant_id = ?",
        [$orderId, $user['tenant_id']]
    );
    if (!$payment) api_error('Order not found', 404);
    if ($payment['status'] === 'paid') api_ok(null, 'Already verified');

    // Verify HMAC SHA256 signature: order_id|payment_id signed with key_secret
    $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, RAZORPAY_KEY_SECRET);
    if (!hash_equals($expected, $signature)) {
        db_run("UPDATE payments SET status='failed', razorpay_payment_id=?, razorpay_signature=? WHERE id=?",
            [$paymentId, $signature, $payment['id']]);
        audit('payment.verify_failed', 'tenant', $user['tenant_id'], ['order_id' => $orderId]);
        api_error('Payment signature verification failed', 400);
    }

    db_transaction(function () use ($payment, $paymentId, $signature, $user): void {
        db_run(
            "UPDATE payments SET status='paid', razorpay_payment_id=?, razorpay_signature=? WHERE id=?",
            [$paymentId, $signature, $payment['id']]
        );

        // Credit quota to the tenant's active subscription
        if ($payment['add_contracts'] > 0 || $payment['add_ai_calls'] > 0) {
            db_run(
                "UPDATE subscriptions SET
                   max_contracts_bonus = COALESCE(max_contracts_bonus,0) + ?,
                   max_ai_calls_bonus  = COALESCE(max_ai_calls_bonus,0)  + ?
                 WHERE tenant_id = ? ORDER BY id DESC LIMIT 1",
                [$payment['add_contracts'], $payment['add_ai_calls'], $user['tenant_id']]
            );
        }

        audit('payment.success', 'tenant', $user['tenant_id'], [
            'order_id'      => $payment['razorpay_order_id'],
            'payment_id'    => $paymentId,
            'amount'        => $payment['amount'],
            'currency'      => $payment['currency'],
            'add_contracts' => $payment['add_contracts'],
            'add_ai_calls'  => $payment['add_ai_calls'],
        ]);
    });

    api_ok(null, 'Payment verified — quota updated');
}

// ── Helpers ────────────────────────────────────────────────────

/** Load the recharge package config ("pay config page") */
function load_pay_packages(): array {
    $file = __DIR__ . '/../core/pay_config.php';
    if (!file_exists($file)) return [];
    $packages = require $file;
    return is_array($packages) ? $packages : [];
}

/** Minimal Razorpay REST client using cURL + Basic Auth (key_id:key_secret) */
function razorpay_api(string $endpoint, array $payload): array {
    $ch = curl_init('https://api.razorpay.com' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERPWD        => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $errno    = curl_errno($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($errno) return ['error' => ['description' => $err]];

    $decoded = json_decode((string)$response, true);
    return is_array($decoded) ? $decoded : ['error' => ['description' => 'Invalid response from Razorpay']];
}
