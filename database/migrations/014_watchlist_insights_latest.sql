CREATE TABLE IF NOT EXISTS watchlist_insights_latest (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  watchlist_id BIGINT UNSIGNED NOT NULL,
  insight_id BIGINT UNSIGNED NOT NULL,
  symbol VARCHAR(16) NOT NULL,
  current_price DECIMAL(18,4) NULL,
  support_price DECIMAL(18,4) NULL,
  resistance_price DECIMAL(18,4) NULL,
  stop_loss_price DECIMAL(18,4) NULL,
  take_profit_price DECIMAL(18,4) NULL,
  position_zone VARCHAR(32) NULL,
  action_advice TEXT NULL,
  confidence DECIMAL(5,2) NULL,
  openclaw_note TEXT NULL,
  analyzed_at DATETIME NOT NULL,
  next_review_at DATETIME NULL,
  raw_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_watchlist_insights_latest_user_watchlist (user_id, watchlist_id),
  KEY idx_watchlist_insights_latest_watchlist (watchlist_id),
  KEY idx_watchlist_insights_latest_user_symbol (user_id, symbol),
  KEY idx_watchlist_insights_latest_analyzed (analyzed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO watchlist_insights_latest (
  user_id,
  watchlist_id,
  insight_id,
  symbol,
  current_price,
  support_price,
  resistance_price,
  stop_loss_price,
  take_profit_price,
  position_zone,
  action_advice,
  confidence,
  openclaw_note,
  analyzed_at,
  next_review_at,
  raw_json,
  created_at,
  updated_at
)
SELECT
  COALESCE(wi.user_id, 0) AS user_id,
  wi.watchlist_id,
  wi.id AS insight_id,
  wi.symbol,
  wi.current_price,
  wi.support_price,
  wi.resistance_price,
  wi.stop_loss_price,
  wi.take_profit_price,
  wi.position_zone,
  wi.action_advice,
  wi.confidence,
  wi.openclaw_note,
  wi.analyzed_at,
  wi.next_review_at,
  wi.raw_json,
  NOW(),
  NOW()
FROM watchlist_insights wi
INNER JOIN (
  SELECT COALESCE(user_id, 0) AS user_id, watchlist_id, MAX(id) AS max_id
  FROM watchlist_insights
  GROUP BY COALESCE(user_id, 0), watchlist_id
) latest ON latest.max_id = wi.id
ON DUPLICATE KEY UPDATE
  insight_id = VALUES(insight_id),
  symbol = VALUES(symbol),
  current_price = VALUES(current_price),
  support_price = VALUES(support_price),
  resistance_price = VALUES(resistance_price),
  stop_loss_price = VALUES(stop_loss_price),
  take_profit_price = VALUES(take_profit_price),
  position_zone = VALUES(position_zone),
  action_advice = VALUES(action_advice),
  confidence = VALUES(confidence),
  openclaw_note = VALUES(openclaw_note),
  analyzed_at = VALUES(analyzed_at),
  next_review_at = VALUES(next_review_at),
  raw_json = VALUES(raw_json),
  updated_at = NOW();
