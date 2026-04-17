CREATE TABLE IF NOT EXISTS market_themes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  theme_name VARCHAR(128) NOT NULL,
  keywords VARCHAR(512) NULL,
  note TEXT NULL,
  priority INT NOT NULL DEFAULT 50,
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_market_themes_user_name (user_id, theme_name),
  KEY idx_market_themes_user_status_priority (user_id, status, priority),
  KEY idx_market_themes_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
