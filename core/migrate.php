<?php
declare(strict_types=1);
/**
 * ContractAI – lightweight migration runner.
 * Called once per request from bootstrap (cheap: just checks a version counter).
 * Each migration is idempotent — safe to run multiple times.
 */

define('SCHEMA_VERSION', 5); // bump this when adding new migrations

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
        if ($current < 3) migration_2();
        if ($current < 4) migration_4();
        if ($current < 5) migration_5();
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
    // Create clauses table (full definition)
    db_run("CREATE TABLE IF NOT EXISTS clauses (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id    INT UNSIGNED NOT NULL,
        clause_uid   VARCHAR(20)  NOT NULL DEFAULT '',
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
        INDEX idx_tenant_cat (tenant_id, category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Repair: add any missing columns to clauses (handles partial prior creation)
    $existingCols = array_column(
        db_rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clauses'"),
        'COLUMN_NAME'
    );

    $addCols = [
        'clause_uid'   => "ADD COLUMN clause_uid   VARCHAR(20)  NOT NULL DEFAULT '' AFTER tenant_id",
        'title_ar'     => "ADD COLUMN title_ar     VARCHAR(500) NULL AFTER title",
        'body_html_ar' => "ADD COLUMN body_html_ar LONGTEXT     NULL AFTER body_html",
        'tags'         => "ADD COLUMN tags         VARCHAR(500) NULL AFTER body_html_ar",
        'is_active'    => "ADD COLUMN is_active    TINYINT(1)   DEFAULT 1 AFTER tags",
        'version'      => "ADD COLUMN version      SMALLINT UNSIGNED DEFAULT 1 AFTER is_active",
        'updated_at'   => "ADD COLUMN updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];
    foreach ($addCols as $col => $ddl) {
        if (!in_array($col, $existingCols, true)) {
            try { db_run("ALTER TABLE clauses {$ddl}"); } catch (Throwable) {}
        }
    }

    // Add unique index on clause_uid+tenant_id if missing
    $indexes = array_column(
        db_rows("SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clauses'"),
        'INDEX_NAME'
    );
    if (!in_array('uq_uid_tenant', $indexes, true)) {
        try { db_run("ALTER TABLE clauses ADD UNIQUE KEY uq_uid_tenant (clause_uid, tenant_id)"); } catch (Throwable) {}
    }

    // Create template_clauses join table
    db_run("CREATE TABLE IF NOT EXISTS template_clauses (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        template_id INT UNSIGNED NOT NULL,
        clause_id   INT UNSIGNED NOT NULL,
        sort_order  SMALLINT UNSIGNED DEFAULT 0,
        UNIQUE KEY uq_tpl_clause (template_id, clause_id),
        INDEX idx_template (template_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Add clause_ids column to templates if missing
    $tplCols = array_column(
        db_rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'templates'"),
        'COLUMN_NAME'
    );
    if (!in_array('clause_ids', $tplCols, true)) {
        try {
            db_run("ALTER TABLE templates ADD COLUMN clause_ids TEXT NULL
                    COMMENT 'JSON array of ordered clause IDs'
                    AFTER questionnaire_schema");
        } catch (Throwable) {}
    }

    // Add FK constraints (best-effort — ignored if already exist or FK off)
    try { db_run("ALTER TABLE clauses
        ADD CONSTRAINT fk_clauses_tenant FOREIGN KEY (tenant_id)  REFERENCES tenants(id) ON DELETE CASCADE,
        ADD CONSTRAINT fk_clauses_user   FOREIGN KEY (created_by) REFERENCES users(id)"); } catch (Throwable) {}
    try { db_run("ALTER TABLE template_clauses
        ADD CONSTRAINT fk_tc_template FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE,
        ADD CONSTRAINT fk_tc_clause   FOREIGN KEY (clause_id)   REFERENCES clauses(id)   ON DELETE CASCADE"); } catch (Throwable) {}
}

// ── Migration 3 → 4: contract_type column + search indexes ──────────────────
function migration_4(): void {
    $cols = array_column(
        db_rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contracts'"),
        'COLUMN_NAME'
    );

    // Add contract_type — populated from template category or questionnaire_data
    if (!in_array('contract_type', $cols, true)) {
        db_run("ALTER TABLE contracts ADD COLUMN contract_type VARCHAR(100) NULL
                COMMENT 'Denormalised from template.category for fast filtering'
                AFTER tone");
    }
    // Add party_1 / party_2 — denormalised from questionnaire for landlord/tenant search
    if (!in_array('party_1', $cols, true)) {
        db_run("ALTER TABLE contracts ADD COLUMN party_1 VARCHAR(255) NULL
                COMMENT 'First party name (landlord, employer, etc.)' AFTER contract_type");
    }
    if (!in_array('party_2', $cols, true)) {
        db_run("ALTER TABLE contracts ADD COLUMN party_2 VARCHAR(255) NULL
                COMMENT 'Second party name (tenant, employee, etc.)' AFTER party_1");
    }

    // Full-text search index on contracts.title
    $indexes = array_column(
        db_rows("SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contracts'"),
        'INDEX_NAME'
    );
    if (!in_array('ft_contracts_title', $indexes, true)) {
        try { db_run("ALTER TABLE contracts ADD FULLTEXT INDEX ft_contracts_title (title)"); }
        catch (Throwable) {}
    }
    // Index for type filtering
    if (!in_array('idx_tenant_type', $indexes, true)) {
        try { db_run("ALTER TABLE contracts ADD INDEX idx_tenant_type (tenant_id, contract_type)"); }
        catch (Throwable) {}
    }

    // Backfill contract_type from template category for existing rows
    db_run("UPDATE contracts c
            JOIN templates t ON t.id = c.template_id
            SET c.contract_type = t.category
            WHERE c.contract_type IS NULL AND c.template_id IS NOT NULL");
}

// ── Migration 4 → 5: audit_log diff columns for entity history ──────────────
function migration_5(): void {
    $cols = array_column(
        db_rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log'"),
        'COLUMN_NAME'
    );

    // before_json / after_json: full or partial snapshots for diffing in History UI
    if (!in_array('before_json', $cols, true)) {
        db_run("ALTER TABLE audit_log ADD COLUMN before_json LONGTEXT NULL
                COMMENT 'JSON snapshot of changed fields before the action' AFTER meta");
    }
    if (!in_array('after_json', $cols, true)) {
        db_run("ALTER TABLE audit_log ADD COLUMN after_json LONGTEXT NULL
                COMMENT 'JSON snapshot of changed fields after the action' AFTER before_json");
    }

    // Composite index for fast per-entity history lookups
    $indexes = array_column(
        db_rows("SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log'"),
        'INDEX_NAME'
    );
    if (!in_array('idx_entity', $indexes, true)) {
        try { db_run("ALTER TABLE audit_log ADD INDEX idx_entity (tenant_id, entity_type, entity_id, created_at)"); }
        catch (Throwable) {}
    }
}
