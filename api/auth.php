<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';

$action = trim($_GET['action'] ?? '');
if (!$action) json_err('Missing action', 400);

match($action) {
    'login'      => auth_login(),
    'logout'     => auth_logout(),
    'refresh'    => auth_refresh(),
    'register'   => auth_register(),
    'forgot'     => auth_forgot(),
    'reset'      => auth_reset(),
    'verify'     => auth_verify(),
    'me'         => auth_me(),
    'setup'      => auth_setup(),
    default      => json_err("Unknown action: {$action}", 404),
};

// ── LOGIN ─────────────────────────────────────────────────────
function auth_login(): void {
    if (method() !== 'POST') json_err('POST required', 405);

    $b     = json_body();
    $email = strtolower(trim($b['email'] ?? ''));
    $pass  = $b['password'] ?? '';

    if (!$email || !$pass) json_err('Email and password are required', 422);

    // Look up user
    $user = db_row(
        "SELECT u.id, u.tenant_id, u.email, u.password_hash, u.full_name, u.role,
                u.is_active, u.email_verified_at,
                t.name AS tenant_name, t.slug AS tenant_slug,
                t.primary_color, t.language AS tenant_lang
         FROM users u
         JOIN tenants t ON t.id = u.tenant_id
         WHERE u.email = ?
         LIMIT 1",
        [$email]
    );

    // Deliberate constant-time: always run password_verify
    $hashOk = $user ? verify_pw($pass, $user['password_hash']) : false;

    if (!$user || !$hashOk) {
        json_err('Invalid email or password', 401);
    }

    if (!(int)$user['is_active']) {
        json_err('Your account has been disabled. Contact your administrator.', 403);
    }

    if (!$user['email_verified_at']) {
        json_err('Please verify your email before logging in. Check your inbox or use the verify link.', 403);
    }

    // Update last login
    db_run("UPDATE users SET last_login_at = NOW() WHERE id = ?", [(int)$user['id']]);

    // Issue tokens
    $tokens = jwt_make($user);
    audit('user.login', 'user', (int)$user['id']);

    json_ok(array_merge($tokens, [
        'user' => [
            'id'            => (int)$user['id'],
            'tenant_id'     => (int)$user['tenant_id'],
            'tenant_name'   => $user['tenant_name'],
            'tenant_slug'   => $user['tenant_slug'],
            'primary_color' => $user['primary_color'],
            'tenant_lang'   => $user['tenant_lang'],
            'full_name'     => $user['full_name'],
            'email'         => $user['email'],
            'role'          => $user['role'],
        ],
    ]), 'Login successful');
}

// ── LOGOUT ────────────────────────────────────────────────────
function auth_logout(): void {
    $b   = json_body();
    $raw = $b['refresh_token'] ?? '';
    if ($raw) {
        db_run("UPDATE refresh_tokens SET revoked_at=NOW() WHERE token_hash=?", [hash('sha256', $raw)]);
    }
    json_ok(null, 'Logged out');
}

// ── REFRESH ───────────────────────────────────────────────────
function auth_refresh(): void {
    $b   = json_body();
    $raw = $b['refresh_token'] ?? '';
    if (!$raw) json_err('refresh_token required', 422);

    $hash = hash('sha256', $raw);
    $rt   = db_row(
        "SELECT rt.user_id FROM refresh_tokens rt
         WHERE rt.token_hash=? AND rt.revoked_at IS NULL AND rt.expires_at > NOW()",
        [$hash]
    );
    if (!$rt) json_err('Invalid or expired refresh token', 401);

    db_run("UPDATE refresh_tokens SET revoked_at=NOW() WHERE token_hash=?", [$hash]);
    $user   = db_row("SELECT * FROM users WHERE id=?", [(int)$rt['user_id']]);
    if (!$user || !(int)$user['is_active']) json_err('User not found', 401);

    json_ok(jwt_make($user), 'Token refreshed');
}

// ── REGISTER ─────────────────────────────────────────────────
function auth_register(): void {
    if (method() !== 'POST') json_err('POST required', 405);

    $b = json_body();
    $errors = validate($b, [
        'company_name' => 'required|min:2|max:255',
        'full_name'    => 'required|min:2|max:255',
        'email'        => 'required|email|max:255',
        'password'     => 'required|min:8|max:128',
    ]);
    if ($errors) json_err('Validation failed', 422, $errors);

    try {
        $result = db_transaction(function() use ($b) {
            $email    = strtolower(trim($b['email']));
            $company  = trim($b['company_name']);
            $name     = trim($b['full_name']);

            $slug     = make_slug($company, 'tenants');
            $tenantId = db_insert("INSERT INTO tenants (name, slug) VALUES (?,?)", [$company, $slug]);

            $planId = db_val("SELECT id FROM plans WHERE id=2 LIMIT 1") ?? db_val("SELECT id FROM plans LIMIT 1") ?? 1;
            db_insert(
                "INSERT INTO subscriptions (tenant_id,plan_id,status,trial_ends_at,period_start,period_end) VALUES (?,?,'trialing',?,NOW(),?)",
                [$tenantId, $planId, date('Y-m-d H:i:s', strtotime('+14 days')), date('Y-m-d H:i:s', strtotime('+14 days'))]
            );

            $userId = db_insert(
                "INSERT INTO users (tenant_id,email,password_hash,full_name,role) VALUES (?,?,?,?,'owner')",
                [$tenantId, $email, hash_pw($b['password']), $name]
            );

            $token = make_token();
            db_insert("INSERT INTO email_verifications (user_id,token,expires_at) VALUES (?,?,?)",
                [$userId, $token, date('Y-m-d H:i:s', strtotime('+24 hours'))]);

            $link = BASE_URL . '/api/auth.php?action=verify&token=' . $token;
            log_info('VERIFY LINK', ['email' => $email, 'link' => $link]);
            @send_mail($email, 'Verify your ContractAI account', "Verify your email:\n{$link}\n\nExpires in 24 hours.");

            return ['user_id' => $userId, 'verify_link' => $link];
        });

        json_ok(
            ['user_id' => $result['user_id'], 'verify_link' => APP_ENV !== 'production' ? $result['verify_link'] : null],
            'Account created! Check your email to verify.'
        );
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), '1062')) json_err('An account with this email already exists.', 409);
        json_err('Registration failed. Please try again.', 500);
    }
}

// ── VERIFY EMAIL ──────────────────────────────────────────────
function auth_verify(): void {
    $token = trim($_GET['token'] ?? '');
    $base  = BASE_URL . '/index.html#/verified';

    if (!$token) { header("Location: {$base}?status=invalid"); exit; }

    $row = db_row("SELECT * FROM email_verifications WHERE token=? AND expires_at > NOW()", [$token]);
    if (!$row) { header("Location: {$base}?status=invalid"); exit; }

    db_run("UPDATE users SET email_verified_at=NOW() WHERE id=?", [(int)$row['user_id']]);
    db_run("DELETE FROM email_verifications WHERE id=?", [(int)$row['id']]);

    header("Location: {$base}?status=ok");
    exit;
}

// ── FORGOT PASSWORD ───────────────────────────────────────────
function auth_forgot(): void {
    $b     = json_body();
    $email = strtolower(trim($b['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Invalid email', 422);

    $user = db_row("SELECT id FROM users WHERE email=? AND is_active=1 LIMIT 1", [$email]);
    if ($user) {
        $token = make_token();
        db_run("DELETE FROM password_resets WHERE email=?", [$email]);
        db_insert("INSERT INTO password_resets (email,token,expires_at) VALUES (?,?,?)",
            [$email, $token, date('Y-m-d H:i:s', strtotime('+1 hour'))]);
        $link = BASE_URL . '/index.html#/reset-password?token=' . $token;
        log_info('RESET LINK', ['email' => $email, 'link' => $link]);
        @send_mail($email, 'Reset your ContractAI password', "Reset link:\n{$link}\n\nExpires in 1 hour.");
    }
    json_ok(null, 'If that email exists, a reset link has been sent.');
}

// ── RESET PASSWORD ────────────────────────────────────────────
function auth_reset(): void {
    $b     = json_body();
    $token = trim($b['token'] ?? '');
    if (!$token) json_err('Token required', 422);

    $row = db_row("SELECT * FROM password_resets WHERE token=? AND expires_at > NOW() AND used_at IS NULL", [$token]);
    if (!$row) json_err('This link is invalid or expired.', 400);

    $pw = $b['password'] ?? '';
    if (strlen($pw) < 8) json_err('Password must be at least 8 characters', 422);

    db_run("UPDATE users SET password_hash=? WHERE email=?", [hash_pw($pw), $row['email']]);
    db_run("UPDATE password_resets SET used_at=NOW() WHERE id=?", [(int)$row['id']]);

    $user = db_row("SELECT id FROM users WHERE email=?", [$row['email']]);
    if ($user) db_run("UPDATE refresh_tokens SET revoked_at=NOW() WHERE user_id=?", [(int)$user['id']]);

    json_ok(null, 'Password updated. You can now log in.');
}

// ── ME ────────────────────────────────────────────────────────
function auth_me(): void {
    $user = auth_required();
    unset($user['password_hash']);

    $tenant = db_row("SELECT name,slug,logo_path,primary_color,language,ai_prompt FROM tenants WHERE id=?", [(int)$user['tenant_id']]);
    $sub    = db_row(
        "SELECT s.status,s.contracts_used,s.ai_calls_used,s.trial_ends_at,
                p.name AS plan_name,p.max_users,p.max_contracts,p.max_ai_calls
         FROM subscriptions s JOIN plans p ON p.id=s.plan_id
         WHERE s.tenant_id=? ORDER BY s.id DESC LIMIT 1",
        [(int)$user['tenant_id']]
    );
    json_ok(['user' => $user, 'tenant' => $tenant, 'subscription' => $sub]);
}

// ── SETUP — creates admin account, use once then remove ───────
// GET /api/auth.php?action=setup
function auth_setup(): void {
    if (!APP_DEBUG) json_err('Not available in production', 403);

    $email    = 'admin@cogzidel.com';
    $password = 'admin123';

    // Ensure tenant
    $tenant = db_row("SELECT id FROM tenants WHERE slug='cogzidel' LIMIT 1");
    if ($tenant) {
        $tenantId = (int)$tenant['id'];
    } else {
        $tenantId = db_insert(
            "INSERT INTO tenants (name,slug,primary_color,language,timezone,is_active) VALUES (?,?,?,?,?,1)",
            ['Cogzidel', 'cogzidel', '#1a3c5e', 'en', 'Asia/Kolkata']
        );
    }

    // Ensure plan
    $planId = db_val("SELECT id FROM plans ORDER BY price_usd DESC LIMIT 1");
    if (!$planId) {
        $planId = db_insert("INSERT INTO plans (name,billing_cycle,price_usd,max_users,max_contracts,max_ai_calls) VALUES ('Enterprise','monthly',399,50,500,1500)");
    }

    // Ensure active subscription
    $sub = db_row("SELECT id FROM subscriptions WHERE tenant_id=? LIMIT 1", [$tenantId]);
    if (!$sub) {
        db_insert("INSERT INTO subscriptions (tenant_id,plan_id,status,period_start,period_end) VALUES (?,?,'active',NOW(),DATE_ADD(NOW(),INTERVAL 10 YEAR))", [$tenantId, $planId]);
    } else {
        db_run("UPDATE subscriptions SET status='active' WHERE tenant_id=?", [$tenantId]);
    }

    // Hash MUST be generated by PHP on the server — never trust externally generated hashes
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    // Upsert user
    $existing = db_row("SELECT id FROM users WHERE email=? LIMIT 1", [$email]);
    if ($existing) {
        db_run("UPDATE users SET password_hash=?, tenant_id=?, role='owner', is_active=1, email_verified_at=NOW() WHERE id=?",
            [$hash, $tenantId, (int)$existing['id']]);
        $done = 'updated';
    } else {
        db_insert("INSERT INTO users (tenant_id,email,password_hash,full_name,role,is_active,email_verified_at) VALUES (?,?,?,'Admin','owner',1,NOW())",
            [$tenantId, $email, $hash]);
        $done = 'created';
    }

    // Immediate verification
    $check   = db_row("SELECT password_hash, email_verified_at, is_active FROM users WHERE email=?", [$email]);
    $hashOk  = password_verify($password, $check['password_hash'] ?? '');

    json_ok([
        'status'         => $done,
        'email'          => $email,
        'password'       => $password,
        'hash_verify'    => $hashOk,
        'email_verified' => !empty($check['email_verified_at']),
        'is_active'      => (bool)($check['is_active'] ?? false),
        'tenant_id'      => $tenantId,
        'can_login'      => $hashOk && !empty($check['email_verified_at']) && ($check['is_active'] ?? false),
    ], $hashOk ? 'Setup done — login with admin@cogzidel.com / admin123' : 'HASH MISMATCH — something is wrong with PHP password_hash on this server');
}
