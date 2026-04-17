# 架构总览

```mermaid
flowchart TB
  A[OpenClaw Session JSONL] --> B[Advice Ingestor]
  B --> C[(MySQL openclaw_suggestions)]
  C --> D[Dashboard 建议中心]
  D --> E[SSE 实时提醒]

  F[OpenClaw CLI cron/tasks/agent] --> G[OpenClaw Bridge]
  G --> H[(MySQL openclaw_jobs/runs)]
  H --> I[任务管理页面]
  I --> G

  J[行情/板块/新闻源] --> K[Market Collector]
  K --> L[(MySQL sector/news/quotes)]
  L --> D
  L --> M[强势股与活跃板块]

  N[持仓录入编辑] --> O[持仓与风控模块]
  O --> E
  O --> D
```

## 模块

- `Panel Web`：页面展示 + API
- `OpenClaw Bridge`：cron 命令封装与缓存同步
- `Advice Ingestor`：增量解析 JSONL 建议
- `Market Collector`：板块与新闻采集
- `Scheduler`：交易时段高频，非交易时段低频
- `Risk Engine`：组合风险计算与快照
- `Audit`：关键操作审计日志
