CREATE TABLE IF NOT EXISTS market_quotes_latest (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  symbol VARCHAR(16) NOT NULL,
  market VARCHAR(32) NOT NULL,
  name VARCHAR(128) NULL,
  sector_name VARCHAR(191) NULL,
  trend_direction VARCHAR(32) NULL,
  price DECIMAL(18,4) NULL,
  change_pct DECIMAL(10,4) NULL,
  volume DECIMAL(24,4) NULL,
  turnover DECIMAL(24,4) NULL,
  quote_time DATETIME NOT NULL,
  source VARCHAR(64) NOT NULL,
  raw_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_market_quotes_latest_symbol_market (symbol, market),
  KEY idx_market_quotes_latest_market_turnover_volume (market, turnover, volume, symbol),
  KEY idx_market_quotes_latest_quote_time (quote_time),
  KEY idx_market_quotes_latest_market_sector_change_vol (market, sector_name, change_pct, volume, symbol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO market_quotes_latest (
  symbol, market, name, sector_name, trend_direction, price, change_pct, volume, turnover, quote_time, source, raw_json, created_at, updated_at
)
SELECT
  mq.symbol,
  mq.market,
  mq.name,
  mq.sector_name,
  mq.trend_direction,
  mq.price,
  mq.change_pct,
  mq.volume,
  mq.turnover,
  mq.quote_time,
  mq.source,
  mq.raw_json,
  NOW(),
  NOW()
FROM market_quotes mq
INNER JOIN (
  SELECT symbol, market, MAX(id) AS max_id
  FROM market_quotes
  GROUP BY symbol, market
) latest ON latest.max_id = mq.id
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  sector_name = VALUES(sector_name),
  trend_direction = VALUES(trend_direction),
  price = VALUES(price),
  change_pct = VALUES(change_pct),
  volume = VALUES(volume),
  turnover = VALUES(turnover),
  quote_time = VALUES(quote_time),
  source = VALUES(source),
  raw_json = VALUES(raw_json),
  updated_at = NOW();
