const state = {
  me: null,
  overview: null,
  queueRows: [],
  queueStatus: '',
  refreshing: false,
};

const $ = (s) => document.querySelector(s);
const $$ = (s) => Array.from(document.querySelectorAll(s));

async function api(path, options = {}) {
  const timeoutMs = typeof options.timeoutMs === 'number' ? options.timeoutMs : 15000;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  const { timeoutMs: _omitTimeout, ...fetchOptions } = options;

  try {
    const res = await fetch(path, {
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        ...(fetchOptions.headers || {}),
      },
      ...fetchOptions,
      signal: controller.signal,
    });

    const json = await res.json().catch(() => ({ ok: false, error: 'Invalid JSON response' }));
    if (!res.ok || !json.ok) {
      throw new Error(json.error || `HTTP ${res.status}`);
    }
    return json.data;
  } catch (err) {
    if (err && err.name === 'AbortError') {
      throw new Error('请求超时，请稍后重试');
    }
    throw err;
  } finally {
    clearTimeout(timer);
  }
}

function showToast(text) {
  const tpl = $('#toastTpl');
  if (!tpl) return;
  const n = tpl.content.firstElementChild.cloneNode(true);
  n.textContent = text;
  document.body.appendChild(n);
  setTimeout(() => n.remove(), 2800);
}

function openExternalPage(path) {
  const url = new URL(path, window.location.origin).toString();
  const opened = window.open(url, '_blank', 'noopener,noreferrer');
  if (!opened) {
    showToast('新窗口被拦截，请允许弹窗后重试');
    return;
  }
  try {
    opened.opener = null;
  } catch (_) {
    // ignore
  }
  if (typeof opened.focus === 'function') {
    opened.focus();
  }
}

function fmtNum(v, digits = 2) {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '-';
  return Number(v).toLocaleString('zh-CN', { maximumFractionDigits: digits });
}

function fmtMin(v) {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '-';
  return `${Number(v).toFixed(0)} 分钟`;
}

function fmtRate(v) {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '-';
  return `${Number(v).toFixed(2)}%`;
}

function fmtTime(v) {
  if (!v) return '-';
  const t = Date.parse(String(v).replace(' ', 'T'));
  if (!Number.isFinite(t)) return String(v);
  return new Date(t).toLocaleString('zh-CN', { hour12: false });
}

function esc(text) {
  return String(text ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function levelLabel(level) {
  const v = String(level || '').toLowerCase();
  if (v === 'error' || v === 'critical' || v === 'failed') return '错误';
  if (v === 'warn' || v === 'warning' || v === 'retry') return '警告';
  return '信息';
}

function levelClass(level) {
  const v = String(level || '').toLowerCase();
  if (v === 'error' || v === 'critical' || v === 'failed') return 'down';
  if (v === 'warn' || v === 'warning' || v === 'retry') return 'warn';
  return '';
}

function statusPill(status) {
  const v = String(status || '').toLowerCase();
  if (v === 'done') return '<span class="pill up">done</span>';
  if (v === 'failed') return '<span class="pill down">failed</span>';
  if (v === 'retry') return '<span class="pill warn">retry</span>';
  if (v === 'processing') return '<span class="pill warn">processing</span>';
  if (v === 'pending') return '<span class="pill">pending</span>';
  return `<span class="pill">${esc(v || '-')}</span>`;
}

function reconcilePill(status) {
  const v = String(status || '').toLowerCase();
  if (v === 'ok') return '<span class="pill up">ok</span>';
  if (v === 'warn') return '<span class="pill warn">warn</span>';
  if (v === 'error') return '<span class="pill down">error</span>';
  return '<span class="pill">unknown</span>';
}

function renderKpi(payload) {
  const node = $('#healthKpi');
  if (!node) return;

  const latency = payload?.latency || {};
  const queueSummary = payload?.queue?.summary || {};
  const queueSuccess = payload?.queue?.success_24h || {};
  const quality = payload?.quality_24h || {};
  const reconcile = payload?.quotes_latest_reconcile || {};
  const reconcileDiff = Number(reconcile.missing_count || 0) + Number(reconcile.mismatched_count || 0);

  const cards = [
    ['行情延迟', fmtMin(latency.quote_delay_min)],
    ['建议延迟', fmtMin(latency.suggestion_delay_min)],
    ['新闻延迟', fmtMin(latency.news_delay_min)],
    ['队列成功率(24h)', fmtRate(queueSuccess.success_rate)],
    ['质量成功率(24h)', fmtRate(quality.success_rate)],
    ['待处理任务', fmtNum(queueSummary.pending || 0, 0)],
    ['失败任务', fmtNum(queueSummary.failed || 0, 0)],
    ['最新表差异', fmtNum(reconcileDiff, 0)],
    ['DB 查询延迟', `${fmtNum(latency.db_ping_ms || 0, 2)} ms`],
  ];

  node.innerHTML = cards
    .map(([k, v]) => `<article class="focus-kpi"><p>${esc(k)}</p><strong>${esc(v)}</strong></article>`)
    .join('');
}

function renderStatus(payload) {
  const node = $('#healthStatusGrid');
  if (!node) return;

  const freshness = payload?.freshness || {};
  const ingest = payload?.ingest_state || {};
  const queue = payload?.queue?.summary || {};
  const quality = payload?.quality_24h || {};
  const reconcile = payload?.quotes_latest_reconcile || {};

  const reconcileMain = `${reconcilePill(reconcile.status)} 缺失 ${fmtNum(reconcile.missing_count, 0)} / 不一致 ${fmtNum(reconcile.mismatched_count, 0)} / 孤儿 ${fmtNum(reconcile.orphan_count, 0)}`;
  const reconcileSub = `源 ${fmtNum(reconcile.source_symbols, 0)} / latest ${fmtNum(reconcile.latest_symbols, 0)} · 校验 ${fmtTime(reconcile.checked_at)}`;

  const blocks = [
    ['Ingest 状态', `状态 ${ingest.status || '-'}`, `更新时间 ${fmtTime(ingest.updated_at || ingest.finished_at || ingest.started_at)}`, false],
    ['行情快照', `最新 ${fmtTime(freshness.market_quotes)}`, '用于持仓与关注池价格计算', false],
    ['建议快照', `最新 ${fmtTime(freshness.openclaw_suggestions)}`, '用于建议中心与反馈闭环', false],
    ['收盘复盘', `最新 ${fmtTime(freshness.watchlist_review_snapshots)}`, '用于全局/个股复盘图表', false],
    ['队列状态', `pending ${fmtNum(queue.pending || 0, 0)} / failed ${fmtNum(queue.failed || 0, 0)}`, '网关不可用时本地降级队列承接', false],
    ['质量日志', `ok ${fmtNum(quality.ok || 0, 0)} / warn ${fmtNum(quality.warn || 0, 0)} / error ${fmtNum(quality.error || 0, 0)}`, '采集链路健康度', false],
    ['行情最新表一致性', reconcileMain, reconcileSub, true],
  ];

  node.innerHTML = blocks
    .map(([title, main, sub, rawMain]) => `
      <article class="health-status-card">
        <h4>${esc(title)}</h4>
        <p class="health-main">${rawMain ? main : esc(main)}</p>
        <p class="health-sub">${esc(sub)}</p>
      </article>
    `)
    .join('');

  const stamp = $('#healthStamp');
  if (stamp) {
    stamp.textContent = `生成于 ${fmtTime(payload?.generated_at)}`;
  }
}

function renderErrors(payload) {
  const rows = Array.isArray(payload?.recent_errors) ? payload.recent_errors : [];
  const node = $('#errorList');
  const chip = $('#errorCountChip');
  if (chip) {
    chip.textContent = `最近 ${rows.length} 条`;
  }
  if (!node) return;

  if (!rows.length) {
    node.innerHTML = '<p class="muted">暂无错误记录</p>';
    return;
  }

  node.innerHTML = rows
    .map((row) => `
      <article class="focus-card">
        <h4><span class="pill ${levelClass(row.level)}">${esc(levelLabel(row.level))}</span> ${esc(row.title || '-')}</h4>
        <p>${esc(row.message || '-')}</p>
        <div class="focus-meta">
          <span>${esc(row.source || '-')}</span>
          <span>${fmtTime(row.occurred_at)}</span>
        </div>
      </article>
    `)
    .join('');
}

function renderQueue(rows) {
  const node = $('#queueTable');
  if (!node) return;

  if (!rows.length) {
    node.innerHTML = '<tr><td class="muted">当前无队列任务</td></tr>';
    return;
  }

  node.innerHTML = `
    <thead>
      <tr>
        <th>ID</th><th>任务</th><th>动作</th><th>状态</th><th>尝试</th><th>排队时间</th><th>下次重试</th><th>最近错误</th><th>操作</th>
      </tr>
    </thead>
    <tbody>
      ${rows.map((row) => {
        const retryable = ['failed', 'retry'].includes(String(row.status || '').toLowerCase());
        return `<tr>
          <td>${fmtNum(row.id, 0)}</td>
          <td>${esc(row.job_id || '-')}</td>
          <td>${esc(row.action || '-')}</td>
          <td>${statusPill(row.status)}</td>
          <td>${fmtNum(row.attempt_count || 0, 0)}</td>
          <td>${fmtTime(row.queued_at)}</td>
          <td>${fmtTime(row.next_retry_at)}</td>
          <td title="${esc(row.last_error || '')}">${esc((row.last_error || '-').toString().slice(0, 60))}</td>
          <td>
            ${retryable ? `<button class="btn ghost tiny" data-retry-id="${Number(row.id || 0)}">一键重试</button>` : '<span class="muted">-</span>'}
          </td>
        </tr>`;
      }).join('')}
    </tbody>
  `;

  $$('[data-retry-id]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = Number(btn.dataset.retryId || 0);
      if (!id) return;
      btn.disabled = true;
      try {
        await api(`/api/system/health/queue/${id}/retry?flush=1`, {
          method: 'POST',
          body: '{}',
          timeoutMs: 20000,
        });
        showToast(`队列任务 #${id} 已重试`);
        await loadAll();
      } catch (err) {
        showToast(`重试失败: ${err.message}`);
      } finally {
        btn.disabled = false;
      }
    });
  });
}

async function loadOverview() {
  const payload = await api('/api/system/health/overview', { timeoutMs: 20000 });
  state.overview = payload;
  renderKpi(payload);
  renderStatus(payload);
  renderErrors(payload);
}

async function loadQueue() {
  const status = state.queueStatus ? `&status=${encodeURIComponent(state.queueStatus)}` : '';
  const payload = await api(`/api/system/health/queue?limit=200${status}`, { timeoutMs: 20000 });
  state.queueRows = payload.rows || [];
  renderQueue(state.queueRows);
}

async function loadAll() {
  if (state.refreshing) return;
  state.refreshing = true;
  try {
    await Promise.all([loadOverview(), loadQueue()]);
  } finally {
    state.refreshing = false;
  }
}

async function initAuth() {
  try {
    const data = await api('/api/auth/me', { timeoutMs: 12000 });
    state.me = data.user;
  } catch {
    window.location.href = '/index.html';
    return false;
  }
  return true;
}

function wireEvents() {
  $('#openDashboardBtn')?.addEventListener('click', () => openExternalPage('/index.html'));
  $('#openPositionsBtn')?.addEventListener('click', () => openExternalPage('/positions.html'));
  $('#openWatchlistBtn')?.addEventListener('click', () => openExternalPage('/watchlist.html'));

  $('#refreshBtn')?.addEventListener('click', () => {
    loadAll().catch((err) => showToast(`刷新失败: ${err.message}`));
  });

  $('#reloadQueueBtn')?.addEventListener('click', () => {
    loadQueue().catch((err) => showToast(`队列刷新失败: ${err.message}`));
  });

  $('#queueStatusFilter')?.addEventListener('change', (e) => {
    state.queueStatus = e.target?.value || '';
    loadQueue().catch((err) => showToast(`队列筛选失败: ${err.message}`));
  });

  $('#flushQueueBtn')?.addEventListener('click', async () => {
    try {
      const result = await api('/api/openclaw/cron/queue/flush', {
        method: 'POST',
        body: '{}',
        timeoutMs: 25000,
      });
      const processed = result?.result?.processed ?? 0;
      const success = result?.result?.success ?? 0;
      showToast(`队列执行完成：处理 ${processed}，成功 ${success}`);
      await loadAll();
    } catch (err) {
      showToast(`队列执行失败: ${err.message}`);
    }
  });

  $('#retryFailedBtn')?.addEventListener('click', async () => {
    if (!window.confirm('确认重试全部失败任务吗？')) return;
    try {
      const data = await api('/api/system/health/queue/retry-failed?flush=1&limit=200', {
        method: 'POST',
        body: '{}',
        timeoutMs: 25000,
      });
      showToast(`已重试 ${data.retried_count || 0} 条失败任务`);
      await loadAll();
    } catch (err) {
      showToast(`批量重试失败: ${err.message}`);
    }
  });

  $('#logoutBtn')?.addEventListener('click', async () => {
    await api('/api/auth/logout', { method: 'POST', body: '{}' }).catch(() => {});
    window.location.href = '/index.html';
  });
}

async function boot() {
  wireEvents();
  const ok = await initAuth();
  if (!ok) return;

  await loadAll().catch((err) => showToast(`初始化失败: ${err.message}`));

  setInterval(() => {
    if (document.hidden) return;
    loadAll().catch(() => {});
  }, 20000);
}

boot();
