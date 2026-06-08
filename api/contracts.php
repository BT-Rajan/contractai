<?php
/**
 * ContractAI – Contracts API
 *
 * GET    /api/contracts.php                        → list
 * GET    /api/contracts.php?id=N                   → single
 * POST   /api/contracts.php                        → AI generate
 * POST   /api/contracts.php?id=N  _method=PUT      → save edits
 * POST   /api/contracts.php?action=finalize&id=N   → finalize
 * POST   /api/contracts.php?id=N  _method=DELETE   → delete
 * GET    /api/contracts.php?action=pdf&id=N&lang=en → PDF stream
 */
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';

$user   = auth_required();
$tid    = $user['tenant_id'];
$id     = (int)($_GET['id'] ?? 0);
$action = trim($_GET['action'] ?? '');
$m      = resolve_method();

match(true) {
    $action === 'pdf'                           => contract_pdf($user, $id),
    $action === 'finalize' && $id > 0           => contract_finalize($user, $id),
    $m === 'GET'  && $id === 0                  => contract_list($tid),
    $m === 'GET'  && $id > 0                    => contract_show($tid, $id),
    $m === 'POST' && $id === 0 && !$action      => contract_generate($user),
    $m === 'PUT'  && $id > 0                    => contract_save($user, $id),
    $m === 'DELETE' && $id > 0                  => contract_delete($user, $id),
    default                                     => api_error('Endpoint not found', 404),
};

// ── LIST ──────────────────────────────────────────────────────
function contract_list(int $tid): void {
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, (int)($_GET['per_page'] ?? 20));
    $status  = trim($_GET['status'] ?? '');
    $q       = trim($_GET['q'] ?? '');

    $where  = 'c.tenant_id = ?';
    $params = [$tid];

    if (in_array($status, ['draft','final'], true)) {
        $where   .= ' AND c.status = ?';
        $params[] = $status;
    }
    if ($q) {
        $where   .= ' AND c.title LIKE ?';
        $params[] = "%{$q}%";
    }

    $sql = "SELECT c.id, c.title, c.status, c.language, c.tone,
                   c.created_at, c.finalized_at,
                   c.ai_tokens_in, c.ai_tokens_out,
                   u.full_name  AS created_by_name,
                   cp.company_name AS counterparty_name,
                   t.name       AS template_name
            FROM contracts c
            LEFT JOIN users u        ON u.id  = c.created_by
            LEFT JOIN counterparties cp ON cp.id = c.counterparty_id
            LEFT JOIN templates t    ON t.id  = c.template_id
            WHERE {$where}
            ORDER BY c.created_at DESC";

    [$rows, $total] = db_paginate($sql, $params, $page, $perPage);
    api_ok(['data' => $rows, 'pagination' => pagination_meta($page, $perPage, $total)]);
}

// ── SHOW ──────────────────────────────────────────────────────
function contract_show(int $tid, int $id): void {
    $row = db_row(
        "SELECT c.*,
                u.full_name     AS created_by_name,
                cp.company_name AS counterparty_name,
                t.name          AS template_name
         FROM contracts c
         LEFT JOIN users u        ON u.id  = c.created_by
         LEFT JOIN counterparties cp ON cp.id = c.counterparty_id
         LEFT JOIN templates t    ON t.id  = c.template_id
         WHERE c.id = ? AND c.tenant_id = ?",
        [$id, $tid]
    );
    if (!$row) api_error('Contract not found', 404);
    $row['questionnaire_data'] = json_decode($row['questionnaire_data'] ?? '{}', true) ?? [];
    api_ok($row);
}

// ── GENERATE (AI) ─────────────────────────────────────────────
function contract_generate(array $user): void {
    $tid = $user['tenant_id'];

    if (!quota_check($tid, 'contract')) api_error('Contract quota exceeded. Please upgrade your plan.', 429);
    if (!quota_check($tid, 'ai'))       api_error('AI call quota exceeded. Please upgrade your plan.',   429);

    rate_limit('ai_gen:' . $tid, RATE_AI['max'], RATE_AI['window']);

    $b = json_body();
    $errors = validate($b, [
        'title'       => 'required|min:2|max:500',
        'template_id' => 'required|numeric',
    ]);
    if ($errors) api_error('Validation failed', 422, $errors);

    $templateId     = (int)($b['template_id'] ?? 0);
    $counterpartyId = (int)($b['counterparty_id'] ?? 0);
    $tone           = in_array($b['tone'] ?? '', ['strong','friendly','casual'], true) ? $b['tone'] : 'strong';

    $template = db_row(
        "SELECT * FROM templates WHERE id = ? AND tenant_id = ? AND is_active = 1",
        [$templateId, $tid]
    );
    if (!$template) api_error('Template not found', 404);

    // Counterparty (decrypt sensitive fields)
    $cp = [];
    if ($counterpartyId > 0) {
        $cpRow = db_row(
            "SELECT * FROM counterparties WHERE id = ? AND tenant_id = ? AND is_active = 1",
            [$counterpartyId, $tid]
        );
        if ($cpRow) {
            $cp = $cpRow;
            $cp['reg_number']     = dec($cpRow['reg_number_enc']    ?? '');
            $cp['tax_number']     = dec($cpRow['tax_number_enc']    ?? '');
            $cp['signatory_name'] = dec($cpRow['signatory_name_enc']?? '');
        }
    }

    // Questionnaire answers — support both answers[key] and flat key in body
    $schema  = json_decode($template['questionnaire_schema'] ?? '{}', true) ?? [];
    $answers = [];
    $bodyAnswers = $b['answers'] ?? [];
    foreach (array_keys($schema) as $field) {
        $answers[$field] = trim((string)($bodyAnswers[$field] ?? $b[$field] ?? ''));
    }

    // Workspace AI instructions
    $tenant   = db_row("SELECT ai_prompt FROM tenants WHERE id = ?", [$tid]);
    $settings = ['ai_prompt' => $tenant['ai_prompt'] ?? ''];

    // Call Gemini
    $result = gemini_generate($template, $answers, $cp, $tone, $settings);
    if (isset($result['error'])) api_error($result['error'], 502);

    $contractId = db_insert(
        "INSERT INTO contracts
         (tenant_id, template_id, counterparty_id, title, language, tone,
          questionnaire_data, generated_html, status, ai_tokens_in, ai_tokens_out, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?)",
        [
            $tid,
            $templateId,
            $counterpartyId ?: null,
            trim($b['title']),
            $template['language'],
            $tone,
            json_encode($answers),
            $result['html'],
            $result['tokens_in'],
            $result['tokens_out'],
            $user['id'],
        ]
    );

    quota_increment($tid, 'contract');
    quota_increment($tid, 'ai');
    audit('contract.generate', 'contract', $contractId);

    api_created(['id' => $contractId, 'html' => $result['html']], 'Contract generated successfully');
}

// ── SAVE (editor) ─────────────────────────────────────────────
function contract_save(array $user, int $id): void {
    $row = tenant_guard(db_row("SELECT * FROM contracts WHERE id = ?", [$id]));
    if ($row['status'] === 'final') api_error('Cannot edit a finalised contract', 403);

    $b     = json_body();
    $html  = sanitize_html(trim($b['html'] ?? $b['edited_html'] ?? ''));
    $title = trim($b['title'] ?? '') ?: $row['title'];

    db_run(
        "UPDATE contracts SET edited_html = ?, title = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?",
        [$html, $title, $id, $user['tenant_id']]
    );

    audit('contract.save', 'contract', $id);
    api_ok(['id' => $id, 'title' => $title], 'Saved');
}

// ── FINALIZE ──────────────────────────────────────────────────
function contract_finalize(array $user, int $id): void {
    $row = tenant_guard(db_row("SELECT * FROM contracts WHERE id = ?", [$id]));
    if ($row['status'] === 'final') api_error('Already finalised', 400);

    db_run(
        "UPDATE contracts SET status = 'final', finalized_by = ?, finalized_at = NOW() WHERE id = ? AND tenant_id = ?",
        [$user['id'], $id, $user['tenant_id']]
    );
    audit('contract.finalize', 'contract', $id);
    api_ok(['id' => $id, 'status' => 'final'], 'Contract finalised');
}

// ── DELETE ────────────────────────────────────────────────────
function contract_delete(array $user, int $id): void {
    $row = tenant_guard(db_row("SELECT * FROM contracts WHERE id = ?", [$id]));
    if ($row['status'] === 'final') api_error('Cannot delete a finalised contract', 403);

    db_run("DELETE FROM contracts WHERE id = ? AND tenant_id = ?", [$id, $user['tenant_id']]);
    audit('contract.delete', 'contract', $id);
    api_ok(null, 'Contract deleted');
}

// ── PDF ───────────────────────────────────────────────────────
function contract_pdf(array $user, int $id): void {
    if (!$id) api_error('Contract ID required', 422);
    $row  = tenant_guard(db_row("SELECT * FROM contracts WHERE id = ?", [$id]));
    $lang = in_array($_GET['lang'] ?? 'en', ['en','ar']) ? $_GET['lang'] : 'en';
    $html = $row['edited_html'] ?: $row['generated_html'];

    // Serve cached PDF
    $cached = $lang === 'ar' ? $row['pdf_ar'] : $row['pdf_en'];
    if ($cached) {
        $full = STORAGE_PATH . '/pdfs/' . $id . '/' . $cached;
        if (file_exists($full)) { pdf_stream($full, $row['title']); }
    }

    // Generate fresh PDF
    if (!class_exists('\\Mpdf\\Mpdf')) {
        api_error('PDF generation requires mPDF. Run: composer require mpdf/mpdf', 501);
    }

    $path = pdf_build($id, $html, $lang, $row['title']);
    if (!$path) api_error('PDF generation failed', 500);

    $col = ($lang === 'ar') ? 'pdf_ar' : 'pdf_en';
    db_run("UPDATE contracts SET {$col} = ? WHERE id = ?", [basename($path), $id]);

    pdf_stream($path, $row['title']);
}

function pdf_build(int $id, string $html, string $lang, string $title): ?string {
    try {
        $dir = STORAGE_PATH . '/pdfs/' . $id;
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $isRtl = ($lang === 'ar');
        $mpdf  = new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_top'    => 20, 'margin_bottom' => 20,
            'margin_left'   => 22, 'margin_right'  => 22,
            'tempDir'       => STORAGE_PATH . '/tmp',
        ]);
        $mpdf->SetTitle($title);
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont   = true;
        if ($isRtl) $mpdf->SetDirectionality('rtl');

        $dir2 = $isRtl ? 'rtl' : 'ltr';
        $css  = "body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11pt;line-height:1.7;direction:{$dir2}}"
              . "h1{font-size:18pt;text-align:center;color:#1a3c5e;border-bottom:2px solid #1a3c5e;padding-bottom:8px;margin-bottom:18px}"
              . "h2{font-size:13pt;color:#1a3c5e;margin-top:16px}"
              . "p{margin-bottom:8px}table{width:100%;border-collapse:collapse}"
              . "th,td{border:1px solid #ccc;padding:6px 10px}th{background:#f0f4f8}";
        $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML("<div dir='{$dir2}'>{$html}</div>", \Mpdf\HTMLParserMode::HTML_BODY);

        $file = "contract_{$id}_{$lang}_" . time() . '.pdf';
        $mpdf->Output("{$dir}/{$file}", \Mpdf\Output\Destination::FILE);
        return "{$dir}/{$file}";
    } catch (\Exception $e) {
        log_error('PDF build failed', ['msg' => $e->getMessage()]);
        return null;
    }
}

function pdf_stream(string $path, string $title): never {
    $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $title) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ── GEMINI ────────────────────────────────────────────────────
function gemini_generate(array $tpl, array $answers, array $cp, string $tone, array $settings): array {
    if (!GEMINI_API_KEY) return ['error' => 'Gemini API key not configured. Add GEMINI_API_KEY to .env'];

    $toneText = match($tone) {
        'strong'   => 'Use firm, assertive, protective legal language. Favour the party commissioning this contract. Be precise and leave no ambiguity.',
        'friendly' => 'Use professional but approachable language. Balance both parties interests. Maintain clarity without excessive legalese.',
        'casual'   => 'Use plain English that non-lawyers can understand. Keep sentences short. Avoid jargon where possible.',
        default    => 'Use standard professional legal language.',
    };

    $answersBlock = '';
    foreach ($answers as $k => $v) {
        if (trim($v) !== '') {
            $answersBlock .= '- ' . str_replace('_', ' ', ucfirst($k)) . ': ' . $v . "\n";
        }
    }

    $cpBlock = '';
    if (!empty($cp['company_name'])) {
        $cpBlock = "\nCOUNTERPARTY DETAILS:\n";
        $map = ['company_name'=>'Company','company_name_ar'=>'Arabic Name',
                'reg_number'=>'Registration','tax_number'=>'Tax ID',
                'address'=>'Address','signatory_name'=>'Signatory','signatory_title'=>'Title'];
        foreach ($map as $f => $l) {
            if (!empty($cp[$f])) $cpBlock .= "- {$l}: {$cp[$f]}\n";
        }
    }

    $wsPrompt  = !empty($settings['ai_prompt'])  ? "\nWORKSPACE INSTRUCTIONS:\n{$settings['ai_prompt']}\n" : '';
    $tplPrompt = !empty($tpl['ai_prompt'])        ? "\nTEMPLATE INSTRUCTIONS:\n{$tpl['ai_prompt']}\n"      : '';

    $prompt = <<<PROMPT
You are an expert GCC legal contract drafter.

TONE: {$toneText}{$wsPrompt}{$tplPrompt}

TEMPLATE STRUCTURE:
{$tpl['contract_body']}

CONTRACT DETAILS PROVIDED:
{$answersBlock}{$cpBlock}

OUTPUT RULES (MUST follow exactly):
1. Draft a complete, professional legal contract.
2. Replace ALL {{placeholder}} tokens with the provided values.
3. Output ONLY clean semantic HTML — no markdown, no code fences, no explanations, no preamble.
4. Structure: <h1> title, <h2>/<h3> clauses, <p> paragraphs, <ol> numbered clauses.
5. Include a signature block at the end with two columns.
6. Do NOT include <html>, <head>, or <body> tags.
7. Ensure the contract is appropriate for GCC/UAE commercial law.
PROMPT;

    $url  = 'https://generativelanguage.googleapis.com/v1beta/models/'
          . GEMINI_MODEL . ':generateContent?key=' . GEMINI_API_KEY;

    $payload = json_encode([
        'contents'         => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 8192],
    ]);

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => $payload,
        'timeout' => 90,
    ]]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        log_error('Gemini unreachable');
        return ['error' => 'AI service unreachable. Please check your internet connection and try again.'];
    }

    $data = json_decode($raw, true);
    if (isset($data['error'])) {
        log_error('Gemini API error', $data['error']);
        return ['error' => 'Gemini error: ' . ($data['error']['message'] ?? 'Unknown')];
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (!$text) return ['error' => 'AI returned an empty response. Please try again.'];

    // Strip any markdown code fences the model might add
    $text = preg_replace('/^```html?\s*/im', '', $text);
    $text = preg_replace('/^```\s*$/im',    '', $text);

    return [
        'html'       => sanitize_html(trim($text)),
        'tokens_in'  => $data['usageMetadata']['promptTokenCount']     ?? 0,
        'tokens_out' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
    ];
}
