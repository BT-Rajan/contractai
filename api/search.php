<?php
/**
 * ContractAI – Global Search API
 *
 * GET /api/search.php?q=keyword
 *   Returns matched contracts, counterparties, clauses, templates.
 *   Searches: contract title, type, party names, counterparty name,
 *             clause title/body, template name.
 *   Max 8 results per entity type.
 */
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';

$user = auth_required();
$tid  = $user['tenant_id'];
$q    = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    api_ok(['contracts' => [], 'counterparties' => [], 'clauses' => [], 'templates' => []]);
}

$like = '%' . $q . '%';

// ── Contracts: title, contract_type, party_1, party_2 ──────────
$contracts = db_rows(
    "SELECT c.id, c.title, c.status, c.contract_type, c.party_1, c.party_2,
            c.created_at, cp.company_name AS counterparty_name
     FROM contracts c
     LEFT JOIN counterparties cp ON cp.id = c.counterparty_id
     WHERE c.tenant_id = ?
       AND (c.title          LIKE ?
         OR c.contract_type  LIKE ?
         OR c.party_1        LIKE ?
         OR c.party_2        LIKE ?)
     ORDER BY c.created_at DESC LIMIT 8",
    [$tid, $like, $like, $like, $like]
);

// ── Counterparties: company name, email, signatory title ────────
$counterparties = db_rows(
    "SELECT id, company_name, company_name_ar, signatory_title,
            email, country, created_at
     FROM counterparties
     WHERE tenant_id = ? AND is_active = 1
       AND (company_name     LIKE ?
         OR company_name_ar  LIKE ?
         OR signatory_title  LIKE ?
         OR email            LIKE ?)
     ORDER BY company_name LIMIT 8",
    [$tid, $like, $like, $like, $like]
);

// ── Clauses: uid, title, category, tags ────────────────────────
$clauses = db_rows(
    "SELECT id, clause_uid, title, title_ar, category, tags
     FROM clauses
     WHERE tenant_id = ? AND is_active = 1
       AND (clause_uid LIKE ?
         OR title      LIKE ?
         OR title_ar   LIKE ?
         OR category   LIKE ?
         OR tags       LIKE ?)
     ORDER BY category, title LIMIT 8",
    [$tid, $like, $like, $like, $like, $like]
);

// ── Templates: name, category ───────────────────────────────────
$templates = db_rows(
    "SELECT id, name, name_ar, category, language
     FROM templates
     WHERE tenant_id = ? AND is_active = 1
       AND (name     LIKE ?
         OR name_ar  LIKE ?
         OR category LIKE ?)
     ORDER BY name LIMIT 8",
    [$tid, $like, $like, $like]
);

api_ok([
    'q'              => $q,
    'contracts'      => $contracts,
    'counterparties' => $counterparties,
    'clauses'        => $clauses,
    'templates'      => $templates,
    'total'          => count($contracts) + count($counterparties) + count($clauses) + count($templates),
]);
