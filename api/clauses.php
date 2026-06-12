<?php
/**
 * ContractAI – Clause Library API
 *
 * GET  /api/clauses.php              → list (paginated, filterable)
 * GET  /api/clauses.php?id=N         → single clause
 * POST /api/clauses.php              → create  [owner/admin]
 * POST /api/clauses.php?id=N  _method=PUT    → update [owner/admin]
 * POST /api/clauses.php?id=N  _method=DELETE → soft-delete [owner/admin]
 */
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';

$user = auth_required();
$tid  = $user['tenant_id'];
$id   = (int)($_GET['id'] ?? 0);
$m    = resolve_method();

match(true) {
    $m === 'GET'    && $id === 0 => clause_list($tid),
    $m === 'GET'    && $id > 0  => clause_show($tid, $id),
    $m === 'POST'   && $id === 0 => clause_create($user),
    $m === 'PUT'    && $id > 0  => clause_update($user, $id),
    $m === 'DELETE' && $id > 0  => clause_delete($user, $id),
    default                      => api_error('Endpoint not found', 404),
};

// ── LIST ──────────────────────────────────────────────────────
function clause_list(int $tid): void {
    $page    = max(1, (int)($_GET['page']     ?? 1));
    $perPage = min(200, (int)($_GET['per_page'] ?? 100));
    $cat     = trim($_GET['category'] ?? '');
    $q       = trim($_GET['q']        ?? '');
    $ids     = trim($_GET['ids']      ?? ''); // comma-separated clause_ids for bulk fetch

    $where  = 'c.tenant_id = ? AND c.is_active = 1';
    $params = [$tid];

    if ($cat) { $where .= ' AND c.category = ?';             $params[] = $cat; }
    if ($q)   { $where .= ' AND (c.title LIKE ? OR c.tags LIKE ? OR c.clause_uid LIKE ?)';
                $like = "%{$q}%"; $params = array_merge($params, [$like, $like, $like]); }
    if ($ids) {
        $idArr = array_filter(array_map('trim', explode(',', $ids)));
        if ($idArr) {
            $ph     = implode(',', array_fill(0, count($idArr), '?'));
            $where .= " AND c.clause_uid IN ({$ph})";
            $params = array_merge($params, $idArr);
        }
    }

    $sql = "SELECT c.id, c.clause_uid, c.title, c.title_ar, c.category,
                   c.tags, c.version, c.is_active, c.created_at,
                   u.full_name AS created_by_name
            FROM clauses c
            JOIN users u ON u.id = c.created_by
            WHERE {$where}
            ORDER BY c.category, c.title";

    [$rows, $total] = db_paginate($sql, $params, $page, $perPage);

    // Categories for filter UI
    $cats = db_rows(
        "SELECT DISTINCT category FROM clauses WHERE tenant_id = ? AND is_active = 1 ORDER BY category",
        [$tid]
    );

    api_ok([
        'data'       => $rows,
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
        'categories' => array_column($cats, 'category'),
    ]);
}

// ── SHOW ──────────────────────────────────────────────────────
function clause_show(int $tid, int $id): void {
    $row = db_row(
        "SELECT * FROM clauses WHERE id = ? AND tenant_id = ? AND is_active = 1",
        [$id, $tid]
    );
    if (!$row) api_error('Clause not found', 404);
    api_ok($row);
}

// ── CREATE ────────────────────────────────────────────────────
function clause_create(array $user): void {
    auth_role('owner', 'admin');
    $b = json_body();
    $errors = validate($b, [
        'title'     => 'required|min:2|max:500',
        'category'  => 'required|max:100',
        'body_html' => 'required|min:5',
    ]);
    if ($errors) api_error('Validation failed', 422, $errors);

    $uid = clause_next_uid($user['tenant_id']);

    $id = db_insert(
        "INSERT INTO clauses
         (tenant_id, clause_uid, title, title_ar, category,
          body_html, body_html_ar, tags, created_by)
         VALUES (?,?,?,?,?,?,?,?,?)",
        [
            $user['tenant_id'],
            $uid,
            trim($b['title']),
            trim($b['title_ar']     ?? '') ?: null,
            trim($b['category']),
            sanitize_html(trim($b['body_html'])),
            isset($b['body_html_ar']) ? sanitize_html(trim($b['body_html_ar'])) : null,
            trim($b['tags']         ?? '') ?: null,
            $user['id'],
        ]
    );

    audit('clause.create', 'clause', $id);
    api_created(db_row("SELECT * FROM clauses WHERE id = ?", [$id]), 'Clause created');
}

// ── UPDATE ────────────────────────────────────────────────────
function clause_update(array $user, int $id): void {
    auth_role('owner', 'admin');
    tenant_guard(db_row("SELECT id, tenant_id FROM clauses WHERE id = ? AND is_active = 1", [$id]));

    $before = db_row("SELECT title, title_ar, category, body_html, body_html_ar, tags FROM clauses WHERE id = ?", [$id]);

    $b = json_body();
    $errors = validate($b, [
        'title'     => 'required|min:2|max:500',
        'category'  => 'required|max:100',
        'body_html' => 'required|min:5',
    ]);
    if ($errors) api_error('Validation failed', 422, $errors);

    db_run(
        "UPDATE clauses SET
         title = ?, title_ar = ?, category = ?,
         body_html = ?, body_html_ar = ?, tags = ?,
         version = version + 1
         WHERE id = ? AND tenant_id = ?",
        [
            trim($b['title']),
            trim($b['title_ar']     ?? '') ?: null,
            trim($b['category']),
            sanitize_html(trim($b['body_html'])),
            isset($b['body_html_ar']) ? sanitize_html(trim($b['body_html_ar'])) : null,
            trim($b['tags']         ?? '') ?: null,
            $id, $user['tenant_id'],
        ]
    );

    $after = db_row("SELECT title, title_ar, category, body_html, body_html_ar, tags FROM clauses WHERE id = ?", [$id]);
    audit_diff('clause.update', 'clause', $id, $before, $after);
    api_ok(db_row("SELECT * FROM clauses WHERE id = ?", [$id]), 'Clause updated');
}

// ── DELETE ────────────────────────────────────────────────────
function clause_delete(array $user, int $id): void {
    auth_role('owner', 'admin');
    tenant_guard(db_row("SELECT id, tenant_id FROM clauses WHERE id = ? AND is_active = 1", [$id]));

    // Check if any active templates use this clause
    $used = db_val(
        "SELECT COUNT(*) FROM template_clauses tc
         JOIN templates t ON t.id = tc.template_id
         WHERE tc.clause_id = ? AND t.tenant_id = ? AND t.is_active = 1",
        [$id, $user['tenant_id']]
    );
    if ($used > 0) {
        api_error("This clause is used in {$used} template(s). Remove it from those templates first.", 409);
    }

    db_run("UPDATE clauses SET is_active = 0 WHERE id = ? AND tenant_id = ?", [$id, $user['tenant_id']]);
    audit('clause.delete', 'clause', $id);
    api_ok(null, 'Clause deleted');
}

// ── Helpers ────────────────────────────────────────────────────
function clause_next_uid(int $tid): string {
    // Find highest numeric suffix for this tenant e.g. CL-0042 → next is CL-0043
    $last = db_val(
        "SELECT clause_uid FROM clauses WHERE tenant_id = ?
         ORDER BY id DESC LIMIT 1",
        [$tid]
    );
    $n = 1;
    if ($last && preg_match('/CL-(\d+)$/i', (string)$last, $m)) {
        $n = (int)$m[1] + 1;
    }
    return 'CL-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}
