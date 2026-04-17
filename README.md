# OpenClaw 智能投研与持仓管理面板

本项目是本机版 MVP：

- PHP 主后端 + MySQL 8
- Python 采集 Worker（建议流、板块/新闻/行情）
- OpenClaw CLI 桥接（cron/jobs 管理）
- SSE 实时提醒
- 单用户登录

## 目录结构

- `public/` 前端面板 + API 入口
- `app/` 后端代码（控制器/服务/核心）
- `workers/` Python 采集与调度
- `database/migrations/` 数据库迁移
- `scripts/` 初始化、备份、风险快照
- `scripts/windows/` Windows 启动脚本

## 快速开始（Windows）

1. 复制环境文件：

```powershell
Copy-Item .env.example .env
```

2. 修改 `.env` 里的数据库密码、`INGEST_API_KEY`。
   如需复用 OpenClaw 的 Streamlit 实时看盘数据，同步设置：
   `STREAMLIT_PANEL_URL=http://127.0.0.1:8501`

3. 初始化数据库：

```powershell
powershell -ExecutionPolicy Bypass -File scripts/windows/init-project.ps1
```

4. 启动 Web 服务：

```powershell
powershell -ExecutionPolicy Bypass -File scripts/windows/start-server.ps1
```

5. 启动 Worker：

```powershell
powershell -ExecutionPolicy Bypass -File scripts/windows/run-workers.ps1
```

7. 一键手动启动双面板（推荐日常使用）：

- 双击项目根目录的 `start-dual-panel.bat`
- 或执行：

```powershell
powershell -ExecutionPolicy Bypass -File scripts/windows/start-manual-panel.ps1
```

该入口会确保以下两个地址可用（已运行则不重复启动）：

- `http://127.0.0.1:8080/`
- `http://127.0.0.1:8501/`

8. Dev 独立测试入口（不影响稳定版）：

- 双击项目根目录的 `start-dev-panel.bat`
- 默认地址：
  - `http://127.0.0.1:8081/`（dev Web）
  - `http://127.0.0.1:8502/`（dev Streamlit）

6. 打开面板：

- `http://127.0.0.1:8080`
- `http://127.0.0.1:8080/sectors.html`（板块雷达：热门板块 + 强势股 + 自定义题材）
- 默认登录账号密码：以 `.env` 的 `ADMIN_USERNAME` / `ADMIN_PASSWORD` 为准。

说明：

- OpenClaw 建议归属账号会优先使用“最近一次登录账号”（写入 `storage/cache/ingest_target.json`）。
- 若无登录记录，则回退到 `.env` 的 `INGEST_DEFAULT_USERNAME`。
- 行情采集会优先读取 `STREAMLIT_PANEL_URL` 同源行情（含股票名称），再与东财数据合并补齐板块信息。

创建独立账号（数据隔离）：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe scripts\create_user.php your_name your_password
```

## 分支工作流（稳定版 + dev）

- 稳定分支：`main`（当前可用版本）
- 开发分支：`dev`（所有新功能先在这里开发和联调）
- 开发验证：使用 `start-dev-panel.bat`，只看 `8081/8502`
- 回归通过后发布：

```powershell
powershell -ExecutionPolicy Bypass -File scripts/windows/promote-dev-to-main.ps1
```

## 核心 API（MVP）

- `POST /api/auth/login`
- `GET /api/dashboard/overview`
- `GET/POST/PUT /api/positions`
- `GET /api/positions/{id}/notes`
- `POST /api/positions/{id}/notes`
- `POST /api/positions/{id}/trades/{tradeId}/undo`
- `GET /api/openclaw/suggestions`
- `POST /api/openclaw/suggestions/manual`
- `GET /api/openclaw/cron/jobs`
- `POST /api/openclaw/cron/jobs`
- `PATCH /api/openclaw/cron/jobs/{id}`
- `POST /api/openclaw/cron/jobs/{id}/run`
- `GET /api/openclaw/cron/jobs/{id}/runs`
- `GET /api/market/sectors/strength`
- `GET /api/market/themes/overview`
- `POST /api/market/themes`
- `PUT /api/market/themes/{id}`
- `DELETE /api/market/themes/{id}`
- `GET /api/market/watchlist/candidates`
- `GET /api/news`
- `GET /api/stream/events`
- `GET /api/internal/market/symbols`（internal key）
- `POST /api/system/ingest/once`（手动执行一次建议+行情同步）

## OpenClaw 对接说明

本项目通过 `OpenClawBridgeService` 调用本机 `openclaw.ps1`。请保证：

- `OPENCLAW_POWERSHELL_PATH` 正确
- OpenClaw Gateway 正常运行
- 已完成设备配对审批（避免 `pairing required`）

## Worker 调度策略

- 交易时段（工作日 9:25-11:35 / 12:55-15:10）：60 秒
- 非交易时段：900 秒

可选自动任务：

```powershell
powershell -ExecutionPolicy Bypass -File scripts/windows/setup-schtasks.ps1
```

## 测试

PHP：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe tests\php\run.php
```

Python：

```powershell
python -m unittest tests/python/test_advice_ingestor.py tests/python/test_market_collector.py
```

## 备份

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe scripts\backup.php
```

- 优先走 `mysqldump`
- 若不可用，自动降级为 JSON 备份
