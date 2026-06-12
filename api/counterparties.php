<?php
/**
 * ContractAI – Counterparties API
 *
 * GET  /api/counterparties.php           → list
 * GET  /api/counterparties.php?id=N      → single (decrypted)
 * POST /api/counterparties.php           → create
 * POST /api/counterparties.php?id=N  _method=PUT    → update
 * POST /api/counterparties.php?id=N  _method=DELETE → delete
 */
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';

$user = auth_required();
$tid  = $user['tenant_id'];
$id   = (int)($_GET['id'] ?? 0);
$m    = resolve_method();

match(true) {
    $m === 'GET'    && $id === 0 => cp_list($tid),
    $m === 'GET'    && $id > 0  => cp_show($tid, $id),
    $m === 'POST'   && $id === 0 => cp_create($user),
    $m === 'PUT'    && $id > 0  => cp_update($user, $id),
    $m === 'DELETE' && $id > 0  => cp_delete($user, $id),
    default                      => api_error('Endpoint not found', 404),
};

// ── LIST ──────────────────────────────────────────────────────
function cp_list(int $tid): void {
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, (int)($_GET['per_page'] ?? 20));
    $q       = trim($_GET['q'] ?? '');

    $sql    = "SELECT id, company_name, company_name_ar, email, phone,
                      country, signatory_title, created_at
               FROM counterparties
               WHERE tenant_id = ? AND is_active = 1";
    $params = [$tid];

    if ($q) {
        $sql    .= " AND (company_name LIKE ? OR company_name_ar LIKE ? OR email LIKE ?)";
        $like    = "%{$q}%";
        $params  = array_merge($params, [$like, $like, $like]);
    }
    $sql .= " ORDER BY company_name";

    [$rows, $total] = db_paginate($sql, $params, $page, $perPage);
    api_ok(['data' => $rows, 'pagination' => pagination_meta($page, $perPage, $total)]);
}

// ── SHOW ──────────────────────────────────────────────────────
function cp_show(int $tid, int $id): void {
    $row = db_row(
        "SELECT * FROM counterparties WHERE id = ? AND tenant_id = ? AND is_active = 1",
        [$id, $tid]
    );
    if (!$row) api_error('Counterparty not found', 404);
    api_ok(cp_decrypt($row));
}

// ── CREATE ────────────────────────────────────────────────────
function cp_create(array $user): void {
    $b = json_body();
    $errors = validate($b, [
        'company_name' => 'required|min:2|max:255',
        'country'      => 'required|max:10',
    ]);
    if ($errors) api_error('Validation failed', 422, $errors);

    $id = db_insert(
        "INSERT INTO counterparties
         (tenant_id, company_name, company_name_ar,
          reg_number_enc, tax_number_enc,
          address,
          signatory_name_enc, signatory_title,
          email, phone, country, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
        [
            $user['tenant_id'],
            trim($b['company_name']),
            trim($b['company_name_ar'] ?? '') ?: null,
            enc($b['reg_number']      ?? null),
            enc($b['tax_number']      ?? null),
            trim($b['address']        ?? '') ?: null,
            enc($b['signatory_name']  ?? null),
            trim($b['signatory_title']    ?? '') ?: null,
            trim($b['email']          ?? '') ?: null,
            trim($b['phone']          ?? '') ?: null,
            trim($b['country']        ?? 'AE'),
            $user['id'],
        ]
    );

    audit('counterparty.create', 'counterparty', $id);
    $row = db_row("SELECT * FROM counterparties WHERE id = ?", [$id]);
    api_created(cp_decrypt($row), 'Counterparty created');
}

// ── UPDATE ────────────────────────────────────────────────────
function cp_update(array $user, int $id): void {
    tenant_guard(db_row("SELECT id, tenant_id FROM counterparties WHERE id = ? AND is_active = 1", [$id]));

    // Decrypted snapshot before update — encrypted columns are never stored raw in history
    $before = cp_decrypt(db_row(
        "SELECT company_name, company_name_ar, address, signatory_title, email, phone, country,
                reg_number_enc, tax_number_enc, signatory_name_enc
         FROM counterparties WHERE id = ?", [$id]
    ));
    unset($before['reg_number_enc'], $before['tax_number_enc'], $before['signatory_name_enc']);

    $b = json_body();
    $errors = validate($b, [
        'company_name' => 'required|min:2|max:255',
        'country'      => 'required|max:10',
    ]);
    if ($errors) api_error('Validation failed', 422, $errors);

    db_run(
        "UPDATE counterparties SET
         company_name = ?, company_name_ar = ?,
         reg_number_enc = ?, tax_number_enc = ?,
         address = ?,
         signatory_name_enc = ?, signatory_title = ?,
         email = ?, phone = ?, country = ?
         WHERE id = ? AND tenant_id = ?",
        [
            trim($b['company_name']),
            trim($b['company_name_ar'] ?? '') ?: null,
            enc($b['reg_number']      ?? null),
            enc($b['tax_number']      ?? null),
            trim($b['address']        ?? '') ?: null,
            enc($b['signatory_name']  ?? null),
            trim($b['signatory_title']    ?? '') ?: null,
            trim($b['email']          ?? '') ?: null,
            trim($b['phone']          ?? '') ?: null,
            trim($b['country']        ?? 'AE'),
            $id, $user['tenant_id'],
        ]
    );

    $after = cp_decrypt(db_row(
        "SELECT company_name, company_name_ar, address, signatory_title, email, phone, country,
                reg_number_enc, tax_number_enc, signatory_name_enc
         FROM counterparties WHERE id = ?", [$id]
    ));
    unset($after['reg_number_enc'], $after['tax_number_enc'], $after['signatory_name_enc']);

    audit_diff('counterparty.update', 'counterparty', $id, $before, $after);
    api_ok(cp_decrypt(db_row("SELECT * FROM counterparties WHERE id = ?", [$id])), 'Updated');
}

// ── DELETE (soft) ─────────────────────────────────────────────
function cp_delete(array $user, int $id): void {
    tenant_guard(db_row("SELECT id, tenant_id FROM counterparties WHERE id = ? AND is_active = 1", [$id]));
    db_run("UPDATE counterparties SET is_active = 0 WHERE id = ? AND tenant_id = ?", [$id, $user['tenant_id']]);
    audit('counterparty.delete', 'counterparty', $id);
    api_ok(null, 'Deleted');
}

// ── Decrypt helper ────────────────────────────────────────────
function cp_decrypt(array $row): array {
    $row['reg_number']     = dec($row['reg_number_enc']     ?? '');
    $row['tax_number']     = dec($row['tax_number_enc']     ?? '');
    $row['signatory_name'] = dec($row['signatory_name_enc'] ?? '');
    unset($row['reg_number_enc'], $row['tax_number_enc'], $row['signatory_name_enc']);
    return $row;
}
