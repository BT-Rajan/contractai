<?php
declare(strict_types=1);

function auth_required(): array {
    $payload = jwt_from_request();
    if (!$payload) json_err('Unauthenticated', 401);

    $user = db_row(
        "SELECT u.id,u.tenant_id,u.email,u.full_name,u.role,u.is_active
         FROM users u WHERE u.id=? AND u.tenant_id=? AND u.is_active=1",
        [(int)$payload['sub'], (int)$payload['tenant_id']]
    );
    if (!$user) json_err('User not found or inactive', 401);

    $GLOBALS['_auth'] = $user;
    return $user;
}

function auth_role(string ...$roles): array {
    $user = auth_required();
    if (!in_array($user['role'], $roles, true)) json_err('Forbidden', 403);
    return $user;
}

function tenant_guard(?array $row, string $col = 'tenant_id'): array {
    if (!$row || (int)$row[$col] !== current_tenant_id()) json_err('Not found', 404);
    return $row;
}
