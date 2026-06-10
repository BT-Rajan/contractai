<?php
declare(strict_types=1);
/**
 * ContractAI – lightweight migration runner.
 * Called once per request from bootstrap (cheap: just checks a version counter).
 * Each migration is idempotent — safe to run multiple times.
 */

define('SCHEMA_VERSION', 2); // bump this when adding new migrations

function run_migrations(): void {
    // Ensure app_options exists first (it stores the version number)
    db_run("CREATE TABLE IF NOT EXISTS app_options (
        option_key   VARCHAR(100) PRIMARY KEY,
        option_value TEXT         NOT NULL,
        updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $current = (int)(db_val("SELECT option_value FROM app_options WHERE option_key = 'schema_version'") ?? 0);
    if ($current >= SCHEMA_VERSION) return;

    db_transaction(function () use ($current): void {
        if ($current < 2) migration_2();
        db_run(
            "INSERT INTO app_options (option_key, option_value)
             VALUES ('schema_version', ?)
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            [SCHEMA_VERSION]
        );
    });
}

// ── Migration 1 → 2: Clause Library tables ────────────────────────────────
function migration_2(): void {
    db_run("CREATE TABLE IF NOT EXISTS clauses (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id    INT UNSIGNED NOT NULL,
        clause_uid   VARCHAR(20)  NOT NULL,
        title        VARCHAR(500) NOT NULL,
        title_ar     VARCHAR(500) NULL,
        category     VARCHAR(100) DEFAULT 'General',
        body_html    LONGTEXT     NOT NULL,
        body_html_ar LONGTEXT     NULL,
        tags         VARCHAR(500) NULL,
        is_active    TINYINT(1)   DEFAULT 1,
        version      SMALLINT UNSIGNED DEFAULT 1,
        created_by   INT UNSIGNED NOT NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_uid_tenant (clause_uid, tenant_id),
        INDEX idx_tenant_cat (tenant_id, category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_run("CREATE TABLE IF NOT EXISTS template_clauses (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        template_id INT UNSIGNED NOT NULL,
        clause_id   INT UNSIGNED NOT NULL,
        sort_order  SMALLINT UNSIGNED DEFAULT 0,
        UNIQUE KEY uq_tpl_clause (template_id, clause_id),
        INDEX idx_template (template_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Add clause_ids column to templates if it doesn't exist
    $col = db_val(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'templates'
           AND COLUMN_NAME  = 'clause_ids'"
    );
    if (!$col) {
        db_run("ALTER TABLE templates ADD COLUMN clause_ids TEXT NULL
                COMMENT 'JSON array of ordered clause IDs'
                AFTER questionnaire_schema");
    }

    // Add FK constraints only if both tables were just created cleanly
    // (skipped if DB doesn't support it or tables already had data)
    try {
        db_run("ALTER TABLE clauses
                ADD CONSTRAINT fk_clauses_tenant
                    FOREIGN KEY (tenant_id)  REFERENCES tenants(id) ON DELETE CASCADE,
                ADD CONSTRAINT fk_clauses_user
                    FOREIGN KEY (created_by) REFERENCES users(id)");
    } catch (Throwable) { /* ignore if FK already exists or FK support off */ }

    try {
        db_run("ALTER TABLE template_clauses
                ADD CONSTRAINT fk_tc_template
                    FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE,
                ADD CONSTRAINT fk_tc_clause
                    FOREIGN KEY (clause_id)   REFERENCES clauses(id)   ON DELETE CASCADE");
    } catch (Throwable) { /* ignore */ }
}
