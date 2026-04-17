# OpenClaw 配对与权限准备

如果任务管理接口出现 `pairing required`，先执行：

```powershell
cd D:\coder\openclaw
powershell -ExecutionPolicy Bypass -File .\openclaw.ps1 devices list
```

审批最新请求：

```powershell
powershell -ExecutionPolicy Bypass -File .\openclaw.ps1 devices approve <requestId>
```

检查网关：

```powershell
powershell -ExecutionPolicy Bypass -File .\openclaw.ps1 gateway status
```

确认 `.env`：

- `OPENCLAW_POWERSHELL_PATH=D:\coder\openclaw\openclaw.ps1`
- `OPENCLAW_CONFIG_PATH=C:\Users\Administrator\.openclaw\openclaw.json`
