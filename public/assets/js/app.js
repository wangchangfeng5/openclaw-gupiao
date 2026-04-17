const state = {
  me: null,
  tabs: 'positions',
  source: null,
  pollTimer: null,
  templates: [],
};

const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => Array.from(document.querySelectorAll(sel));

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
      throw new Error('请求超时，请重试');
    }
    throw err;
  } finally {
    clearTimeout(timer);
  }
}

function showToast(text) {
  const tpl = $('#toastTpl');
  if (!tpl) return;
  const node = tpl.content.firstElementChild.cloneNode(true);
  node.textContent = text;
  document.body.appendChild(node);
  setTimeout(() => node.remove(), 3200);
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
function stopRealtimeChannels() {
  if (state.source) {
    state.source.close();
    state.source = null;
  }
  if (state.pollTimer) {
    clearInterval(state.pollTimer);
    state.pollTimer = null;
  }
}

function shouldUsePollingRealtime() {
  const host = window.location.hostname;
  return host === '127.0.0.1' || host === 'localhost';
}

function startRealtimePolling() {
  stopRealtimeChannels();

  const tick = () => {
    Promise.all([loadAlerts(), loadOverview(), loadSuggestions(), loadPositions()]).catch(() => {});
  };

  tick();
  state.pollTimer = setInterval(tick, 20000);
}

function fmtNum(val) {
  if (val === null || val === undefined || Number.isNaN(Number(val))) return '-';
  return Number(val).toLocaleString('zh-CN', { maximumFractionDigits: 2 });
}

function fmtPct(val) {
  if (val === null || val === undefined || Number.isNaN(Number(val))) return '-';
  const num = Number(val);
  return `${num >= 0 ? '+' : ''}${num.toFixed(2)}%`;
}

function fmtPlainPct(val) {
  if (val === null || val === undefined || Number.isNaN(Number(val))) return '-';
  return `${Number(val).toFixed(2)}%`;
}

function parseJson(value, fallback = []) {
  if (value === null || value === undefined || value === '') return fallback;
  if (Array.isArray(value) || typeof value === 'object') return value;
  try {
    return JSON.parse(value);
  } catch {
    return fallback;
  }
}

function toBool(value) {
  return value === true || value === 1 || value === '1';
}

function renderSyncStatus(failed) {
  const node = $('#syncStatus');
  if (!node) return;

  if (!failed.length) {
    node.textContent = '数据同步正常';
    node.classList.remove('warn');
    node.classList.add('ok');
    return;
  }

  node.textContent = `部分模块异常(${failed.length})`;
  node.classList.remove('ok');
  node.classList.add('warn');
  node.title = failed.join('\n');
}

function showLogin() {
  stopRealtimeChannels();
  $('#loginCard')?.classList.remove('hidden');
  $('#workspace')?.classList.add('hidden');
  $('#logoutBtn')?.classList.add('hidden');
  $('#userBadge')?.classList.add('hidden');
  $('#syncStatus')?.classList.add('hidden');
  $('#quickBackupBtn')?.classList.add('hidden');
  $('#syncNowBtn')?.classList.add('hidden');
}

function showWorkspace() {
  $('#loginCard')?.classList.add('hidden');
  $('#workspace')?.classList.remove('hidden');
  $('#logoutBtn')?.classList.remove('hidden');
  $('#userBadge')?.classList.remove('hidden');
  $('#syncStatus')?.classList.remove('hidden');
  $('#quickBackupBtn')?.classList.remove('hidden');
  $('#syncNowBtn')?.classList.remove('hidden');
}

function wireTabs() {
  $$('.tab').forEach((btn) => {
    btn.addEventListener('click', () => {
      const tab = btn.dataset.tab;
      state.tabs = tab;
      $$('.tab').forEach((b) => b.classList.toggle('active', b.dataset.tab === tab));
      $$('.tab-panel').forEach((p) => p.classList.toggle('active', p.id === `tab-${tab}`));
    });
  });
}

function renderKpi(kpi = {}) {
  const node = $('#kpiGrid');
  if (!node) return;

  const cards = [
    ['持仓数量', kpi.holding_count],
    ['新增建议', kpi.new_suggestions],
    ['新增提醒', kpi.new_alerts],
    ['运行中任务', kpi.active_jobs],
  ];

  node.innerHTML = cards
    .map(([label, value]) => `<div class="kpi-card"><p>${label}</p><strong>${fmtNum(value)}</strong></div>`)
    .join('');
}

function renderRisk(risk = {}) {
  const node = $('#riskBoard');
  if (!node) return;

  const items = [
    ['总成本', fmtNum(risk.total_cost_value)],
    ['总市值', fmtNum(risk.total_market_value)],
    ['浮动盈亏', fmtNum(risk.unrealized_pnl)],
    ['单日回撤', fmtPct(risk.daily_drawdown_pct)],
    ['集中度', fmtPct(risk.concentration_score)],
    ['止损风险数', fmtNum(risk.stoploss_risk_count)],
  ];

  node.innerHTML = items
    .map(([k, v]) => `<article class="risk-item"><small>${k}</small><b>${v}</b></article>`)
    .join('');
}

function renderPositions(rows) {
  const node = $('#positionsTable');
  if (!node) return;

  if (!rows.length) {
    node.innerHTML = '<p class="muted">暂无持仓</p>';
    return;
  }

  node.innerHTML = `
    <table>
      <thead><tr><th>代码</th><th>名称</th><th>数量</th><th>成本</th><th>现价</th><th>止损</th><th>止盈</th><th>状态</th></tr></thead>
      <tbody>
      ${rows
        .map(
          (r) => `<tr>
            <td>${r.symbol}</td>
            <td>${r.name || '-'}</td>
            <td>${fmtNum(r.quantity)}</td>
            <td>${fmtNum(r.cost_price)}</td>
            <td>${fmtNum(r.current_price)}</td>
            <td>${fmtNum(r.stop_loss_price)}</td>
            <td>${fmtNum(r.take_profit_price)}</td>
            <td>${r.status}</td>
          </tr>`
        )
        .join('')}
      </tbody>
    </table>
  `;
}

function renderSuggestions(rows) {
  const node = $('#suggestionList');
  if (!node) return;

  if (!rows.length) {
    node.innerHTML = '<p class="muted">暂无建议</p>';
    return;
  }

  node.innerHTML = rows
    .map(
      (r) => `<article class="list-item">
        <h4>#${r.id} 路 ${parseJson(r.tags_json, []).join(' / ') || '建议'}</h4>
        <p>${r.content}</p>
        <div class="meta">
          <span>置信度: ${fmtNum(r.confidence)}</span>
          <span>${r.suggested_at || r.created_at}</span>
          <span>${r.ingest_source}</span>
          <span>状态: ${r.status || 'new'}</span>
        </div>
        <div class="meta actions">
          <button class="btn ghost tiny" data-feedback-quick="${r.id}" data-outcome="profit" data-status="reviewed" data-score="85">标记盈利</button>
          <button class="btn ghost tiny" data-feedback-quick="${r.id}" data-outcome="loss" data-status="reviewed" data-score="40">标记亏损</button>
          <button class="btn ghost tiny" data-feedback-quick="${r.id}" data-outcome="pending" data-status="new" data-score="70">继续观察</button>
        </div>
      </article>`
    )
    .join('');

  $$('[data-feedback-quick]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const suggestionId = Number(btn.dataset.feedbackQuick);
      const outcome = btn.dataset.outcome || 'pending';
      const status = btn.dataset.status || 'reviewed';
      const reviewScore = Number(btn.dataset.score || 70);

      try {
        await submitSuggestionFeedback(suggestionId, {
          outcome,
          status,
          review_score: reviewScore,
          action_taken: 'quick_feedback',
          review_note: `quick: ${outcome}`,
        });
        showToast('建议反馈已记录');
      } catch (e) {
        showToast(`建议反馈失败: ${e.message}`);
      }
    });
  });
}

function renderJobs(rows, meta = {}) {
  const node = $('#jobList');
  if (!node) return;

  const queue = meta.queue || {};
  const queueMetaNode = $('#jobQueueMeta');
  if (queueMetaNode) {
    const pieces = [
      meta.degraded ? '降级模式' : '实时模式',
      `待同步 ${fmtNum(queue.pending || 0)}`,
      `失败 ${fmtNum(queue.failed || 0)}`,
    ];
    if (meta.reason) {
      pieces.push(`原因: ${meta.reason}`);
    }
    queueMetaNode.textContent = pieces.join(' | ');
  }

  if (!rows.length) {
    node.innerHTML = '<p class="muted">暂无任务（或尚未同步）</p>';
    return;
  }

  node.innerHTML = rows
    .map(
      (j) => `<article class="list-item">
        <h4>${j.name}</h4>
        <p>${j.description || '无描述'}</p>
        <div class="meta">
          <span>job_id: ${j.job_id}</span>
          <span>${String(j.job_id || '').startsWith('local-') ? '本地队列任务' : '远程任务'}</span>
          <span>${toBool(j.enabled) ? '启用中' : '已禁用'}</span>
          <span>下次: ${j.next_run_at || '-'}</span>
        </div>
        <div class="meta">
          <button class="btn ghost" data-run-job="${j.job_id}">立即执行</button>
          <button class="btn" data-toggle-job="${j.job_id}" data-enabled="${toBool(j.enabled) ? 1 : 0}">${toBool(j.enabled) ? '禁用' : '启用'}</button>
        </div>
      </article>`
    )
    .join('');

  $$('[data-run-job]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      try {
        const result = await api(`/api/openclaw/cron/jobs/${btn.dataset.runJob}/run`, { method: 'POST' });
        if (result.queued) {
          showToast(`网关不可写，执行请求已入队(${result.queue_id})`);
        } else {
          showToast('任务已触发执行');
        }
        await loadJobs();
      } catch (e) {
        showToast(`执行失败: ${e.message}`);
      }
    });
  });

  $$('[data-toggle-job]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const enabled = btn.dataset.enabled === '1';
      try {
        const result = await api(`/api/openclaw/cron/jobs/${btn.dataset.toggleJob}`, {
          method: 'PATCH',
          body: JSON.stringify({ enabled: !enabled }),
        });
        if (result.queued) {
          showToast(`网关不可写，编辑请求已入队(${result.queue_id})`);
        }
        await loadJobs();
      } catch (e) {
        showToast(`切换失败: ${e.message}`);
      }
    });
  });
}

function renderSectors(rows) {
  const node = $('#sectorList');
  if (!node) return;

  if (!rows.length) {
    node.innerHTML = '<p class="muted">暂无板块数据</p>';
    return;
  }

  node.innerHTML = rows
    .map((s) => {
      const strongStocks = s.strong_stocks || [];
      return `<article class="list-item">
        <h4>${s.sector_name}</h4>
        <p>强度 ${fmtNum(s.strength_score)} · 涨跌 ${fmtPct(s.change_pct)} · 龙头 ${s.leading_symbol || '-'}</p>
        <div class="meta"><span>${s.sample_time || '-'}</span></div>
        ${strongStocks.length
          ? `<div class="meta"><span>强势股: ${strongStocks.slice(0, 4).map((x) => `${x.symbol}(${fmtPct(x.change_pct)})`).join(' / ')}</span></div>`
          : ''}
      </article>`;
    })
    .join('');
}

function renderNews(rows) {
  const node = $('#newsList');
  if (!node) return;

  if (!rows.length) {
    node.innerHTML = '<p class="muted">暂无新闻</p>';
    return;
  }

  node.innerHTML = rows
    .map(
      (n) => `<article class="list-item">
        <h4>${n.title}</h4>
        <p>${n.summary || ''}</p>
        <div class="meta"><span>${n.source}</span><span>${n.published_at || '-'}</span>${n.url ? `<a href="${n.url}" target="_blank" rel="noreferrer">打开</a>` : ''}</div>
      </article>`
    )
    .join('');
}

function renderAlerts(rows) {
  const node = $('#alertList');
  if (!node) return;

  if (!rows.length) {
    node.innerHTML = '<p class="muted">暂无提醒</p>';
    return;
  }

  node.innerHTML = rows
    .map(
      (a) => `<article class="list-item">
        <h4>${a.title}</h4>
        <p>${a.message}</p>
        <div class="meta"><span>${a.alert_type}</span><span>${a.severity}</span><span>${a.triggered_at}</span></div>
      </article>`
    )
    .join('');
}

function renderFeedbackMetrics(metrics = {}) {
  const node = $('#feedbackBoard');
  if (!node) return;

  const cards = [
    ['复盘总数', metrics.total_feedback ?? 0],
    ['盈利反馈数', metrics.win_feedback ?? 0],
    ['建议胜率', fmtPlainPct(metrics.win_rate_pct ?? 0)],
    ['平均评分', fmtNum(metrics.avg_review_score ?? 0)],
  ];

  node.innerHTML = cards
    .map(([label, value]) => `<article class="kpi-card mini"><p>${label}</p><strong>${value}</strong></article>`)
    .join('');
}

function renderCandidates(payload = {}) {
  const node = $('#candidateList');
  if (!node) return;

  const watchlist = payload.watchlist || [];
  const leaders = payload.leaders || [];

  if (!watchlist.length && !leaders.length) {
    node.innerHTML = '<p class="muted">暂无候选数据</p>';
    return;
  }

  const watchHtml = watchlist.length
    ? watchlist
        .slice(0, 12)
        .map((w) => `<article class="list-item"><h4>${w.symbol} ${w.name || ''}</h4><p>${w.thesis || '无投资观点'}</p><div class="meta"><span>优先级: ${w.priority}</span><span>${w.market}</span></div></article>`)
        .join('')
    : '<p class="muted">暂无 active watchlist</p>';

  const leadersHtml = leaders.length
    ? leaders
        .slice(0, 12)
        .map((s) => `<article class="list-item"><h4>${s.leading_symbol || '-'} 路 ${s.sector_name}</h4><p>强度 ${fmtNum(s.strength_score)} / 涨跌 ${fmtPct(s.change_pct)}</p><div class="meta"><span>${s.sample_time || '-'}</span></div></article>`)
        .join('')
    : '<p class="muted">暂无板块龙头</p>';

  node.innerHTML = `
    <div class="split nested">
      <div><h4 class="sub-title">关注池</h4><div class="list compact">${watchHtml}</div></div>
      <div><h4 class="sub-title">板块龙头</h4><div class="list compact">${leadersHtml}</div></div>
    </div>
  `;
}

function renderTemplates(rows) {
  const node = $('#templateList');
  if (!node) return;

  state.templates = rows || [];
  syncTemplateInstantiateSelect();

  if (!rows.length) {
    node.innerHTML = '<p class="muted">暂无策略模板</p>';
    return;
  }

  node.innerHTML = rows
    .map(
      (t) => `<article class="list-item">
        <h4>${t.name}</h4>
        <p>${t.period} 路 ${t.cron_expression} 路 ${t.timezone}</p>
        <div class="meta">
          <span>${toBool(t.enabled) ? '启用' : '禁用'}</span>
          <button class="btn ghost" data-run-template="${t.id}">一键创建任务</button>
        </div>
      </article>`
    )
    .join('');

  $$('[data-run-template]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = btn.dataset.runTemplate;
      try {
        const res = await api(`/api/strategy/templates/${id}/instantiate`, { method: 'POST', body: '{}' });
        if (res.queued) {
          showToast(`模板实例化已入队，本地任务ID: ${res.job_id}`);
        } else {
          showToast('模板已实例化为 OpenClaw 任务');
        }
        await loadJobs();
      } catch (e) {
        showToast(`模板实例化失败: ${e.message}`);
      }
    });
  });
}

function syncTemplateInstantiateSelect() {
  const select = $('#templateInstantiateSelect');
  if (!select) return;

  const current = select.value;
  const options = state.templates || [];
  const html = [
    '<option value="">请先选择模板</option>',
    ...options.map((tpl) => `<option value="${tpl.id}">#${tpl.id} ${tpl.name} (${tpl.period})</option>`),
  ].join('');

  select.innerHTML = html;
  if (current && options.some((tpl) => String(tpl.id) === String(current))) {
    select.value = current;
  }
}

function renderAudit(rows) {
  const node = $('#auditList');
  if (!node) return;

  if (!rows.length) {
    node.innerHTML = '<p class="muted">暂无审计日志</p>';
    return;
  }

  node.innerHTML = rows
    .slice(0, 30)
    .map(
      (a) => `<article class="list-item">
        <h4>${a.action}</h4>
        <p>${a.entity_type || 'system'} ${a.entity_id ? `#${a.entity_id}` : ''}</p>
        <div class="meta"><span>${a.created_at}</span></div>
      </article>`
    )
    .join('');
}

function renderQuality(rows) {
  const node = $('#qualityList');
  if (!node) return;

  if (!rows.length) {
    node.innerHTML = '<p class="muted">暂无数据质量日志</p>';
    return;
  }

  node.innerHTML = rows
    .slice(0, 30)
    .map(
      (q) => `<article class="list-item">
        <h4>${q.job_name || q.source_name || 'collector'} 路 ${q.status}</h4>
        <p>${q.message || ''}</p>
        <div class="meta"><span>重试:${fmtNum(q.retry_count)}</span><span>${q.logged_at || q.created_at || '-'}</span></div>
      </article>`
    )
    .join('');
}

async function submitSuggestionFeedback(suggestionId, payload) {
  if (!Number.isInteger(suggestionId) || suggestionId <= 0) {
    throw new Error('建议 ID 无效');
  }

  await api(`/api/openclaw/suggestions/${suggestionId}/feedback`, {
    method: 'POST',
    body: JSON.stringify(payload),
  });

  await Promise.all([loadSuggestions(), loadAdvanced(), loadOverview()]);
}

async function loadOverview() {
  const data = await api('/api/dashboard/overview');
  renderKpi(data.kpi);
  renderRisk(data.risk);
}

async function loadPositions() {
  const data = await api('/api/positions');
  renderPositions(data.positions || []);
}

async function loadSuggestions() {
  const data = await api('/api/openclaw/suggestions?limit=50');
  renderSuggestions(data.suggestions || []);
}

async function loadJobs(forceSync = false, enableFlush = false) {
  const q = new URLSearchParams({
    sync: forceSync ? '1' : '0',
    flush: enableFlush ? '1' : '0',
  });
  const data = await api(`/api/openclaw/cron/jobs?${q.toString()}`);
  renderJobs(data.jobs || [], {
    degraded: !!data.degraded,
    reason: data.reason || '',
    queue: data.queue || {},
  });
}

async function loadMarket() {
  const [sectors, news] = await Promise.all([
    api('/api/market/themes/overview?sector_limit=20&stock_limit=4'),
    api('/api/news?limit=20'),
  ]);

  renderSectors(sectors.hot_sectors || sectors.sectors || []);
  renderNews(news.news || []);
}

async function loadAlerts() {
  const data = await api('/api/alerts?limit=50');
  renderAlerts(data.alerts || []);
}

async function loadAdvanced() {
  const [metrics, candidates, templates, audit, quality] = await Promise.all([
    api('/api/openclaw/suggestions/metrics'),
    api('/api/market/watchlist/candidates'),
    api('/api/strategy/templates'),
    api('/api/audit/logs?limit=50'),
    api('/api/quality/logs?limit=50'),
  ]);

  renderFeedbackMetrics(metrics);
  renderCandidates(candidates);
  renderTemplates(templates.templates || []);
  renderAudit(audit.logs || []);
  renderQuality(quality.logs || []);
}

async function loadAll() {
  const tasks = [
    ['总览', loadOverview],
    ['持仓', loadPositions],
    ['建议', loadSuggestions],
    ['任务', loadJobs],
    ['市场', loadMarket],
    ['消息', loadAlerts],
    ['进阶看板', loadAdvanced],
  ];

  const results = await Promise.allSettled(tasks.map(([, fn]) => fn()));
  const failed = [];
  results.forEach((r, idx) => {
    if (r.status === 'rejected') {
      failed.push(`${tasks[idx][0]}: ${r.reason?.message || 'unknown error'}`);
    }
  });

  renderSyncStatus(failed);
  if (failed.length) {
    showToast(`部分数据加载失败：${failed.length} 个模块`);
  }
}

function connectStream() {
  if (!state.me) return;

  if (shouldUsePollingRealtime()) {
    startRealtimePolling();
    return;
  }

  stopRealtimeChannels();

  const source = new EventSource('/api/stream/events', { withCredentials: true });
  state.source = source;

  source.addEventListener('alert', () => {
    loadAlerts().catch(() => {});
    loadOverview().catch(() => {});
    showToast('收到新的系统提醒');
  });

  source.onerror = () => {
    source.close();
    state.source = null;
    if (state.me) {
      setTimeout(connectStream, 4000);
    }
  };
}

async function initAuth() {
  try {
    const data = await api('/api/auth/me');
    state.me = data.user;
  } catch {
    showLogin();
    return;
  }

  showWorkspace();
  $('#userBadge').textContent = `用户: ${state.me.username}`;
  loadAll().catch(() => {});
  connectStream();
}

function wireForms() {
  const loginForm = $('#loginForm');
  if (loginForm) {
    loginForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const payload = Object.fromEntries(fd.entries());

      try {
        await api('/api/auth/login', { method: 'POST', body: JSON.stringify(payload) });
        $('#loginError').textContent = '';
        await initAuth();
        showToast('登录成功');
      } catch (err) {
        $('#loginError').textContent = err.message;
      }
    });
  }

  $('#logoutBtn')?.addEventListener('click', async () => {
    await api('/api/auth/logout', { method: 'POST', body: '{}' }).catch(() => {});
    state.me = null;
    stopRealtimeChannels();
    location.reload();
  });

  $('#refreshBtn')?.addEventListener('click', () => loadAll().catch(() => {}));
  $('#syncNowBtn')?.addEventListener('click', async () => {
    try {
      showToast('开始执行建议与行情同步...');
      await api('/api/system/ingest/once', { method: 'POST', body: '{}' });
      showToast('同步完成');
      await loadAll();
    } catch (err) {
      showToast(`同步失败: ${err.message}`);
    }
  });
  $('#openPositionsPageBtn')?.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    openExternalPage('/positions.html');
  });
  $('#openWatchlistPageBtn')?.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    openExternalPage('/watchlist.html');
  });
  $('#openSectorsPageBtn')?.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    openExternalPage('/sectors.html');
  });

  const positionForm = $('#positionForm');
  if (positionForm) {
    positionForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const submitBtn = positionForm.querySelector('button[type="submit"]');
      const oldText = submitBtn ? submitBtn.textContent : '';
      const fd = new FormData(e.target);
      const payload = Object.fromEntries(fd.entries());

      ['quantity', 'cost_price', 'current_price', 'stop_loss_price', 'take_profit_price'].forEach((k) => {
        if (payload[k] === '') payload[k] = null;
        else payload[k] = Number(payload[k]);
      });

      try {
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.textContent = '保存中...';
        }
        await api('/api/positions', { method: 'POST', body: JSON.stringify(payload) });
        e.target.reset();
        showToast('持仓已保存');
        await loadPositions();
        await loadOverview();
        await loadAdvanced().catch(() => {});
      } catch (err) {
        showToast(`持仓保存失败: ${err.message}`);
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = oldText || '保存持仓';
        }
      }
    });
  }

  const suggestionForm = $('#suggestionForm');
  if (suggestionForm) {
    suggestionForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const payload = Object.fromEntries(fd.entries());
      payload.symbols = (payload.symbols || '').split(',').map((x) => x.trim()).filter(Boolean);
      payload.tags = (payload.tags || '').split(',').map((x) => x.trim()).filter(Boolean);

      try {
        await api('/api/openclaw/suggestions/manual', { method: 'POST', body: JSON.stringify(payload) });
        e.target.reset();
        showToast('建议已补录');
        await Promise.all([loadSuggestions(), loadOverview()]);
      } catch (err) {
        showToast(`补录失败: ${err.message}`);
      }
    });
  }

  const feedbackForm = $('#feedbackForm');
  if (feedbackForm) {
    feedbackForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const payload = Object.fromEntries(fd.entries());

      const suggestionId = Number(payload.suggestion_id || 0);
      const reviewScore = payload.review_score === '' ? null : Number(payload.review_score);

      try {
        await submitSuggestionFeedback(suggestionId, {
          action_taken: 'manual_feedback',
          outcome: payload.outcome || 'pending',
          review_score: reviewScore,
          review_note: payload.review_note || '',
          status: payload.status || 'reviewed',
        });
        e.target.reset();
        showToast('建议反馈已提交');
      } catch (err) {
        showToast(`建议反馈失败: ${err.message}`);
      }
    });
  }

  const jobForm = $('#jobForm');
  if (jobForm) {
    jobForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const payload = Object.fromEntries(fd.entries());

      try {
        const result = await api('/api/openclaw/cron/jobs', { method: 'POST', body: JSON.stringify(payload) });
        if (result.queued) {
          showToast(`网关不可写，任务已进入本地队列(${result.queue_id})`);
        } else {
          showToast('任务创建成功');
        }
        e.target.reset();
        await loadJobs();
      } catch (err) {
        showToast(`任务创建失败: ${err.message}`);
      }
    });
  }

  const templateForm = $('#templateForm');
  if (templateForm) {
    templateForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const payload = Object.fromEntries(fd.entries());
      payload.enabled = true;

      try {
        await api('/api/strategy/templates', { method: 'POST', body: JSON.stringify(payload) });
        showToast('策略模板已创建');
        e.target.reset();
        await loadAdvanced();
      } catch (err) {
        showToast(`模板创建失败: ${err.message}`);
      }
    });
  }

  const templateInstantiateForm = $('#templateInstantiateForm');
  if (templateInstantiateForm) {
    templateInstantiateForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const payload = Object.fromEntries(fd.entries());
      const templateId = Number(payload.template_id || 0);

      if (!templateId) {
        showToast('请先选择模板');
        return;
      }

      try {
        const result = await api(`/api/strategy/templates/${templateId}/instantiate`, {
          method: 'POST',
          body: JSON.stringify({
            session: payload.session || 'isolated',
            timezone: payload.timezone || 'Asia/Shanghai',
          }),
        });
        if (result.queued) {
          showToast(`模板实例化已入队(${result.queue_id})`);
        } else {
          showToast('模板实例化成功');
        }
        await loadJobs();
      } catch (err) {
        showToast(`模板实例化失败: ${err.message}`);
      }
    });
  }

  const watchlistForm = $('#watchlistForm');
  if (watchlistForm) {
    watchlistForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const payload = Object.fromEntries(fd.entries());
      payload.priority = Number(payload.priority || 50);

      try {
        await api('/api/watchlist', { method: 'POST', body: JSON.stringify(payload) });
        showToast('关注池已更新');
        e.target.reset();
        await loadAdvanced();
      } catch (err) {
        showToast(`关注池更新失败: ${err.message}`);
      }
    });
  }

  const runBackup = async () => {
    try {
      await api('/api/system/backup', { method: 'POST', body: '{}' });
      showToast('备份任务已执行');
      await loadAdvanced();
      await loadJobs();
    } catch (err) {
      showToast(`备份失败: ${err.message}`);
    }
  };

  $('#backupBtn')?.addEventListener('click', runBackup);
  $('#quickBackupBtn')?.addEventListener('click', runBackup);

  $('#snapshotBtn')?.addEventListener('click', async () => {
    try {
      await api('/api/system/risk/snapshot', { method: 'POST', body: '{}' });
      showToast('风险快照已生成');
      await loadOverview();
      await loadAdvanced();
    } catch (err) {
      showToast(`风险快照失败: ${err.message}`);
    }
  });

  $('#syncJobsBtn')?.addEventListener('click', async () => {
    try {
      showToast('开始同步远端任务...');
      await loadJobs(true, true);
      showToast('远端任务同步完成');
    } catch (err) {
      showToast(`任务同步失败: ${err.message}`);
    }
  });

  $('#flushQueueBtn')?.addEventListener('click', async () => {
    try {
      const result = await api('/api/openclaw/cron/queue/flush', { method: 'POST', body: '{}' });
      const processed = result.result?.processed ?? 0;
      const success = result.result?.success ?? 0;
      showToast(`队列重试完成：处理 ${processed}，成功 ${success}`);
      await loadJobs();
    } catch (err) {
      showToast(`队列重试失败: ${err.message}`);
    }
  });
}

wireTabs();
wireForms();
initAuth();
setInterval(() => {
  if (state.me) {
    loadAll().catch(() => {});
  }
}, 60000);


