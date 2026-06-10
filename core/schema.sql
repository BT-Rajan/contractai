-- ContractAI – Database Schema
-- Import: mysql -u root contractai < core/schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS tenants (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(255) NOT NULL,
    slug          VARCHAR(100) NOT NULL UNIQUE,
    logo_path     VARCHAR(500) NULL,
    primary_color VARCHAR(7)   DEFAULT '#1a3c5e',
    language      ENUM('en','ar') DEFAULT 'en',
    timezone      VARCHAR(100) DEFAULT 'Asia/Dubai',
    is_active     TINYINT(1)   DEFAULT 1,
    ai_prompt     TEXT         NULL,
    created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plans (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    billing_cycle   ENUM('monthly','annual') DEFAULT 'monthly',
    price_usd       DECIMAL(10,2) DEFAULT 0,
    max_users       SMALLINT UNSIGNED DEFAULT 5,
    max_contracts   SMALLINT UNSIGNED DEFAULT 50,
    max_ai_calls    SMALLINT UNSIGNED DEFAULT 100,
    is_active       TINYINT(1) DEFAULT 1,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriptions (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id      INT UNSIGNED NOT NULL,
    plan_id        INT UNSIGNED NOT NULL,
    status         ENUM('active','trialing','past_due','canceled','paused') DEFAULT 'trialing',
    contracts_used SMALLINT UNSIGNED DEFAULT 0,
    ai_calls_used  SMALLINT UNSIGNED DEFAULT 0,
    trial_ends_at  DATETIME NULL,
    period_start   DATETIME NULL,
    period_end     DATETIME NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id)   REFERENCES plans(id),
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id         INT UNSIGNED NOT NULL,
    email             VARCHAR(255) NOT NULL,
    password_hash     VARCHAR(255) NOT NULL,
    full_name         VARCHAR(255) NOT NULL,
    role              ENUM('owner','admin','lawyer') DEFAULT 'lawyer',
    is_active         TINYINT(1)  DEFAULT 1,
    email_verified_at DATETIME    NULL,
    invited_by        INT UNSIGNED NULL,
    last_login_at     DATETIME    NULL,
    created_at        DATETIME    DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_tenant (email, tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token_hash VARCHAR(128) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    ip         VARCHAR(45) NULL,
    ua         VARCHAR(500) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_verifications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token      VARCHAR(128) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(255) NOT NULL,
    token      VARCHAR(128) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invitations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT UNSIGNED NOT NULL,
    email       VARCHAR(255) NOT NULL,
    role        ENUM('admin','lawyer') DEFAULT 'lawyer',
    token       VARCHAR(128) NOT NULL UNIQUE,
    invited_by  INT UNSIGNED NOT NULL,
    accepted_at DATETIME NULL,
    expires_at  DATETIME NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id)  REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (invited_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS counterparties (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id          INT UNSIGNED NOT NULL,
    company_name       VARCHAR(255) NOT NULL,
    company_name_ar    VARCHAR(255) NULL,
    reg_number_enc     TEXT NULL,
    tax_number_enc     TEXT NULL,
    address            TEXT NULL,
    signatory_name_enc TEXT NULL,
    signatory_title    VARCHAR(255) NULL,
    email              VARCHAR(255) NULL,
    phone              VARCHAR(50)  NULL,
    country            VARCHAR(10)  DEFAULT 'AE',
    is_active          TINYINT(1)   DEFAULT 1,
    created_by         INT UNSIGNED NOT NULL,
    created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id)  REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS templates (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id            INT UNSIGNED NOT NULL,
    name                 VARCHAR(255) NOT NULL,
    name_ar              VARCHAR(255) NULL,
    category             VARCHAR(100) DEFAULT 'General',
    language             ENUM('en','ar','bilingual') DEFAULT 'en',
    questionnaire_schema LONGTEXT NOT NULL,
    contract_body        LONGTEXT NOT NULL,
    ai_prompt            TEXT NULL,
    version              SMALLINT UNSIGNED DEFAULT 1,
    is_active            TINYINT(1) DEFAULT 1,
    created_by           INT UNSIGNED NOT NULL,
    created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id)  REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_tenant_cat (tenant_id, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contracts (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id          INT UNSIGNED NOT NULL,
    template_id        INT UNSIGNED NULL,
    counterparty_id    INT UNSIGNED NULL,
    title              VARCHAR(500) NOT NULL,
    language           ENUM('en','ar','bilingual') DEFAULT 'en',
    tone               ENUM('strong','friendly','casual') DEFAULT 'strong',
    questionnaire_data LONGTEXT NULL,
    generated_html     LONGTEXT NULL,
    edited_html        LONGTEXT NULL,
    status             ENUM('draft','final') DEFAULT 'draft',
    ai_tokens_in       INT UNSIGNED DEFAULT 0,
    ai_tokens_out      INT UNSIGNED DEFAULT 0,
    created_by         INT UNSIGNED NOT NULL,
    finalized_by       INT UNSIGNED NULL,
    finalized_at       DATETIME NULL,
    created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id)       REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id)     REFERENCES templates(id) ON DELETE SET NULL,
    FOREIGN KEY (counterparty_id) REFERENCES counterparties(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by)      REFERENCES users(id),
    INDEX idx_tenant_status (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key`        VARCHAR(255) NOT NULL,
    hits         SMALLINT UNSIGNED DEFAULT 1,
    window_start DATETIME NOT NULL,
    INDEX idx_key (`key`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT UNSIGNED NULL,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NULL,
    entity_id   INT UNSIGNED NULL,
    meta        TEXT NULL,
    ip          VARCHAR(45) NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant (tenant_id),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Seed plans
INSERT IGNORE INTO plans (id, name, billing_cycle, price_usd, max_users, max_contracts, max_ai_calls) VALUES
(1, 'Starter',    'monthly',  49,  3,   20,   50),
(2, 'Pro',        'monthly', 149, 10,  100,  300),
(3, 'Enterprise', 'monthly', 399, 50,  500, 1500);

-- NOTE: Admin user is NOT seeded here with a pre-hashed password.
-- Pre-hashed passwords fail because bcrypt hashes are environment-specific.
-- Instead: import this schema, then visit:
--   http://localhost/contractai/api/auth.php?action=setup
-- That page uses PHP's own password_hash() on your server and creates:
--   Email:    admin@cogzidel.com
--   Password: admin123

-- ── Clause Library (added for clause-based template workflow) ──

CREATE TABLE IF NOT EXISTS clauses (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    INT UNSIGNED NOT NULL,
    clause_uid   VARCHAR(20)  NOT NULL,          -- e.g. CL-0001, unique per tenant
    title        VARCHAR(500) NOT NULL,
    title_ar     VARCHAR(500) NULL,
    category     VARCHAR(100) DEFAULT 'General',
    body_html    LONGTEXT     NOT NULL,
    body_html_ar LONGTEXT     NULL,
    tags         VARCHAR(500) NULL,              -- comma-separated
    is_active    TINYINT(1)   DEFAULT 1,
    version      SMALLINT UNSIGNED DEFAULT 1,
    created_by   INT UNSIGNED NOT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_uid_tenant (clause_uid, tenant_id),
    FOREIGN KEY (tenant_id)  REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_tenant_cat (tenant_id, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS template_clauses (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id INT UNSIGNED NOT NULL,
    clause_id   INT UNSIGNED NOT NULL,
    sort_order  SMALLINT UNSIGNED DEFAULT 0,
    UNIQUE KEY uq_tpl_clause (template_id, clause_id),
    FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE,
    FOREIGN KEY (clause_id)   REFERENCES clauses(id)   ON DELETE CASCADE,
    INDEX idx_template (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration: add clause_ids column to templates for ordered clause list
ALTER TABLE templates ADD COLUMN IF NOT EXISTS clause_ids TEXT NULL COMMENT 'JSON array of ordered clause IDs';
