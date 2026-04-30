ALTER TABLE watchlist
  ADD INDEX idx_watchlist_user_status_id (user_id, status, id);

ALTER TABLE market_quotes
  ADD INDEX idx_market_quotes_market_symbol_time_id (market, symbol, quote_time, id);

ALTER TABLE watchlist_review_snapshots
  ADD INDEX idx_review_snapshot_user_scope_watchlist_id (user_id, snapshot_scope, watchlist_id, id);
