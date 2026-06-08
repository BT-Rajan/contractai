<?php
/**
 * ContractAI – Users, Settings & Dashboard API
 *
 * GET  ?action=dashboard    → stats + recent contracts
 * GET  ?action=list         → list team users [admin/owner]
 * POST ?action=invite        → invite user
 * GET  ?action=accept&token= → redirect to SPA (public)
 * POST ?action=accept        → complete invite signup (public)
 * POST ?action=toggle        → enable/disable user
 * POST ?action=profile       → update own profile/password
 * GET  ?action=settings      → get tenant + subscription data
 * POST ?action=settings      → save tenant settings
 * POST ?action=upload_logo   → upload tenant logo
 */
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');
$m      = method();

// ── Public routes (no auth needed) ───────────────────────────
if ($action === 'accept') {
    if ($m === 'GET')  { invite_redirect(); }
    if ($m === 'POST') { invite_complete(); }
    api_error('Method not allowed', 405);
}

// ── Auth required from here ───────────────────────────────────
$user = auth_required();

match($action) {
    'dashboard'   => action_dashboard($user),
    'list'        => action_list($user),
    'invite'      => action_invite($user),
    'toggle'      => action_toggle($user),
    'profile'     => action_profile($user),
    'settings'    => ($m === 'GET') ? action_settings_get($user) : action_settings_save($user),
    'upload_logo' => action_upload_logo($user),
    default       => api_error('Unknown action: ' . $action, 404),
};

// ── DASHBOARD ─────────────────────────────────────────────────
function action_dashboard(array $user): void {
    $tid = $user['tenant_id'];

    $stats = [
        'total_contracts'      => db_count("SELECT COUNT(*) FROM contracts     WHERE tenant_id = ?",               [$tid]),
        'draft_contracts'      => db_count("SELECT COUNT(*) FROM contracts     WHERE tenant_id = ? AND status='draft'", [$tid]),
        'final_contracts'      => db_count("SELECT COUNT(*) FROM contracts     WHERE tenant_id = ? AND status='final'", [$tid]),
        'total_counterparties' => db_count("SELECT COUNT(*) FROM counterparties WHERE tenant_id = ? AND is_active=1",  [$tid]),
        'total_templates'      => db_count("SELECT COUNT(*) FROM templates     WHERE tenant_id = ? AND is_active=1",   [$tid]),
        'total_users'          => db_count("SELECT COUNT(*) FROM users         WHERE tenant_id = ? AND is_active=1",   [$tid]),
    ];

    $sub = db_row(
        "SELECT s.*, p.name AS plan_name, p.max_contracts, p.max_ai_calls, p.max_users
         FROM subscriptions s JOIN plans p ON p.id = s.plan_id
         WHERE s.tenant_id = ? ORDER BY s.id DESC LIMIT 1",
        [$tid]
    );

    $recent = db_rows(
        "SELECT c.id, c.title, c.status, c.tone, c.created_at,
                u.full_name AS created_by_name
         FROM contracts c JOIN users u ON u.id = c.created_by
         WHERE c.tenant_id = ?
         ORDER BY c.created_at DESC LIMIT 8",
        [$tid]
    );

    api_ok(compact('stats', 'sub', 'recent'));
}

// ── LIST USERS ────────────────────────────────────────────────
function action_list(array $user): void {
    auth_role('owner', 'admin');
    $rows = db_rows(
        "SELECT id, full_name, email, role, is_active,
                email_verified_at, last_login_at, created_at
         FROM users WHERE tenant_id = ? ORDER BY role, full_name",
        [$user['tenant_id']]
    );
    api_ok(['data' => $rows]);
}

// ── INVITE ────────────────────────────────────────────────────
function action_invite(array $user): void {
    auth_role('owner', 'admin');

    $b = json_body();
    $errors = validate($b, [
        'email' => 'required|email|max:255',
        'role'  => 'required|in:admin,lawyer',
    ]);

    // Check plan user limit
    $sub = db_row(
        "SELECT p.max_users FROM subscriptions s JOIN plans p ON p.id = s.plan_id
         WHERE s.tenant_id = ? AND s.status IN('active','trialing') ORDER BY s.id DESC LIMIT 1",
        [$user['tenant_id']]
    );
    $cur = db_count("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND is_active = 1", [$user['tenant_id']]);
    if ($sub && $cur >= (int)$sub['max_users']) {
        $errors['email'][] = 'User limit reached for your plan. Please upgrade.';
    }

    $email = strtolower(trim($b['email'] ?? ''));
    if (!$errors) {
        $exists = db_count(
            "SELECT COUNT(*) FROM users WHERE email = ? AND tenant_id = ?",
            [$email, $user['tenant_id']]
        );
        if ($exists) $errors['email'][] = 'This user is already in your workspace.';
    }
    if ($errors) api_error('Validation failed', 422, $errors);

    $token = make_token();
    db_run("DELETE FROM invitations WHERE email = ? AND tenant_id = ?", [$email, $user['tenant_id']]);
    db_insert(
        "INSERT INTO invitations (tenant_id, email, role, token, invited_by, expires_at)
         VALUES (?, ?, ?, ?, ?, ?)",
        [$user['tenant_id'], $email, $b['role'], $token, $user['id'],
         date('Y-m-d H:i:s', strtotime('+7 days'))]
    );

    $link = BASE_URL . '/api/users.php?action=accept&token=' . $token;
    log_info('Invite link', ['email' => $email, 'link' => $link]);

    // Try to send email — silently ignore failures (no mail server on local XAMPP)
    @send_mail($email, 'You have been invited to ContractAI',
        "{$user['full_name']} has invited you to join ContractAI.\n\nAccept here:\n{$link}\n\nThis link expires in 7 days.");

    audit('user.invite', null, null, ['email' => $email]);

    // Always return the invite link so it works without an email server
    api_ok([
        'email'       => $email,
        'invite_link' => $link,
    ], 'Invitation ready for ' . $email);
}

// ── ACCEPT INVITE — GET redirect to SPA ──────────────────────
function invite_redirect(): never {
    $token = trim($_GET['token'] ?? '');
    $inv   = $token ? db_row(
        "SELECT i.*, t.name AS tenant_name
         FROM invitations i JOIN tenants t ON t.id = i.tenant_id
         WHERE i.token = ? AND i.accepted_at IS NULL AND i.expires_at > NOW()",
        [$token]
    ) : null;

    $dest = $inv
        ? BASE_URL . '/index.html#/accept-invite?token=' . urlencode($token)
        : BASE_URL . '/index.html#/verified?status=invalid';

    header('Location: ' . $dest);
    exit;
}

// ── ACCEPT INVITE — POST create account ──────────────────────
function invite_complete(): never {
    $b   = json_body();
    $tok = trim($b['token'] ?? '');

    $inv = db_row(
        "SELECT i.*, t.name AS tenant_name
         FROM invitations i JOIN tenants t ON t.id = i.tenant_id
         WHERE i.token = ? AND i.accepted_at IS NULL AND i.expires_at > NOW()",
        [$tok]
    );
    if (!$inv) api_error('Invalid or expired invitation link', 400);

    $errors = validate($b, [
        'full_name' => 'required|min:2|max:255',
        'password'  => 'required|min:8|max:128|confirmed',
    ]);
    if ($errors) api_error('Validation failed', 422, $errors);

    $userId = db_insert(
        "INSERT INTO users (tenant_id, email, password_hash, full_name, role, email_verified_at, invited_by)
         VALUES (?, ?, ?, ?, ?, NOW(), ?)",
        [$inv['tenant_id'], $inv['email'], hash_pw($b['password']),
         trim($b['full_name']), $inv['role'], $inv['invited_by']]
    );
    db_run("UPDATE invitations SET accepted_at = NOW() WHERE id = ?", [$inv['id']]);
    audit('user.accept_invite', 'user', $userId);
    api_created(['user_id' => $userId], 'Account created. You can now log in.');
}

// ── TOGGLE USER ───────────────────────────────────────────────
function action_toggle(array $user): void {
    auth_role('owner', 'admin');

    $b        = json_body();
    $targetId = (int)($b['id'] ?? $_GET['id'] ?? 0);
    if (!$targetId) api_error('User ID required', 422);
    if ($targetId === (int)$user['id']) api_error('Cannot disable your own account', 400);

    $target = db_row("SELECT * FROM users WHERE id = ? AND tenant_id = ?", [$targetId, $user['tenant_id']]);
    if (!$target) api_error('User not found', 404);
    if ($target['role'] === 'owner' && $user['role'] !== 'owner') api_error('Forbidden', 403);

    $new = $target['is_active'] ? 0 : 1;
    db_run("UPDATE users SET is_active = ? WHERE id = ?", [$new, $targetId]);
    audit('user.' . ($new ? 'enable' : 'disable'), 'user', $targetId);
    api_ok(['id' => $targetId, 'is_active' => (bool)$new], $new ? 'User enabled' : 'User disabled');
}

// ── PROFILE UPDATE ────────────────────────────────────────────
function action_profile(array $user): void {
    $b      = json_body();
    $errors = validate($b, ['full_name' => 'required|min:2|max:255']);

    $changingPw = !empty($b['new_password']);
    if ($changingPw) {
        $cur = db_row("SELECT password_hash FROM users WHERE id = ?", [$user['id']]);
        if (!verify_pw($b['current_password'] ?? '', $cur['password_hash'])) {
            $errors['current_password'][] = 'Current password is incorrect';
        }
        $pwErr = validate($b, ['new_password' => 'required|min:8|max:128|confirmed']);
        $errors = array_merge($errors, $pwErr);
    }
    if ($errors) api_error('Validation failed', 422, $errors);

    db_run("UPDATE users SET full_name = ? WHERE id = ?", [trim($b['full_name']), $user['id']]);
    if ($changingPw && empty($errors['current_password'])) {
        db_run("UPDATE users SET password_hash = ? WHERE id = ?", [hash_pw($b['new_password']), $user['id']]);
        // Revoke all refresh tokens (force re-login everywhere)
        db_run("UPDATE refresh_tokens SET revoked_at = NOW() WHERE user_id = ?", [$user['id']]);
    }
    audit('user.profile_update', 'user', $user['id']);
    api_ok(['full_name' => trim($b['full_name'])], 'Profile updated');
}

// ── SETTINGS GET ──────────────────────────────────────────────
function action_settings_get(array $user): void {
    $tenant = db_row("SELECT * FROM tenants WHERE id = ?", [$user['tenant_id']]);
    $sub    = db_row(
        "SELECT s.*, p.name AS plan_name, p.max_users, p.max_contracts, p.max_ai_calls, p.price_usd
         FROM subscriptions s JOIN plans p ON p.id = s.plan_id
         WHERE s.tenant_id = ? ORDER BY s.id DESC LIMIT 1",
        [$user['tenant_id']]
    );
    $plans  = db_rows("SELECT id, name, billing_cycle, price_usd, max_users, max_contracts, max_ai_calls FROM plans WHERE is_active = 1 ORDER BY price_usd");
    api_ok(compact('tenant', 'sub', 'plans'));
}

// ── SETTINGS SAVE ─────────────────────────────────────────────
function action_settings_save(array $user): void {
    auth_role('owner', 'admin');
    $b = json_body();
    $errors = validate($b, [
        'name'     => 'required|min:2|max:255',
        'language' => 'required|in:en,ar',
    ]);
    if ($errors) api_error('Validation failed', 422, $errors);

    db_run(
        "UPDATE tenants SET name = ?, language = ?, timezone = ?, primary_color = ?, ai_prompt = ? WHERE id = ?",
        [
            trim($b['name']),
            $b['language'],
            trim($b['timezone'] ?? 'Asia/Dubai'),
            trim($b['primary_color'] ?? '#1a3c5e'),
            trim($b['ai_prompt'] ?? '') ?: null,
            $user['tenant_id'],
        ]
    );
    audit('settings.save', 'tenant', $user['tenant_id']);
    api_ok(null, 'Settings saved');
}

// ── LOGO UPLOAD ───────────────────────────────────────────────
function action_upload_logo(array $user): void {
    auth_role('owner', 'admin');
    if (empty($_FILES['logo']['name'])) api_error('No file received', 422);

    $name = save_upload($_FILES['logo'], UPLOADS_PATH . '/logos',
        ['image/jpeg','image/png','image/webp','image/gif'], 2 * 1024 * 1024);

    if (!$name) api_error('Upload failed. Use JPG, PNG or WebP under 2 MB.', 422);

    db_run("UPDATE tenants SET logo_path = ? WHERE id = ?", ['logos/' . $name, $user['tenant_id']]);
    api_ok(['url' => BASE_URL . '/uploads/logos/' . $name, 'path' => 'logos/' . $name], 'Logo uploaded');
}

function save_upload(array $file, string $dir, array $allowedTypes = ['image/jpeg','image/png','image/webp'], int $maxBytes = 2097152): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > $maxBytes) return null;
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowedTypes, true)) return null;
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = rtrim($dir, '/') . '/' . $name;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $dest)) return null;
    return $name;
}
