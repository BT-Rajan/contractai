<?php
/**
 * ContractAI – Templates API
 *
 * GET  /api/templates.php           → list
 * GET  /api/templates.php?id=N      → single (includes clauses)
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
                      u.full_name AS created_by_name,
                      (SELECT COUNT(*) FROM template_clauses tc WHERE tc.template_id = t.id) AS clause_count
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

    // Load associated clauses in order
    $row['clauses'] = tpl_load_clauses($id);

    api_ok($row);
}

// ── CREATE ────────────────────────────────────────────────────
function tpl_create(array $user): void {
    auth_role('owner', 'admin');
    $b = json_body();
    $errors = validate($b, [
        'name'     => 'required|min:2|max:255',
        'category' => 'required|max:100',
        'language' => 'required|in:en,ar,bilingual',
    ]);
    if ($errors) api_error('Validation failed', 422, $errors);

    $schema     = tpl_encode_schema($b['questionnaire_schema'] ?? '{}');
    $clauseIds  = tpl_sanitize_clause_ids($b['clause_ids'] ?? []);
    // Build contract_body by assembling selected clauses + any freeform header
    $body       = tpl_assemble_body($clauseIds, trim($b['contract_body'] ?? ''), $user['tenant_id']);

    if (empty($body)) api_error('Template must have either clauses selected or a contract body', 422);

    $id = db_insert(
        "INSERT INTO templates
         (tenant_id, name, name_ar, category, language,
          questionnaire_schema, contract_body, ai_prompt, clause_ids, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?)",
        [
            $user['tenant_id'],
            trim($b['name']),
            trim($b['name_ar'] ?? '') ?: null,
            trim($b['category']),
            $b['language'],
            $schema,
            $body,
            trim($b['ai_prompt'] ?? '') ?: null,
            json_encode($clauseIds),
            $user['id'],
        ]
    );

    tpl_sync_clauses($id, $clauseIds);
    audit('template.create', 'template', $id);

    $row = tpl_show_row($id);
    api_created($row, 'Template created');
}

// ── UPDATE ────────────────────────────────────────────────────
function tpl_update(array $user, int $id): void {
    auth_role('owner', 'admin');
    tenant_guard(db_row("SELECT id, tenant_id FROM templates WHERE id = ? AND is_active = 1", [$id]));

    $b = json_body();
    $errors = validate($b, [
        'name'     => 'required|min:2|max:255',
        'category' => 'required|max:100',
        'language' => 'required|in:en,ar,bilingual',
    ]);
    if ($errors) api_error('Validation failed', 422, $errors);

    $schema    = tpl_encode_schema($b['questionnaire_schema'] ?? '{}');
    $clauseIds = tpl_sanitize_clause_ids($b['clause_ids'] ?? []);
    $body      = tpl_assemble_body($clauseIds, trim($b['contract_body'] ?? ''), $user['tenant_id']);

    if (empty($body)) api_error('Template must have either clauses selected or a contract body', 422);

    db_run(
        "UPDATE templates SET
         name = ?, name_ar = ?, category = ?, language = ?,
         questionnaire_schema = ?, contract_body = ?,
         ai_prompt = ?, clause_ids = ?,
         version = version + 1
         WHERE id = ? AND tenant_id = ?",
        [
            trim($b['name']),
            trim($b['name_ar'] ?? '') ?: null,
            trim($b['category']),
            $b['language'],
            $schema,
            $body,
            trim($b['ai_prompt'] ?? '') ?: null,
            json_encode($clauseIds),
            $id, $user['tenant_id'],
        ]
    );

    tpl_sync_clauses($id, $clauseIds);
    audit('template.update', 'template', $id);
    api_ok(tpl_show_row($id), 'Template updated');
}

// ── DELETE ────────────────────────────────────────────────────
function tpl_delete(array $user, int $id): void {
    auth_role('owner', 'admin');
    tenant_guard(db_row("SELECT id, tenant_id FROM templates WHERE id = ? AND is_active = 1", [$id]));
    db_run("UPDATE templates SET is_active = 0 WHERE id = ? AND tenant_id = ?", [$id, $user['tenant_id']]);
    audit('template.delete', 'template', $id);
    api_ok(null, 'Template deleted');
}

// ── Helpers ────────────────────────────────────────────────────

function tpl_show_row(int $id): array {
    $row = db_row("SELECT * FROM templates WHERE id = ?", [$id]);
    $row['questionnaire_schema'] = json_decode($row['questionnaire_schema'] ?? '{}', true) ?? [];
    $row['clauses'] = tpl_load_clauses($id);
    return $row;
}

function tpl_load_clauses(int $tplId): array {
    return db_rows(
        "SELECT c.id, c.clause_uid, c.title, c.title_ar, c.category, c.tags,
                c.body_html, c.body_html_ar, tc.sort_order
         FROM template_clauses tc
         JOIN clauses c ON c.id = tc.clause_id
         WHERE tc.template_id = ? AND c.is_active = 1
         ORDER BY tc.sort_order, c.title",
        [$tplId]
    );
}

/** Sync template_clauses join table with the ordered clause ID list */
function tpl_sync_clauses(int $tplId, array $clauseIds): void {
    db_run("DELETE FROM template_clauses WHERE template_id = ?", [$tplId]);
    foreach ($clauseIds as $order => $cid) {
        db_run(
            "INSERT IGNORE INTO template_clauses (template_id, clause_id, sort_order)
             VALUES (?,?,?)",
            [$tplId, (int)$cid, $order]
        );
    }
}

/** Assemble final contract_body from ordered clauses + optional freeform footer */
function tpl_assemble_body(array $clauseIds, string $freeform, int $tid): string {
    $parts = [];
    if ($clauseIds) {
        $ph     = implode(',', array_fill(0, count($clauseIds), '?'));
        $rows   = db_rows(
            "SELECT id, clause_uid, title, body_html FROM clauses
             WHERE id IN ({$ph}) AND tenant_id = ? AND is_active = 1",
            array_merge($clauseIds, [$tid])
        );
        // Re-sort by clauseIds order
        $map = array_column($rows, null, 'id');
        foreach ($clauseIds as $cid) {
            if (isset($map[$cid])) {
                $c       = $map[$cid];
                $parts[] = "<section data-clause-uid=\"{$c['clause_uid']}\">"
                         . "<h2>{$c['title']}</h2>"
                         . $c['body_html']
                         . "</section>";
            }
        }
    }
    if ($freeform !== '') $parts[] = $freeform;
    return implode("\n\n", $parts);
}

function tpl_sanitize_clause_ids(mixed $input): array {
    if (is_string($input)) $input = json_decode($input, true) ?? [];
    if (!is_array($input)) return [];
    return array_values(array_filter(array_map('intval', $input)));
}

function tpl_encode_schema(mixed $input): string {
    if (is_array($input)) return json_encode($input);
    $decoded = json_decode((string)$input, true);
    return json_encode($decoded ?? []);
}
