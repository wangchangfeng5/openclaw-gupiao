const state = {
  id: 0,
  payload: null,
  lastIngestAt: 0,
  syncing: false,
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

function fmtPct(v) {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '-';
  const n = Number(v);
  return `${n >= 0 ? '+' : ''}${n.toFixed(2)}%`;
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

function zoneLabel(zone) {
  if (zone === 'support_zone') return '支撑区';
  if (zone === 'middle_zone') return '中枢区';
  if (zone === 'resistance_zone') return '压力区';
  return '未知';
}

function tradeLabel(type) {
  if (type === 'add') return '加仓';
  if (type === 'reduce') return '减仓';
  if (type === 'clear') return '清仓';
  return type || '-';
}

function scoreClass(score) {
  const n = Number(score || 0);
  if (n >= 78) return 'score-a-plus';
  if (n >= 65) return 'score-a';
  if (n >= 50) return 'score-b';
  return 'score-c';
}

function scoreBadge(score, grade, tag) {
  if (score === null || score === undefined) return '<span class="notice">-</span>';
  return `<span class="score-badge ${scoreClass(score)}">${fmtNum(score, 0)} · ${grade || '-'}</span><span class="notice">${tag || '-'}</span>`;
}

function riskFlagsText(flags) {
  if (!Array.isArray(flags) || !flags.length) return '风险状态正常';
  const map = {
    stoploss_critical: '止损临界',
    stoploss_near: '接近止损',
    takeprofit_near: '接近止盈',
    takeprofit_watch: '止盈观察',
    deep_drawdown: '回撤偏大',
    profit_expanded: '浮盈扩大',
  };
  return flags.map((x) => map[x] || x).join(' / ');
}

function renderKpi(overview, sectorContext, quotes) {
  const latest = quotes.length ? quotes[quotes.length - 1] : null;
  const cards = [
    ['当前价/涨跌', `${fmtNum(overview.current_price)} / ${fmtPct(latest?.change_pct ?? overview.change_pct)}`],
    ['持仓数量', fmtNum(overview.quantity, 4)],
    ['成本/市值', `${fmtNum(overview.cost_value)} / ${fmtNum(overview.market_value)}`],
    ['浮动盈亏', `${fmtNum(overview.pnl)} (${fmtPct(overview.pnl_pct)})`],
    ['止损距离', fmtPct(overview.distance_to_stop_pct)],
    ['止盈距离', fmtPct(overview.distance_to_take_pct)],
    ['板块强度', `${fmtNum(sectorContext?.sector?.strength_score, 2)} (${fmtPct(sectorContext?.sector?.change_pct)})`],
    ['风险状态', riskFlagsText(overview.risk_flags)],
  ];

  $('#detailKpi').innerHTML = cards
    .map(([k, v]) => `<article class="focus-kpi"><p>${k}</p><strong>${v}</strong></article>`)
    .join('');
}

function drawTrendChart(quotes) {
  const canvas = $('#trendCanvas');
  if (!canvas) return;
  const rect = canvas.getBoundingClientRect();
  const width = Math.max(420, Math.floor(rect.width || 920));
  const height = 280;
  const dpr = window.devicePixelRatio || 1;
  canvas.width = Math.floor(width * dpr);
  canvas.height = Math.floor(height * dpr);
  canvas.style.width = `${width}px`;
  canvas.style.height = `${height}px`;

  const ctx = canvas.getContext('2d');
  if (!ctx) return;
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  ctx.clearRect(0, 0, width, height);

  ctx.fillStyle = 'rgba(8,16,22,0.88)';
  ctx.fillRect(0, 0, width, height);

  const values = quotes.map((x) => Number(x.price || 0)).filter((v) => Number.isFinite(v) && v > 0);
  if (values.length < 2) {
    ctx.fillStyle = '#9eb2c1';
    ctx.font = '14px "Noto Sans SC", sans-serif';
    ctx.fillText('暂无足够走势数据', 20, 34);
    return;
  }

  const min = Math.min(...values);
  const max = Math.max(...values);
  const pad = (max - min) * 0.12 || max * 0.01 || 1;
  const yMin = min - pad;
  const yMax = max + pad;

  const left = 50;
  const right = width - 14;
  const top = 18;
  const bottom = height - 34;
  const plotW = right - left;
  const plotH = bottom - top;

  ctx.strokeStyle = 'rgba(255,255,255,0.1)';
  ctx.lineWidth = 1;
  for (let i = 0; i <= 4; i += 1) {
    const y = top + (plotH / 4) * i;
    ctx.beginPath();
    ctx.moveTo(left, y);
    ctx.lineTo(right, y);
    ctx.stroke();
  }

  const points = values.map((p, idx) => {
    const x = left + (idx / (values.length - 1)) * plotW;
    const y = top + (1 - (p - yMin) / (yMax - yMin)) * plotH;
    return { x, y };
  });

  const grad = ctx.createLinearGradient(left, top, left, bottom);
  grad.addColorStop(0, 'rgba(88,215,255,0.9)');
  grad.addColorStop(1, 'rgba(88,215,255,0.18)');
  ctx.strokeStyle = '#58d7ff';
  ctx.lineWidth = 2;
  ctx.beginPath();
  points.forEach((pt, i) => {
    if (i === 0) ctx.moveTo(pt.x, pt.y);
    else ctx.lineTo(pt.x, pt.y);
  });
  ctx.stroke();

  ctx.beginPath();
  points.forEach((pt, i) => {
    if (i === 0) ctx.moveTo(pt.x, pt.y);
    else ctx.lineTo(pt.x, pt.y);
  });
  ctx.lineTo(points[points.length - 1].x, bottom);
  ctx.lineTo(points[0].x, bottom);
  ctx.closePath();
  ctx.fillStyle = grad;
  ctx.fill();

  ctx.fillStyle = '#d7e8f3';
  ctx.font = '12px "Noto Sans SC", sans-serif';
  ctx.fillText(`最低 ${fmtNum(min, 3)}`, 12, bottom - 4);
  ctx.fillText(`最高 ${fmtNum(max, 3)}`, 12, top + 12);
  const latest = values[values.length - 1];
  ctx.fillStyle = '#ffc756';
  ctx.fillText(`最新 ${fmtNum(latest, 3)}`, width - 120, top + 12);
}

function renderMetrics(overview) {
  const metrics = [
    ['趋势方向', trendLabel(overview.trend_direction)],
    ['位置状态', zoneLabel(overview.position_zone)],
    ['板块', overview.sector_name || '-'],
    ['支撑/压力', `${fmtNum(overview.support_price)} / ${fmtNum(overview.resistance_price)}`],
    ['止损/止盈', `${fmtNum(overview.stop_loss_price)} / ${fmtNum(overview.take_profit_price)}`],
    ['距支撑/压力', `${fmtPct(overview.distance_to_support_pct)} / ${fmtPct(overview.distance_to_resistance_pct)}`],
    ['实现盈亏', fmtNum(overview.realized_pnl_total)],
    ['交易次数', fmtNum(overview.trade_count, 0)],
    ['最近交易', overview.last_traded_at || '-'],
    ['操作建议', overview.action_advice || '-'],
  ];

  $('#metricsGrid').innerHTML = metrics
    .map(([k, v]) => `<article class="detail-metric"><p>${k}</p><strong>${v}</strong></article>`)
    .join('');
}

function renderQuoteTable(quotes) {
  const node = $('#quoteTable');
  const rows = [...quotes].slice(-40).reverse();
  if (!rows.length) {
    node.innerHTML = '<tr><td class="muted">暂无行情样本</td></tr>';
    return;
  }

  node.innerHTML = `
    <thead>
      <tr><th>时间</th><th>价格</th><th>涨跌</th><th>成交量</th><th>来源</th></tr>
    </thead>
    <tbody>
      ${rows.map((r) => `
        <tr>
          <td>${r.quote_time || '-'}</td>
          <td>${fmtNum(r.price)}</td>
          <td>${fmtPct(r.change_pct)}</td>
          <td>${fmtNum(r.volume, 0)}</td>
          <td>${r.source || '-'}</td>
        </tr>
      `).join('')}
    </tbody>
  `;
}

function renderTrades(trades) {
  const node = $('#tradeList');
  if (!node) return;
  if (!trades.length) {
    node.innerHTML = '<p class="muted">暂无交易记录</p>';
    return;
  }

  node.innerHTML = trades.map((t) => {
    const pnlClass = Number(t.realized_pnl || 0) >= 0 ? 'up' : 'down';
    const pnlText = t.realized_pnl === null || t.realized_pnl === undefined
      ? '-'
      : `<span class="pill ${pnlClass}">${fmtNum(t.realized_pnl)}</span>`;
    return `<article class="focus-card">
      <h4>${tradeLabel(t.trade_type)} · 数量 ${fmtNum(t.quantity, 4)} · 价格 ${fmtNum(t.price, 4)}</h4>
      <p>成交额 ${fmtNum(t.amount)} · 手续费 ${fmtNum(t.fee)} · 时间 ${t.traded_at || '-'}</p>
      <div class="focus-meta">
        <span>实现盈亏 ${pnlText}</span>
        ${t.note ? `<span>备注: ${t.note}</span>` : ''}
      </div>
    </article>`;
  }).join('');
}

function renderSector(sectorContext) {
  const sector = sectorContext?.sector || {};
  $('#sectorSummary').innerHTML = `
    <h4 style="margin:0 0 8px;">${sector.name || '未匹配板块'}</h4>
    <p>强度: ${fmtNum(sector.strength_score, 2)} · 涨跌: ${fmtPct(sector.change_pct)} · 样本时间: ${sector.sample_time || '-'}</p>
    <p>领涨代码: ${sector.leading_symbol || '-'} · 活跃数: ${fmtNum(sector.active_count, 0)} · 来源: ${sector.source || '-'}</p>
  `;

  const leaders = sectorContext?.leaders || [];
  $('#sectorLeaders').innerHTML = leaders.length
    ? leaders.map((x) => `<article class="focus-card">
        <h4>${x.symbol} ${x.name || ''}</h4>
        <p>价格 ${fmtNum(x.price)} · 涨跌 ${fmtPct(x.change_pct)} · ${trendLabel(x.trend_direction)}</p>
        <div class="focus-meta"><span>${x.quote_time || '-'}</span></div>
      </article>`).join('')
    : '<p class="muted">暂无板块强势票</p>';

  const momentum = sectorContext?.momentum || [];
  $('#sectorMomentum').innerHTML = momentum.length
    ? momentum.map((x) => `<article class="focus-card">
        <h4>${x.symbol} ${x.name || ''}</h4>
        <p>${x.sector_name || '-'} · ${trendLabel(x.trend_direction)} · 涨跌 ${fmtPct(x.change_pct)}</p>
        <div class="focus-meta">
          <span>价格 ${fmtNum(x.price)}</span>
          <span>量 ${fmtNum(x.volume, 0)}</span>
          <span>${x.quote_time || '-'}</span>
        </div>
      </article>`).join('')
    : '<p class="muted">暂无板块动量票</p>';

  const picks = sectorContext?.recommended || [];
  $('#sectorPicks').innerHTML = picks.length
    ? picks.map((x) => `<article class="focus-card">
        <h4>${x.symbol} ${x.name || ''}</h4>
        <p>${scoreBadge(x.ranking_score, x.ranking_grade, x.ranking_tag)}</p>
        <p>${trendLabel(x.trend_direction)} · ${zoneLabel(x.position_zone)} · 现价 ${fmtNum(x.current_price)}</p>
        <p>${x.ranking_action || '-'}</p>
      </article>`).join('')
    : '<p class="muted">暂无同板块推荐票</p>';
}

function renderRelatedPositions(items) {
  const node = $('#relatedPositions');
  if (!node) return;

  node.innerHTML = items.length
    ? items.map((x) => `<article class="focus-card">
        <h4>${x.symbol} ${x.name || ''}</h4>
        <p>${x.sector_name || '-'} · ${trendLabel(x.trend_direction)} · ${x.status || '-'}</p>
        <div class="focus-meta">
          <span>现价 ${fmtNum(x.current_price)}</span>
          <span>涨跌 ${fmtPct(x.change_pct)}</span>
          <span>盈亏 ${fmtPct(x.pnl_pct)}</span>
          <span>${x.quote_time || '-'}</span>
        </div>
        <div style="margin-top:8px;">
          <button class="btn ghost tiny" data-open-position-id="${x.id}">查看持仓详情</button>
        </div>
      </article>`).join('')
    : '<p class="muted">暂无可关联的持仓</p>';

  $$('[data-open-position-id]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = Number(btn.dataset.openPositionId || 0);
      if (!id) return;
      window.location.href = `/position-detail.html?id=${encodeURIComponent(String(id))}`;
    });
  });
}

function renderNews(items) {
  const node = $('#newsList');
  node.innerHTML = items.length
    ? items.map((x) => `<article class="focus-card">
        <h4>${x.title || '-'}</h4>
        <p>${x.summary || '-'}</p>
        <div class="focus-meta">
          <span>${x.published_at || '-'}</span>
          <span>${x.source || '-'}</span>
          <span>相关度 ${fmtNum(x.relevance_score, 0)}</span>
        </div>
      </article>`).join('')
    : '<p class="muted">暂无匹配新闻</p>';
}

function renderSuggestions(items) {
  const node = $('#suggestionList');
  node.innerHTML = items.length
    ? items.map((x) => `<article class="focus-card">
        <h4>建议 #${x.id} · 置信度 ${fmtNum(x.confidence, 0)}</h4>
        <p>${(x.content || '-').toString().slice(0, 280)}</p>
        <div class="focus-meta">
          <span>${x.suggested_at || '-'}</span>
          <span>${x.status || '-'}</span>
        </div>
      </article>`).join('')
    : '<p class="muted">暂无该股票的 OpenClaw 建议</p>';
}

function render(payload) {
  const overview = payload.overview || {};
  const symbol = overview.symbol || '-';
  const name = overview.name || '';
  const sectorName = overview.sector_name || '-';

  $('#detailTitle').textContent = `${symbol} ${name}`.trim();
  $('#detailSub').textContent = `板块: ${sectorName} · 趋势: ${trendLabel(overview.trend_direction)} · 位置: ${zoneLabel(overview.position_zone)}`;
  $('#detailStamp').textContent = `${new Date().toLocaleString('zh-CN')} · 自动刷新 15s`;

  const quoteSeries = payload.quote_series || [];
  renderKpi(overview, payload.sector_context || {}, quoteSeries);
  drawTrendChart(quoteSeries);
  renderMetrics(overview);
  renderQuoteTable(quoteSeries);
  renderTrades(payload.recent_trades || []);
  renderSector(payload.sector_context || {});
  renderRelatedPositions(payload.related_positions || []);
  renderNews(payload.related_news || []);
  renderSuggestions(payload.recent_suggestions || []);
}

async function maybeIngest(force = false) {
  const now = Date.now();
  if (!force && now - state.lastIngestAt < 60000) {
    return;
  }
  if (state.syncing) {
    return;
  }

  state.syncing = true;
  try {
    const result = await api('/api/system/ingest/once', { method: 'POST', body: '{}', timeoutMs: 8000 });
    const status = (result.status || '').toString().toLowerCase();
    if (['accepted', 'running', 'cooldown', 'ok', 'partial_failed'].includes(status)) {
      state.lastIngestAt = Date.now();
    }
  } catch (e) {
    if (force) {
      showToast(`行情/新闻同步失败: ${e.message}`);
    }
  } finally {
    state.syncing = false;
  }
}

async function loadDetail(showToastOnError = true, forceSync = false) {
  if (!state.id) return;
  try {
    if (forceSync) {
      await maybeIngest(true);
    } else {
      maybeIngest(false).catch(() => {});
    }
    const payload = await api(`/api/positions/${state.id}/detail?limit=220`);
    state.payload = payload;
    render(payload);
  } catch (e) {
    if (showToastOnError) {
      showToast(`加载详情失败: ${e.message}`);
    }
  }
}

async function ensureAuth() {
  try {
    await api('/api/auth/me');
  } catch {
    location.href = '/';
    throw new Error('unauthorized');
  }
}

function wireActions() {
  $('#backPositionsBtn')?.addEventListener('click', () => {
    openExternalPage('/positions.html');
  });
  $('#openWatchlistBtn')?.addEventListener('click', () => {
    openExternalPage('/watchlist.html');
  });
  $('#openDashboardBtn')?.addEventListener('click', () => {
    openExternalPage('/');
  });
  $('#refreshBtn')?.addEventListener('click', () => {
    loadDetail(true, true).catch(() => {});
  });
}

async function boot() {
  const id = Number(new URLSearchParams(window.location.search).get('id') || 0);
  if (!id) {
    showToast('缺少 position id 参数');
    return;
  }
  state.id = id;

  await ensureAuth();
  wireActions();
  await loadDetail(true, false);

  setInterval(() => {
    loadDetail(false, false).catch(() => {});
  }, 20000);
}

boot().catch(() => {});
