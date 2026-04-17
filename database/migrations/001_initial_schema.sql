CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS positions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  symbol VARCHAR(16) NOT NULL,
  market VARCHAR(32) NOT NULL DEFAULT 'A_STOCK_MAIN',
  name VARCHAR(128) NULL,
  quantity DECIMAL(18,4) NOT NULL DEFAULT 0,
  cost_price DECIMAL(18,4) NOT NULL DEFAULT 0,
  current_price DECIMAL(18,4) NULL,
  stop_loss_price DECIMAL(18,4) NULL,
  take_profit_price DECIMAL(18,4) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'holding',
  strategy TEXT NULL,
  operation_advice TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_positions_symbol (symbol),
  KEY idx_positions_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS position_notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  position_id BIGINT UNSIGNED NOT NULL,
  note_type VARCHAR(24) NOT NULL DEFAULT 'advice',
  content TEXT NOT NULL,
  action_result TEXT NULL,
  review_score TINYINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_position_notes_position FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE,
  KEY idx_position_notes_position_id (position_id),
  KEY idx_position_notes_note_type (note_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS openclaw_suggestions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id VARCHAR(128) NULL,
  message_id VARCHAR(128) NULL,
  source_file VARCHAR(1024) NULL,
  suggested_at DATETIME NULL,
  content MEDIUMTEXT NOT NULL,
  symbols_json JSON NULL,
  tags_json JSON NULL,
  confidence DECIMAL(5,2) NULL,
  ingest_source VARCHAR(24) NOT NULL DEFAULT 'auto',
  status VARCHAR(24) NOT NULL DEFAULT 'new',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_openclaw_suggestions_message (message_id),
  KEY idx_openclaw_suggestions_session (session_id),
  KEY idx_openclaw_suggestions_suggested_at (suggested_at),
  KEY idx_openclaw_suggestions_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS advice_feedback (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  suggestion_id BIGINT UNSIGNED NOT NULL,
  position_id BIGINT UNSIGNED NULL,
  action_taken VARCHAR(64) NULL,
  outcome VARCHAR(64) NULL,
  review_score TINYINT NULL,
  review_note TEXT NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_advice_feedback_suggestion FOREIGN KEY (suggestion_id) REFERENCES openclaw_suggestions(id) ON DELETE CASCADE,
  CONSTRAINT fk_advice_feedback_position FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE SET NULL,
  KEY idx_advice_feedback_suggestion_id (suggestion_id),
  KEY idx_advice_feedback_position_id (position_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS openclaw_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id VARCHAR(128) NOT NULL UNIQUE,
  name VARCHAR(191) NOT NULL,
  description TEXT NULL,
  schedule_type VARCHAR(24) NULL,
  schedule_expr VARCHAR(191) NULL,
  timezone VARCHAR(64) NULL,
  session_target VARCHAR(64) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  delivery_mode VARCHAR(24) NULL,
  delivery_target VARCHAR(255) NULL,
  payload_json JSON NULL,
  raw_json JSON NULL,
  last_run_at DATETIME NULL,
  next_run_at DATETIME NULL,
  synced_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_openclaw_jobs_enabled (enabled),
  KEY idx_openclaw_jobs_next_run_at (next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS openclaw_job_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id VARCHAR(128) NOT NULL,
  run_id VARCHAR(128) NULL,
  status VARCHAR(32) NOT NULL,
  started_at DATETIME NULL,
  ended_at DATETIME NULL,
  duration_ms INT NULL,
  summary TEXT NULL,
  error_message TEXT NULL,
  raw_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_openclaw_job_runs_job_id (job_id),
  KEY idx_openclaw_job_runs_status (status),
  KEY idx_openclaw_job_runs_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS watchlist (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  symbol VARCHAR(16) NOT NULL,
  market VARCHAR(32) NOT NULL DEFAULT 'A_STOCK_MAIN',
  name VARCHAR(128) NULL,
  thesis TEXT NULL,
  priority INT NOT NULL DEFAULT 50,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_watchlist_symbol_market (symbol, market),
  KEY idx_watchlist_priority (priority),
  KEY idx_watchlist_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS market_quotes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  symbol VARCHAR(16) NOT NULL,
  market VARCHAR(32) NOT NULL,
  price DECIMAL(18,4) NULL,
  change_pct DECIMAL(10,4) NULL,
  volume DECIMAL(24,4) NULL,
  turnover DECIMAL(24,4) NULL,
  quote_time DATETIME NOT NULL,
  source VARCHAR(64) NOT NULL,
  raw_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_market_quotes_symbol_market_time (symbol, market, quote_time),
  KEY idx_market_quotes_quote_time (quote_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sector_strength (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sector_code VARCHAR(32) NULL,
  sector_name VARCHAR(191) NOT NULL,
  strength_score DECIMAL(10,4) NOT NULL DEFAULT 0,
  change_pct DECIMAL(10,4) NULL,
  leading_symbol VARCHAR(16) NULL,
  active_count INT NULL,
  sample_time DATETIME NOT NULL,
  source VARCHAR(64) NOT NULL,
  raw_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sector_strength_sample_time (sample_time),
  KEY idx_sector_strength_score (strength_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS news_feed (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(512) NOT NULL,
  summary TEXT NULL,
  url VARCHAR(1024) NULL,
  source VARCHAR(128) NOT NULL,
  category VARCHAR(64) NULL,
  sentiment VARCHAR(32) NULL,
  tags_json JSON NULL,
  published_at DATETIME NULL,
  dedupe_hash CHAR(64) NOT NULL,
  raw_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_news_feed_dedupe_hash (dedupe_hash),
  KEY idx_news_feed_published_at (published_at),
  KEY idx_news_feed_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS alerts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  alert_type VARCHAR(64) NOT NULL,
  title VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  severity VARCHAR(16) NOT NULL DEFAULT 'info',
  status VARCHAR(16) NOT NULL DEFAULT 'new',
  related_type VARCHAR(64) NULL,
  related_id VARCHAR(64) NULL,
  triggered_at DATETIME NOT NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_alerts_status (status),
  KEY idx_alerts_triggered_at (triggered_at),
  KEY idx_alerts_type (alert_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ingest_offsets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  consumer_name VARCHAR(128) NOT NULL UNIQUE,
  last_file VARCHAR(1024) NULL,
  last_offset BIGINT NOT NULL DEFAULT 0,
  last_checkpoint_at DATETIME NULL,
  extra_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS risk_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  total_market_value DECIMAL(24,4) NOT NULL DEFAULT 0,
  total_cost_value DECIMAL(24,4) NOT NULL DEFAULT 0,
  unrealized_pnl DECIMAL(24,4) NOT NULL DEFAULT 0,
  daily_drawdown DECIMAL(10,4) NOT NULL DEFAULT 0,
  concentration_score DECIMAL(10,4) NOT NULL DEFAULT 0,
  stoploss_risk_count INT NOT NULL DEFAULT 0,
  snapshot_time DATETIME NOT NULL,
  raw_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_risk_snapshots_snapshot_time (snapshot_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS strategy_templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(191) NOT NULL,
  period VARCHAR(24) NOT NULL,
  prompt TEXT NOT NULL,
  cron_expression VARCHAR(128) NOT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Shanghai',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_strategy_templates_period (period),
  KEY idx_strategy_templates_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS data_quality_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_name VARCHAR(128) NOT NULL,
  status VARCHAR(24) NOT NULL,
  message TEXT NOT NULL,
  retry_count INT NOT NULL DEFAULT 0,
  context_json JSON NULL,
  logged_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_data_quality_logs_job_name (job_name),
  KEY idx_data_quality_logs_logged_at (logged_at),
  KEY idx_data_quality_logs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS backup_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  status VARCHAR(24) NOT NULL,
  file_path VARCHAR(1024) NULL,
  size_bytes BIGINT NULL,
  error_message TEXT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_backup_jobs_status (status),
  KEY idx_backup_jobs_started_at (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(128) NOT NULL,
  target_type VARCHAR(64) NULL,
  target_id VARCHAR(64) NULL,
  request_path VARCHAR(255) NULL,
  ip_addr VARCHAR(64) NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_logs_action (action),
  KEY idx_audit_logs_created_at (created_at),
  KEY idx_audit_logs_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
