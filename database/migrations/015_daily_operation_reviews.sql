CREATE TABLE IF NOT EXISTS daily_operation_reviews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  review_date DATE NOT NULL,
  review_slot VARCHAR(16) NOT NULL DEFAULT 'close',
  auto_generated TINYINT(1) NOT NULL DEFAULT 1,
  generated_at DATETIME NOT NULL,

  realized_pnl DECIMAL(24,4) NOT NULL DEFAULT 0,
  unrealized_pnl DECIMAL(24,4) NOT NULL DEFAULT 0,
  total_pnl DECIMAL(24,4) NOT NULL DEFAULT 0,
  trade_count INT NOT NULL DEFAULT 0,
  win_trade_count INT NOT NULL DEFAULT 0,
  loss_trade_count INT NOT NULL DEFAULT 0,

  auto_profit_summary TEXT NULL,
  auto_issues TEXT NULL,
  auto_shortcomings TEXT NULL,
  auto_suggestions TEXT NULL,
  auto_metrics_json JSON NULL,

  self_profit_summary TEXT NULL,
  self_issues TEXT NULL,
  self_shortcomings TEXT NULL,
  self_suggestions TEXT NULL,
  self_plan TEXT NULL,
  self_score TINYINT NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uk_daily_operation_reviews_user_date (user_id, review_date),
  KEY idx_daily_operation_reviews_user_date (user_id, review_date),
  KEY idx_daily_operation_reviews_generated_at (generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
