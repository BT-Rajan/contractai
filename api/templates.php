<?php
/**
 * ContractAI – Templates API
 *
 * GET  /api/templates.php           → list (with categories)
 * GET  /api/templates.php?id=N      → single (includes parsed schema)
 * POST /api/templates.php           → create  [owner/admin]
 * POST /api/templates.php?id=N  _method=PUT    → update [owner/admin]
 * POST /api/templates.php?id=N  _method=DELETE → delete [owner/admin]
 */
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';

$user = auth_required();
$tid  = $user['tenant_id'];
$id   = (int)($_GET['id'] ?? 0);
$m    = resolve_method();

match(true) {
    $m === 'GET'    && $id === 0 => tpl_list($tid),
    $m === 'GET'    && $id > 0  => tpl_show($tid, $id),
    $m === 'POST'   && $id === 0 => tpl_create($user),
    $m === 'PUT'    && $id > 0  => tpl_update($user, $id),
    $m === 'DELETE' && $id > 0  => tpl_delete($user, $id),
    default                      => api_error('Endpoint not found', 404),
};

// ── LIST ──────────────────────────────────────────────────────
function tpl_list(int $tid): void {
    $cat  = trim($_GET['category'] ?? '');
    $lang = trim($_GET['language'] ?? '');

    $sql    = "SELECT t.id, t.name, t.name_ar, t.category, t.language,
                      t.version, t.is_active, t.created_at,
                      u.full_name AS created_by_name
               FROM templates t
               JOIN users u ON u.id = t.created_by
               WHERE t.tenant_id = ? AND t.is_active = 1";
    $params = [$tid];

    if ($cat)  { $sql .= " AND t.category = ?";  $params[] = $cat; }
    if ($lang) { $sql .= " AND t.language = ?";  $params[] = $lang; }
    $sql .= " ORDER BY t.category, t.name";

    $rows = db_rows($sql, $params);
    $cats = db_rows(
        "SELECT DISTINCT category FROM templates WHERE tenant_id = ? AND is_active = 1 ORDER BY category",
        [$tid]
    );

    api_ok([
        'data'       => $rows,
        'categories' => array_column($cats, 'category'),
    ]);
}

// ── SHOW ──────────────────────────────────────────────────────
function tpl_show(int $tid, int $id): void {
    $row = db_row(
        "SELECT * FROM templates WHERE id = ? AND tenant_id = ? AND is_active = 1",
        [$id, $tid]
    );
    if (!$row) api_error('Template not found', 404);
    $row['questionnaire_schema'] = json_decode($row['questionnaire_schema'] ?? '{}', true) ?? [];
    api_ok($row);
}

// ── CREATE ────────────────────────────────────────────────────
function tpl_create(array $user): void {
    auth_role('owner', 'admin');
    $b = json_body();
    $errors = validate($b, [
        'name'          => 'required|min:2|max:255',
        'category'      => 'required|max:100',
        'language'      => 'required|in:en,ar,bilingual',
        'contract_body' => 'required|min:20',
    ]);
    if ($errors) api_error('Validation failed', 422, $errors);

    $schema = tpl_encode_schema($b['questionnaire_schema'] ?? '{}');

    $id = db_insert(
        "INSERT INTO templates
         (tenant_id, name, name_ar, category, language,
          questionnaire_schema, contract_body, ai_prompt, created_by)
         VALUES (?,?,?,?,?,?,?,?,?)",
        [
            $user['tenant_id'],
            trim($b['name']),
            trim($b['name_ar'] ?? '') ?: null,
            trim($b['category']),
            $b['language'],
            $schema,
            trim($b['contract_body']),
            trim($b['ai_prompt'] ?? '') ?: null,
            $user['id'],
        ]
    );

    audit('template.create', 'template', $id);
    $row = db_row("SELECT * FROM templates WHERE id = ?", [$id]);
    $row['questionnaire_schema'] = json_decode($row['questionnaire_schema'], true) ?? [];
    api_created($row, 'Template created');
}

// ── UPDATE ────────────────────────────────────────────────────
function tpl_update(array $user, int $id): void {
    auth_role('owner', 'admin');
    tenant_guard(db_row("SELECT id, tenant_id FROM templates WHERE id = ? AND is_active = 1", [$id]));

    $b = json_body();
    $errors = validate($b, [
        'name'          => 'required|min:2|max:255',
        'category'      => 'required|max:100',
        'language'      => 'required|in:en,ar,bilingual',
        'contract_body' => 'required|min:20',
    ]);
    if ($errors) api_error('Validation failed', 422, $errors);

    $schema = tpl_encode_schema($b['questionnaire_schema'] ?? '{}');

    db_run(
        "UPDATE templates SET
         name = ?, name_ar = ?, category = ?, language = ?,
         questionnaire_schema = ?, contract_body = ?, ai_prompt = ?,
         version = version + 1
         WHERE id = ? AND tenant_id = ?",
        [
            trim($b['name']),
            trim($b['name_ar'] ?? '') ?: null,
            trim($b['category']),
            $b['language'],
            $schema,
            trim($b['contract_body']),
            trim($b['ai_prompt'] ?? '') ?: null,
            $id, $user['tenant_id'],
        ]
    );

    audit('template.update', 'template', $id);
    $row = db_row("SELECT * FROM templates WHERE id = ?", [$id]);
    $row['questionnaire_schema'] = json_decode($row['questionnaire_schema'], true) ?? [];
    api_ok($row, 'Template updated');
}

// ── DELETE ────────────────────────────────────────────────────
function tpl_delete(array $user, int $id): void {
    auth_role('owner', 'admin');
    tenant_guard(db_row("SELECT id, tenant_id FROM templates WHERE id = ? AND is_active = 1", [$id]));
    db_run("UPDATE templates SET is_active = 0 WHERE id = ? AND tenant_id = ?", [$id, $user['tenant_id']]);
    audit('template.delete', 'template', $id);
    api_ok(null, 'Template deleted');
}

// ── Schema encode helper ──────────────────────────────────────
function tpl_encode_schema(mixed $input): string {
    if (is_array($input)) return json_encode($input);
    $decoded = json_decode((string)$input, true);
    return json_encode($decoded ?? []);
}
