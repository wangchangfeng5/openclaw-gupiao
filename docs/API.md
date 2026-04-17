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
