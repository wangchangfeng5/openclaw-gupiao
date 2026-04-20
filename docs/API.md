# API 设计说明

## 认证

- 登录后使用 PHP Session（`credentials: include`）
- 内部采集接口通过 `X-Ingest-Key` 鉴权

## 内部采集接口

- `POST /api/internal/ingest/suggestions`
- `POST /api/internal/ingest/sectors`
- `POST /api/internal/ingest/news`
- `POST /api/internal/ingest/quotes`
- `POST /api/internal/quality/log`

## SSE

- `GET /api/stream/events`
- 事件类型：
  - `alert`
  - `heartbeat`

## OpenClaw 任务

桥接命令：

- `openclaw cron list --all --json`
- `openclaw cron add ...`
- `openclaw cron edit <id> ...`
- `openclaw cron run <id>`
- `openclaw cron runs --id <id>`

结果同步到：

- `openclaw_jobs`
- `openclaw_job_runs`

## 关注池复盘

- `POST /api/watchlist/{id}/review`
  - 对单只关注股按历史分析点做复盘，输出准确率与收益率（1/3/5日）。
- `POST /api/watchlist/review-all`
  - 对 active 关注池批量复盘。
- `GET /api/watchlist/{id}/reviews`
  - 获取单股复盘历史。
- `POST /api/watchlist/review/snapshot`
  - 执行“复盘 + 快照写入”（slot 可选：`midday|close|manual`）。
- `GET /api/watchlist/review/snapshots/global?days=45`
  - 获取全局复盘快照序列（用于全局趋势图）。
- `GET /api/watchlist/{id}/review/snapshots?days=45`
  - 获取单股复盘快照序列（用于个股趋势图）。

`GET /api/watchlist` 额外返回以下字段（用于表格展示）：

- `review_total`
- `review_accurate`
- `review_accuracy_rate`
- `review_avg_return_1d_pct`
- `review_avg_return_3d_pct`
- `review_avg_return_5d_pct`
- `review_win_rate_5d`
- `review_latest_at`
- `review_last_return_pct`
- `review_sparkline_points`
- `snapshot_date`
- `snapshot_slot`
- `snapshot_sample_count`
- `snapshot_accuracy_rate`
- `snapshot_avg_return_1d_pct`
- `snapshot_avg_return_3d_pct`
- `snapshot_avg_return_5d_pct`
- `snapshot_win_rate_5d`
- `snapshot_score`
- `snapshot_series_points`
