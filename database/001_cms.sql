-- Run manually in a NEW, dedicated CMS database after approval and backup.
-- No destructive statements. Do not import into a populated/partial schema.
CREATE TABLE cms_schema_versions (version INT PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE cms_users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 active TINYINT UNSIGNED NOT NULL DEFAULT 1,
 session_version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE cms_login_limits (
 bucket CHAR(64) CHARACTER SET ascii PRIMARY KEY,
 window_start BIGINT UNSIGNED NOT NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 INDEX(window_start)
) ENGINE=InnoDB;
-- Module tables follow in separate, versioned migrations when implemented.
INSERT INTO cms_schema_versions (version) VALUES (1);
