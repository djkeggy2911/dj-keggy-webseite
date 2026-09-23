-- Additive phase-2 migration. Run only through tools/cms_migrate.php after backup.
CREATE TABLE cms_weddings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(160) NOT NULL,
 wedding_date DATE NOT NULL,
 location VARCHAR(200) NOT NULL,
 internal_note TEXT NOT NULL,
 active TINYINT UNSIGNED NOT NULL DEFAULT 1,
 code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
 code_active TINYINT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE cms_reviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 wedding_id BIGINT UNSIGNED NOT NULL,
 stars TINYINT UNSIGNED NOT NULL,
 display_name VARCHAR(80) NOT NULL,
 body TEXT NOT NULL,
 language CHAR(2) CHARACTER SET ascii NOT NULL,
 consent TINYINT UNSIGNED NOT NULL,
 status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
 submission_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 moderated_at DATETIME NULL,
 FOREIGN KEY(wedding_id) REFERENCES cms_weddings(id),
 CHECK(stars BETWEEN 1 AND 5), CHECK(consent=1),
 INDEX(status,id), INDEX(wedding_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE cms_review_limits (
 bucket CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 expires_at BIGINT UNSIGNED NOT NULL,
 INDEX(expires_at)
) ENGINE=InnoDB;
INSERT INTO cms_schema_versions(version) VALUES(2);
