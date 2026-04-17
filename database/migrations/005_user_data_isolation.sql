SET @default_user_id := (SELECT id FROM users ORDER BY id ASC LIMIT 1);

ALTER TABLE positions
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY idx_positions_user_id (user_id);

UPDATE positions
SET user_id = @default_user_id
WHERE user_id IS NULL;

ALTER TABLE position_notes
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY idx_position_notes_user_id (user_id);

UPDATE position_notes pn
INNER JOIN positions p ON p.id = pn.position_id
SET pn.user_id = p.user_id
WHERE pn.user_id IS NULL;

UPDATE position_notes
SET user_id = @default_user_id
WHERE user_id IS NULL;

ALTER TABLE position_trades
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY idx_position_trades_user_id (user_id);

UPDATE position_trades pt
INNER JOIN positions p ON p.id = pt.position_id
SET pt.user_id = p.user_id
WHERE pt.user_id IS NULL;

UPDATE position_trades
SET user_id = @default_user_id
WHERE user_id IS NULL;

ALTER TABLE openclaw_suggestions
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY idx_openclaw_suggestions_user_id (user_id);

UPDATE openclaw_suggestions
SET user_id = @default_user_id
WHERE user_id IS NULL;

ALTER TABLE openclaw_suggestions
  DROP INDEX uk_openclaw_suggestions_message,
  ADD UNIQUE KEY uk_openclaw_suggestions_user_message (user_id, message_id);

ALTER TABLE advice_feedback
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY idx_advice_feedback_user_id (user_id);

UPDATE advice_feedback af
LEFT JOIN openclaw_suggestions s ON s.id = af.suggestion_id
LEFT JOIN positions p ON p.id = af.position_id
SET af.user_id = COALESCE(s.user_id, p.user_id, @default_user_id)
WHERE af.user_id IS NULL;

ALTER TABLE openclaw_jobs
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY idx_openclaw_jobs_user_id (user_id);

UPDATE openclaw_jobs
SET user_id = @default_user_id
WHERE user_id IS NULL;

ALTER TABLE openclaw_jobs
  DROP INDEX job_id,
  ADD UNIQUE KEY uk_openclaw_jobs_user_job (user_id, job_id);

ALTER TABLE openclaw_job_runs
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY idx_openclaw_job_runs_user_id (user_id);

UPDATE openclaw_job_runs r
LEFT JOIN openclaw_jobs j ON j.job_id = r.job_id
SET r.user_id = COALESCE(j.user_id, @default_user_id)
WHERE r.user_id IS NULL;

ALTER TABLE openclaw_job_queue
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY idx_openclaw_job_queue_user_id (user_id);

UPDATE openclaw_job_queue
SET user_id = @default_user_id
WHERE user_id IS NULL;

ALTER TABLE watchlist
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY idx_watchlist_user_id (user_id);

UPDATE watchlist
SET user_id = @default_user_id
WHERE user_id IS NULL;

ALTER TABLE watchlist
  DROP INDEX uk_watchlist_symbol_market,
  ADD UNIQUE KEY uk_watchlist_user_symbol_market (user_id, symbol, market);

ALTER TABLE watchlist_insights
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY idx_watchlist_insights_user_id (user_id);

UPDATE watchlist_insights wi
INNER JOIN watchlist w ON w.id = wi.watchlist_id
SET wi.user_id = w.user_id
WHERE wi.user_id IS NULL;

UPDATE watchlist_insights
SET user_id = @default_user_id
WHERE user_id IS NULL;

ALTER TABLE alerts
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY idx_alerts_user_id (user_id);

UPDATE alerts
SET user_id = @default_user_id
WHERE user_id IS NULL;

ALTER TABLE risk_snapshots
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY idx_risk_snapshots_user_id (user_id);

UPDATE risk_snapshots
SET user_id = @default_user_id
WHERE user_id IS NULL;

ALTER TABLE strategy_templates
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY idx_strategy_templates_user_id (user_id);

UPDATE strategy_templates
SET user_id = @default_user_id
WHERE user_id IS NULL;

ALTER TABLE backup_jobs
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY idx_backup_jobs_user_id (user_id);

UPDATE backup_jobs
SET user_id = @default_user_id
WHERE user_id IS NULL;
