<?php
declare(strict_types=1);

// ── JSON response ─────────────────────────────────────────────

function json_ok(mixed $data = null, string $message = 'OK', int $code = 200): never {
    $buf = ob_get_clean(); // discard any PHP warnings/notices
    if ($buf && APP_DEBUG) error_log('[ContractAI] Suppressed output: ' . substr($buf, 0, 300));
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_err(string $message, int $code = 400, array $errors = []): never {
    $buf = ob_get_clean(); // discard any PHP warnings/notices
    if ($buf && APP_DEBUG) error_log('[ContractAI] Suppressed output: ' . substr($buf, 0, 300));
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $r = ['success' => false, 'message' => $message];
    if ($errors) $r['errors'] = $errors;
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

// Aliases used throughout
function api_ok(mixed $data = null, string $message = 'OK', int $code = 200): never  { json_ok($data, $message, $code); }
function api_error(string $msg, int $code = 400, array $err = []): never              { json_err($msg, $code, $err); }
function api_created(mixed $data, string $msg = 'Created'): never                     { json_ok($data, $msg, 201); }

// ── JWT HS256 ─────────────────────────────────────────────────

function b64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function b64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
}

function jwt_encode(array $payload): string {
    $h = b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $p = b64url(json_encode($payload));
    $s = b64url(hash_hmac('sha256', "{$h}.{$p}", JWT_SECRET, true));
    return "{$h}.{$p}.{$s}";
}

function jwt_decode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    $expected = b64url(hash_hmac('sha256', "{$h}.{$p}", JWT_SECRET, true));
    if (!hash_equals($expected, $s)) return null;
    $data = json_decode(b64url_decode($p), true);
    if (!is_array($data)) return null;
    if (isset($data['exp']) && $data['exp'] < time()) return null;
    return $data;
}

function jwt_make(array $user): array {
    $access = jwt_encode([
        'sub'       => (int)$user['id'],
        'tenant_id' => (int)$user['tenant_id'],
        'role'      => $user['role'],
        'iat'       => time(),
        'exp'       => time() + JWT_TTL,
    ]);
    $raw  = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);
    db_insert(
        "INSERT INTO refresh_tokens (user_id, token_hash, expires_at, ip, ua) VALUES (?,?,?,?,?)",
        [(int)$user['id'], $hash, date('Y-m-d H:i:s', time() + JWT_REFRESH), ip(), ua()]
    );
    return ['access_token' => $access, 'refresh_token' => $raw, 'expires_in' => JWT_TTL];
}

function jwt_from_request(): ?array {
    // 1. Standard — mod_php sets this directly
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    // 2. CGI/FastCGI: Apache may pass it as REDIRECT_HTTP_AUTHORIZATION
    if (!$auth) $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    // 3. apache_request_headers() — catches mod_rewrite E= passthrough
    if (!$auth && function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        $auth = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
    }

    if (str_starts_with($auth, 'Bearer ')) return jwt_decode(substr($auth, 7));

    // 4. Cookie fallback (set by auth.php on login for same-origin requests)
    $cookie = $_COOKIE['access_token'] ?? '';
    if ($cookie) return jwt_decode($cookie);

    return null;
}

// ── Password ──────────────────────────────────────────────────

function hash_pw(string $pw): string { return password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]); }
function verify_pw(string $pw, string $hash): bool { return password_verify($pw, $hash); }

// ── Request helpers ───────────────────────────────────────────

function method(): string { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); }

/**
 * Resolve the effective HTTP method.
 * The frontend POSTs with _method=PUT or _method=DELETE in the JSON body
 * for method-override (browsers and CORS don't freely send PUT/DELETE).
 * This function honours that override so routing works correctly.
 */
function resolve_method(): string {
    $real = method(); // GET, POST, PUT, DELETE …
    if ($real !== 'POST') return $real;
    // Check JSON body for _method override
    $body = json_body();
    $override = strtoupper(trim($body['_method'] ?? ''));
    return in_array($override, ['PUT', 'PATCH', 'DELETE'], true) ? $override : $real;
}
function ip(): string     { return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'; }
function ua(): string     { return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500); }

function json_body(): array {
    static $body = null;
    if ($body !== null) return $body;
    $ct  = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    $raw = str_contains($ct, 'multipart') ? '' : (string)file_get_contents('php://input');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $body = $decoded;
            return $body;
        }
    }
    $body = $_POST;
    return $body;
}

// ── Rate limiting ─────────────────────────────────────────────

function rate_limit(string $key, int $max, int $window): void {
    $now = time();
    $windowStart = date('Y-m-d H:i:s', $now);

    // Atomic upsert: insert new key or increment existing within window.
    // Uses INSERT ... ON DUPLICATE KEY to avoid a read-then-write race.
    db_run(
        "INSERT INTO rate_limits (`key`, hits, window_start)
         VALUES (?, 1, ?)
         ON DUPLICATE KEY UPDATE
           hits         = IF(UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(window_start) > ?, 1, hits + 1),
           window_start = IF(UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(window_start) > ?, VALUES(window_start), window_start)",
        [$key, $windowStart, $window, $window]
    );

    $row = db_row("SELECT hits FROM rate_limits WHERE `key` = ?", [$key]);
    if ($row && (int)$row['hits'] > $max) {
        json_err('Too many requests. Please wait and try again.', 429);
    }
}

// ── Validation ────────────────────────────────────────────────

function validate(array $data, array $rules): array {
    $errors = [];
    foreach ($rules as $field => $rule) {
        $value = $data[$field] ?? null;
        $label = ucwords(str_replace('_', ' ', $field));
        foreach (explode('|', $rule) as $r) {
            [$name, $param] = array_pad(explode(':', $r, 2), 2, null);
            $v = (string)($value ?? '');
            switch ($name) {
                case 'required':
                    if ($value === null || $value === '') $errors[$field][] = "{$label} is required";
                    break;
                case 'email':
                    if ($v && !filter_var($v, FILTER_VALIDATE_EMAIL)) $errors[$field][] = "Invalid email address";
                    break;
                case 'min':
                    if ($v !== '' && mb_strlen($v) < (int)$param) $errors[$field][] = "{$label} must be at least {$param} characters";
                    break;
                case 'max':
                    if (mb_strlen($v) > (int)$param) $errors[$field][] = "{$label} must not exceed {$param} characters";
                    break;
                case 'in':
                    if ($v !== '' && !in_array($v, explode(',', (string)$param), true)) $errors[$field][] = "{$label} is invalid";
                    break;
                case 'numeric':
                    if ($v !== '' && !is_numeric($v)) $errors[$field][] = "{$label} must be a number";
                    break;
                case 'confirmed':
                    $confirm = (string)($data[$field . '_confirmation'] ?? '');
                    if ($v !== $confirm) $errors[$field][] = "{$label} confirmation does not match";
                    break;
            }
        }
    }
    return $errors;
}

// ── Current user ──────────────────────────────────────────────

function current_user_id(): ?int   { return $GLOBALS['_auth']['id']        ?? null; }
function current_tenant_id(): ?int { return $GLOBALS['_auth']['tenant_id']  ?? null; }
function current_role(): string    { return $GLOBALS['_auth']['role']        ?? ''; }
function current_user(): ?array    { return $GLOBALS['_auth']               ?? null; }

// ── Audit log ─────────────────────────────────────────────────

function audit(string $action, ?string $entity = null, ?int $entityId = null, array $meta = []): void {
    try {
        db_insert(
            "INSERT INTO audit_log(tenant_id,user_id,action,entity_type,entity_id,meta,ip) VALUES(?,?,?,?,?,?,?)",
            [current_tenant_id(), current_user_id(), $action, $entity, $entityId, $meta ? json_encode($meta) : null, ip()]
        );
    } catch (Throwable) {}
}

/**
 * Record an audit entry with a field-level before/after diff.
 * Only fields that actually changed are stored — keeps history compact
 * and makes the "what changed" view trivial to render.
 *
 * $before / $after are associative arrays of the relevant DB row
 * (or a subset of fields). Keys present in either array are compared;
 * unchanged values are dropped from both snapshots.
 *
 * Sensitive fields (passwords, tokens, *_enc) are never stored — pass
 * already-decrypted values if needed, or omit them entirely.
 */
function audit_diff(string $action, string $entity, int $entityId, array $before, array $after): void {
    $skip = ['password_hash','token','token_hash','reg_number_enc','tax_number_enc','signatory_name_enc'];

    $changedBefore = [];
    $changedAfter  = [];
    $keys = array_unique(array_merge(array_keys($before), array_keys($after)));

    foreach ($keys as $k) {
        if (in_array($k, $skip, true)) continue;
        $b = $before[$k] ?? null;
        $a = $after[$k]  ?? null;
        // Normalise for comparison (e.g. "1" vs 1, null vs "")
        $bn = is_scalar($b) ? (string)$b : json_encode($b);
        $an = is_scalar($a) ? (string)$a : json_encode($a);
        if ($bn === $an) continue;
        $changedBefore[$k] = $b;
        $changedAfter[$k]  = $a;
    }

    if (empty($changedBefore) && empty($changedAfter)) return; // no real change — skip noise

    try {
        db_insert(
            "INSERT INTO audit_log(tenant_id,user_id,action,entity_type,entity_id,before_json,after_json,ip)
             VALUES(?,?,?,?,?,?,?,?)",
            [
                current_tenant_id(), current_user_id(), $action, $entity, $entityId,
                json_encode($changedBefore, JSON_UNESCAPED_UNICODE),
                json_encode($changedAfter,  JSON_UNESCAPED_UNICODE),
                ip(),
            ]
        );
    } catch (Throwable) {}
}

// ── Token / slug ──────────────────────────────────────────────

function make_token(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }

function make_slug(string $name, string $table): string {
    $slug = trim(preg_replace('/[^a-zA-Z0-9]+/', '-', strtolower($name)), '-');
    $base = $slug; $i = 1;
    while (db_count("SELECT COUNT(*) FROM `{$table}` WHERE slug=?", [$slug])) {
        $slug = "{$base}-{$i}"; $i++;
    }
    return $slug;
}

// ── Logging ───────────────────────────────────────────────────

function log_info(string $msg, array $ctx = []): void {
    if (!APP_DEBUG) return;
    $line = date('Y-m-d H:i:s') . ' INFO  ' . $msg;
    if ($ctx) $line .= ' ' . json_encode($ctx);
    if (!is_dir(LOGS_PATH)) @mkdir(LOGS_PATH, 0755, true);
    @file_put_contents(LOGS_PATH . '/app.log', $line . PHP_EOL, FILE_APPEND);
}

function log_error(string $msg, array $ctx = []): void {
    $line = date('Y-m-d H:i:s') . ' ERROR ' . $msg;
    if ($ctx) $line .= ' ' . json_encode($ctx);
    if (!is_dir(LOGS_PATH)) @mkdir(LOGS_PATH, 0755, true);
    @file_put_contents(LOGS_PATH . '/app.log', $line . PHP_EOL, FILE_APPEND);
}

// ── Rate limiting constants ───────────────────────────────────

if (!defined('RATE_AI')) {
    define('RATE_AI', ['max' => 20, 'window' => 60]); // 20 AI calls per 60 seconds per tenant
}

// ── HTML sanitiser ────────────────────────────────────────────

/**
 * Light sanitiser for AI-generated HTML contract output.
 * Strips <script>, <style>, <iframe>, event handlers,
 * javascript:/data: URIs, and SVG-based XSS vectors while
 * keeping all structural/semantic contract tags.
 */
function sanitize_html(string $html): string {
    if ($html === '') return '';
    // Remove dangerous tags entirely (with content)
    $html = preg_replace('#<(script|style|iframe|object|embed|form)[^>]*>.*?</\1>#is', '', $html);
    // Remove standalone dangerous/script-capable tags
    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|meta|link|base)[^>]*/?>#i', '', $html);
    // Strip on* event attributes (onclick, onload, onerror, etc.)
    $html = preg_replace('/\s+on\w+\s*=\s*(["\'`])[^"\'`]*\1/i', '', $html);
    // Strip javascript: and data: URIs in href/src/action/formaction
    $html = preg_replace('/(href|src|action|formaction)\s*=\s*(["\'`])\s*(javascript|data|vbscript):[^"\'`]*\2/i', '', $html);
    // Strip CSS expression() which can execute JS in old IE
    $html = preg_replace('/expression\s*\([^)]*\)/i', '', $html);
    // Remove SVG script-capable elements
    $html = preg_replace('#<(foreignObject|use|animate)[^>]*>.*?</\1>#is', '', $html);
    $html = preg_replace('#<(foreignObject|use|animate)[^>]*/?>#i', '', $html);
    return $html;
}

// ── Misc ──────────────────────────────────────────────────────

function e(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
function input(string $key, string $from = 'post', mixed $default = ''): string {
    $src = strtolower($from) === 'get' ? $_GET : $_POST;
    return trim((string)($src[$key] ?? $default));
}

function send_mail(string $to, string $subject, string $body): void {
    $headers = "From: noreply@contractai.com\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    @mail($to, $subject, $body, $headers);
}

function pagination_meta(int $page, int $perPage, int $total): array {
    return [
        'page'      => $page,
        'per_page'  => $perPage,
        'total'     => $total,
        'last_page' => (int)ceil($total / max(1, $perPage)),
    ];
}

function enc(?string $v): ?string {
    if (!$v) return null;
    $key = substr(hash('sha256', ENCRYPT_KEY), 0, 32);
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($v, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

function dec(?string $v): string {
    if (!$v) return '';
    $key = substr(hash('sha256', ENCRYPT_KEY), 0, 32);
    $raw = base64_decode($v);
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    return (string)openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
}

function quota_check(int $tenantId, string $type): bool {
    $sub = db_row(
        "SELECT s.contracts_used, s.ai_calls_used,
                p.max_contracts + COALESCE(s.max_contracts_bonus,0) AS max_contracts,
                p.max_ai_calls  + COALESCE(s.max_ai_calls_bonus,0)  AS max_ai_calls
         FROM subscriptions s JOIN plans p ON p.id=s.plan_id
         WHERE s.tenant_id=? AND s.status IN('active','trialing') ORDER BY s.id DESC LIMIT 1",
        [$tenantId]
    );
    if (!$sub) return false;
    return match($type) {
        'contract' => $sub['contracts_used'] < $sub['max_contracts'],
        'ai'       => $sub['ai_calls_used']  < $sub['max_ai_calls'],
        default    => false,
    };
}

function quota_increment(int $tenantId, string $type): void {
    $col = $type === 'contract' ? 'contracts_used' : 'ai_calls_used';
    db_run("UPDATE subscriptions SET {$col}={$col}+1 WHERE tenant_id=? AND status IN('active','trialing')", [$tenantId]);
}
