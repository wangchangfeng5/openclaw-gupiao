const state = {
  payload: null,
  loading: false,
  lastAutoSyncAt: 0,
};

const $ = (s) => document.querySelector(s);

async function api(path, options = {}) {
  const timeoutMs = typeof options.timeoutMs === 'number' ? options.timeoutMs : 20000;
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

function fmtPct(v) {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '-';
  const n = Number(v);
  return `${n >= 0 ? '+' : ''}${n.toFixed(2)}%`;
}

function scoreClass(score) {
  const n = Number(score || 0);
  if (n >= 75) return 'score-a-plus';
  if (n >= 62) return 'score-a';
  if (n >= 50) return 'score-b';
  return 'score-c';
}

function trendLabel(trend) {
  const v = (trend || '').toString().toLowerCase();
  if (v === 'strong_up') return '强势上行';
  if (v === 'up_bias') return '偏强震荡';
  if (v === 'strong_down') return '强势回落';
  if (v === 'down_bias') return '偏弱震荡';
  if (v === 'range') return '区间震荡';
  return trend || '-';
}

function fmtFlow(v) {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '-';
  const n = Number(v);
  const abs = Math.abs(n);
  if (abs >= 1e8) return `${n >= 0 ? '+' : '-'}${(abs / 1e8).toFixed(2)}亿`;
  if (abs >= 1e4) return `${n >= 0 ? '+' : '-'}${(abs / 1e4).toFixed(2)}万`;
  return `${n >= 0 ? '+' : '-'}${abs.toFixed(2)}`;
}

function barRow(label, value) {
  const safe = Math.max(0, Math.min(100, Number(value || 0)));
  return `<div class="op-dim-row">
    <span>${label}</span>
    <div class="op-dim-track"><i style="width:${safe}%;"></i></div>
    <b>${safe.toFixed(0)}</b>
  </div>`;
}

function sparklineSvg(values) {
  const points = (Array.isArray(values) ? values : [])
    .map((x) => Number(x))
    .filter((x) => Number.isFinite(x) && x > 0);
  if (points.length < 2) {
    return '<span class="notice">趋势样本不足</span>';
  }

  const width = 170;
  const height = 44;
  const min = Math.min(...points);
  const max = Math.max(...points);
  const span = Math.max(0.0001, max - min);

  const path = points.map((p, idx) => {
    const x = (idx / (points.length - 1)) * width;
    const y = height - ((p - min) / span) * height;
    return `${idx === 0 ? 'M' : 'L'}${x.toFixed(2)} ${y.toFixed(2)}`;
  }).join(' ');

  return `<svg class="op-sparkline" viewBox="0 0 ${width} ${height}" preserveAspectRatio="none">
    <path d="${path}" />
  </svg>`;
}

function renderKpi(payload) {
  const summary = payload?.summary || {};
  const cards = [
    ['持仓分析数', fmtNum(summary.position_count || 0, 0)],
    ['关注分析数', fmtNum(summary.watchlist_count || 0, 0)],
    ['机会池数量', fmtNum(summary.opportunity_count || 0, 0)],
    ['强支撑', fmtNum(summary.strong_support_count || 0, 0)],
    ['短涨小回调', fmtNum(summary.rise_pullback_count || 0, 0)],
    ['放量', fmtNum(summary.volume_surge_count || 0, 0)],
    ['交易时段', summary.market_open_now ? '是' : '否'],
  ];
  $('#opKpi').innerHTML = cards
    .map(([k, v]) => `<article class="focus-kpi"><p>${k}</p><strong>${v}</strong></article>`)
    .join('');
}

function renderPositions(payload) {
  const rows = payload?.positions || [];
  const node = $('#positionList');
  if (!rows.length) {
    node.innerHTML = '<p class="muted">暂无持仓分析数据</p>';
    return;
  }
  node.innerHTML = rows.slice(0, 20).map((r) => `
    <article class="focus-card">
      <h4>${r.symbol} ${r.name || ''} <span class="score-badge ${scoreClass(r.score)}">${fmtNum(r.score, 0)}</span></h4>
      <p>状态 ${r.status || '-'} · 成本 ${fmtNum(r.cost_price)} · 现价 ${fmtNum(r.current_price)} · 收益 ${fmtPct(r.pnl_pct)}</p>
      <p>距支撑 ${fmtPct(r.distance_to_support_pct)} · 量比 ${fmtNum(r.volume_ratio)}</p>
      <p>${r.action_advice || '-'}</p>
    </article>
  `).join('');
}

function renderWatchlist(payload) {
  const rows = payload?.watchlist || [];
  const node = $('#watchlistList');
  if (!rows.length) {
    node.innerHTML = '<p class="muted">暂无关注分析数据</p>';
    return;
  }
  node.innerHTML = rows.slice(0, 24).map((r) => `
    <article class="focus-card">
      <h4>${r.symbol} ${r.name || ''} <span class="score-badge ${scoreClass(r.score)}">${fmtNum(r.score, 0)}</span></h4>
      <p>优先级 ${fmtNum(r.priority || 0, 0)} · 评级 ${r.ranking_grade || '-'} · 评分 ${fmtNum(r.ranking_score)}</p>
      <p>${r.near_support ? '强支撑' : '-'} / ${r.rise_pullback ? '短涨回调' : '-'} / ${r.volume_surge ? '放量' : '-'}</p>
      <p>${r.action_advice || '-'}</p>
    </article>
  `).join('');
}

function renderOpportunities(payload) {
  const rows = payload?.opportunities || [];
  const node = $('#opportunityList');
  if (!rows.length) {
    node.innerHTML = '<p class="muted">当前无满足条件的主板标的</p>';
    return;
  }

  node.innerHTML = rows.map((r, idx) => {
    const dims = r.dimension_scores || {};
    return `
      <article class="focus-card op-card">
        <h4>#${idx + 1} ${r.symbol} ${r.name || ''} <span class="score-badge ${scoreClass(r.total_score)}">${fmtNum(r.total_score, 0)}</span></h4>
        <p>${r.sector_name || '-'} · ${trendLabel(r.trend_direction)} · 现价 ${fmtNum(r.price)} · 涨跌 ${fmtPct(r.change_pct)}</p>
        <div class="focus-meta">
          <span>${r.near_support ? '强支撑' : '非支撑'}</span>
          <span>${r.rise_pullback ? '短涨回调' : '非回调形态'}</span>
          <span>${r.volume_surge ? '放量' : '量能一般'}</span>
          <span>信号 ${r.signal_level || '-'}</span>
        </div>
        <div class="op-dim-board">
          ${barRow('技术面', dims.technical)}
          ${barRow('消息面', dims.news)}
          ${barRow('政策面', dims.policy)}
          ${barRow('资金面', dims.funds)}
        </div>
        <div class="focus-meta">
          <span>支撑 ${fmtNum(r.support_price)} / 压力 ${fmtNum(r.resistance_price)}</span>
          <span>距支撑 ${fmtPct(r.distance_to_support_pct)}</span>
          <span>3日涨幅 ${fmtPct(r.rise_3d_pct)}</span>
          <span>回调 ${fmtPct(r.pullback_pct)}</span>
          <span>量比 ${fmtNum(r.volume_ratio)}</span>
        </div>
        <div class="focus-meta">
          <span>主力净流 ${fmtFlow(r.net_main_inflow)}</span>
          <span>资金方向 ${r.flow_direction || '-'}</span>
          <span>强势榜#${r.close_rank || '-'}</span>
          <span>资金榜#${r.money_rank || '-'}</span>
        </div>
        <div class="focus-meta">
          <span>消息数 ${fmtNum(r.news_hits_72h || 0, 0)}</span>
          <span>${r.policy_tag || '中性'}</span>
          <span>${r.news_latest_time || '-'}</span>
        </div>
        ${r.news_latest_title ? `<p class="notice">最新消息：${r.news_latest_title}</p>` : ''}
        <div class="op-sparkline-wrap">${sparklineSvg(r.sparkline_prices || [])}</div>
        <p>${r.action_advice || '-'}</p>
      </article>
    `;
  }).join('');
}

function render(payload) {
  renderKpi(payload);
  renderPositions(payload);
  renderWatchlist(payload);
  renderOpportunities(payload);
  const stamp = `${payload?.generated_at || '-'} · 每小时自动更新`;
  $('#posStamp').textContent = stamp;
  $('#watchStamp').textContent = stamp;
  $('#opStamp').textContent = stamp;
}

async function ensureAuth() {
  try {
    await api('/api/auth/me');
  } catch {
    location.href = '/';
    throw new Error('unauthorized');
  }
}

async function loadData(syncFirst = false) {
  if (state.loading) return;
  state.loading = true;

  try {
    if (syncFirst) {
      await api('/api/system/ingest/once', { method: 'POST', body: '{}', timeoutMs: 25000 }).catch(() => {});
      state.lastAutoSyncAt = Date.now();
    }
    const payload = await api('/api/market/opportunities?limit=30', { timeoutMs: 25000 });
    state.payload = payload;
    render(payload);
  } finally {
    state.loading = false;
  }
}

function isTradingSessionLocal() {
  const now = new Date();
  const day = now.getDay();
  if (day === 0 || day === 6) return false;
  const minute = now.getHours() * 60 + now.getMinutes();
  const am = minute >= 570 && minute <= 690;
  const pm = minute >= 780 && minute <= 900;
  return am || pm;
}

function wireActions() {
  $('#openDashboardBtn')?.addEventListener('click', () => openExternalPage('/'));
  $('#openPositionsBtn')?.addEventListener('click', () => openExternalPage('/positions.html'));
  $('#openWatchlistBtn')?.addEventListener('click', () => openExternalPage('/watchlist.html'));
  $('#openSectorsBtn')?.addEventListener('click', () => openExternalPage('/sectors.html'));

  $('#refreshBtn')?.addEventListener('click', async () => {
    await loadData(false).catch((e) => showToast(e.message));
  });
  $('#syncRefreshBtn')?.addEventListener('click', async () => {
    await loadData(true).catch((e) => showToast(e.message));
  });
  $('#logoutBtn')?.addEventListener('click', async () => {
    await api('/api/auth/logout', { method: 'POST', body: '{}' }).catch(() => {});
    location.href = '/';
  });
}

async function boot() {
  await ensureAuth();
  wireActions();
  await loadData(false);

  setInterval(() => {
    if (!isTradingSessionLocal()) return;
    const elapsed = Date.now() - state.lastAutoSyncAt;
    if (elapsed < 60 * 60 * 1000) return;
    loadData(true).catch(() => {});
  }, 60000);
}

boot().catch(() => {});
