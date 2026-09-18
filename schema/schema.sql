-- ファイル: schema/schema.sql
-- 機能: NFC URLの転送設定と、各転送要求の解析記録を作成するMariaDB初期スキーマ。

CREATE TABLE IF NOT EXISTS redirects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    destination_url VARCHAR(2048) NOT NULL,
    access_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_accessed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_redirects_slug (slug),
    KEY idx_redirects_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS redirect_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    redirect_id BIGINT UNSIGNED NOT NULL,
    accessed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    client_ip VARCHAR(45) NOT NULL DEFAULT '',
    user_agent VARCHAR(2048) NOT NULL DEFAULT '',
    referrer VARCHAR(2048) NOT NULL DEFAULT '',
    request_method VARCHAR(16) NOT NULL DEFAULT '',
    request_uri VARCHAR(2048) NOT NULL DEFAULT '',
    request_meta_json LONGTEXT NOT NULL,
    PRIMARY KEY (id),
    KEY idx_redirect_events_redirect_accessed (redirect_id, accessed_at),
    CONSTRAINT fk_redirect_events_redirect
        FOREIGN KEY (redirect_id) REFERENCES redirects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
