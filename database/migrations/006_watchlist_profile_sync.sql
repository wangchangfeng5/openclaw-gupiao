ALTER TABLE watchlist
  ADD COLUMN sector_name VARCHAR(128) NULL AFTER name,
  ADD COLUMN trend_direction VARCHAR(32) NULL AFTER status,
  ADD COLUMN updated_from_quote_at DATETIME NULL AFTER trend_direction;

ALTER TABLE market_quotes
  ADD COLUMN name VARCHAR(128) NULL AFTER market,
  ADD COLUMN sector_name VARCHAR(128) NULL AFTER name,
  ADD COLUMN trend_direction VARCHAR(32) NULL AFTER sector_name;

UPDATE watchlist w
LEFT JOIN (
  SELECT mq.symbol, mq.market, mq.name, mq.sector_name, mq.trend_direction, mq.quote_time
  FROM market_quotes mq
  INNER JOIN (
    SELECT symbol, market, MAX(id) AS max_id
    FROM market_quotes
    GROUP BY symbol, market
  ) latest ON latest.max_id = mq.id
) q ON q.symbol = w.symbol AND q.market = w.market
SET w.name = CASE WHEN (w.name IS NULL OR w.name = '') AND q.name IS NOT NULL AND q.name <> '' THEN q.name ELSE w.name END,
    w.sector_name = CASE WHEN (w.sector_name IS NULL OR w.sector_name = '') AND q.sector_name IS NOT NULL AND q.sector_name <> '' THEN q.sector_name ELSE w.sector_name END,
    w.trend_direction = CASE WHEN q.trend_direction IS NOT NULL AND q.trend_direction <> '' THEN q.trend_direction ELSE w.trend_direction END,
    w.updated_from_quote_at = CASE WHEN q.quote_time IS NOT NULL THEN q.quote_time ELSE w.updated_from_quote_at END
WHERE q.symbol IS NOT NULL;

