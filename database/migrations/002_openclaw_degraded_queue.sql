CREATE TABLE IF NOT EXISTS openclaw_job_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(24) NOT NULL,
  job_id VARCHAR(128) NULL,
  payload_json JSON NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  attempt_count INT NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  queued_at DATETIME NOT NULL,
  last_attempt_at DATETIME NULL,
  next_retry_at DATETIME NULL,
  completed_at DATETIME NULL,
  result_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_openclaw_job_queue_status_next (status, next_retry_at),
  KEY idx_openclaw_job_queue_job_id (job_id),
  KEY idx_openclaw_job_queue_queued_at (queued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
