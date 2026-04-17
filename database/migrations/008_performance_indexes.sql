ALTER TABLE positions
  ADD INDEX idx_positions_user_status_updated_id (user_id, status, updated_at, id);

ALTER TABLE position_trades
  ADD INDEX idx_position_trades_user_position_traded_id (user_id, position_id, traded_at, id),
  ADD INDEX idx_position_trades_user_position_id (user_id, position_id, id);

ALTER TABLE position_notes
  ADD INDEX idx_position_notes_user_position_created (user_id, position_id, created_at, id);

ALTER TABLE watchlist
  ADD INDEX idx_watchlist_user_status_priority_updated (user_id, status, priority, updated_at, id);

ALTER TABLE watchlist_insights
  ADD INDEX idx_watchlist_insights_user_watchlist_id (user_id, watchlist_id, id),
  ADD INDEX idx_watchlist_insights_user_watchlist_analyzed (user_id, watchlist_id, analyzed_at, id);

ALTER TABLE openclaw_suggestions
  ADD INDEX idx_openclaw_suggestions_user_status_suggested (user_id, status, suggested_at, id),
  ADD INDEX idx_openclaw_suggestions_user_suggested (user_id, suggested_at, id);

ALTER TABLE alerts
  ADD INDEX idx_alerts_user_status_triggered (user_id, status, triggered_at, id),
  ADD INDEX idx_alerts_user_type_related_triggered (user_id, alert_type, related_id, triggered_at, id);

ALTER TABLE openclaw_jobs
  ADD INDEX idx_openclaw_jobs_user_enabled_next (user_id, enabled, next_run_at, id);

ALTER TABLE openclaw_job_queue
  ADD INDEX idx_openclaw_job_queue_user_status_retry (user_id, status, next_retry_at, id);

ALTER TABLE market_quotes
  ADD INDEX idx_market_quotes_symbol_market_id (symbol, market, id),
  ADD INDEX idx_market_quotes_market_symbol_id (market, symbol, id),
  ADD INDEX idx_market_quotes_market_sector_change_vol (market, sector_name, change_pct, volume, id),
  ADD INDEX idx_market_quotes_symbol_market_time_id (symbol, market, quote_time, id);

ALTER TABLE sector_strength
  ADD INDEX idx_sector_strength_sector_time_score (sector_name, sample_time, strength_score, id);

ALTER TABLE news_feed
  ADD INDEX idx_news_feed_published_id (published_at, id);
