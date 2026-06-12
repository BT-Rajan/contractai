<?php
/**
 * ContractAI – Entity History API
 *
 * GET /api/history.php?entity=contract&id=5
 *   Returns audit_log entries for the given entity, newest first,
 *   with field-level before/after diffs where available.
 *
 * Valid entity types: contract, template, clause, counterparty, tenant
 */
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';

$user = auth_required();
$tid  = $user['tenant_id'];

$entity = trim($_GET['entity'] ?? '');
$id     = (int)($_GET['id'] ?? 0);

$allowed = ['contract', 'template', 'clause', 'counterparty', 'tenant'];
if (!in_array($entity, $allowed, true)) {
    api_error('Invalid entity type. Must be one of: ' . implode(', ', $allowed), 422);
}
if ($id <= 0) api_error('Entity id required', 422);

// 'tenant' history (settings) is owner/admin only — contains org-wide config
if ($entity === 'tenant') {
    auth_role('owner', 'admin');
    if ($id !== $tid) api_error('Not found', 404);
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(50, (int)($_GET['per_page'] ?? 20));

[$rows, $total] = db_paginate(
    "SELECT a.id, a.action, a.before_json, a.after_json, a.meta,
            a.created_at, u.full_name AS user_name, u.email AS user_email
     FROM audit_log a
     LEFT JOIN users u ON u.id = a.user_id
     WHERE a.tenant_id = ? AND a.entity_type = ? AND a.entity_id = ?
     ORDER BY a.created_at DESC",
    [$tid, $entity, $id],
    $page, $perPage
);

foreach ($rows as &$r) {
    $r['before'] = $r['before_json'] ? json_decode($r['before_json'], true) : null;
    $r['after']  = $r['after_json']  ? json_decode($r['after_json'],  true) : null;
    $r['meta']   = $r['meta']        ? json_decode($r['meta'],        true) : null;
    unset($r['before_json'], $r['after_json']);
}

api_ok([
    'data'     => $rows,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
]);
