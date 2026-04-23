const state = {
  positions: [],
  me: null,
  selectedPositionId: null,
  loading: false,
  formMode: 'create',
  sidePanelOpen: false,
  sideHintShownAt: 0,
  detailPayload: null,
  detailFetchedAt: 0,
  detailLoading: false,
};

const $ = (s) => document.querySelector(s);
const $$ = (s) => Array.from(document.querySelectorAll(s));
const SIDE_PANEL_STORAGE_KEY = 'positions_side_panel_open_v1';

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

function numClass(v) {
  const n = Number(v);
  if (!Number.isFinite(n) || n === 0) return '';
  return n > 0 ? 'num-pos' : 'num-neg';
}

function zoneClass(zone) {
  return ['support_zone', 'middle_zone', 'resistance_zone', 'unknown'].includes(zone) ? zone : 'unknown';
}

function zoneLabel(zone) {
  if (zone === 'support_zone') return '支撑区';
  if (zone === 'middle_zone') return '中枢区';
  if (zone === 'resistance_zone') return '压力区';
  return '未知';
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

function tradeLabel(type) {
  if (type === 'add') return '加仓';
  if (type === 'reduce') return '减仓';
  if (type === 'clear') return '清仓';
  return type || '-';
}

function noteTypeLabel(type) {
  if (type === 'auto_sync') return '行情同步';
  if (type === 'openclaw_sync') return '建议同步';
  if (type === 'advice') return '人工建议';
  return type || '记录';
}

function updatePositionFormMode() {
  const chip = $('#positionFormMode');
  if (!chip) return;
  if (state.formMode === 'edit') {
    const id = Number($('#positionForm')?.elements?.id?.value || 0);
    chip.textContent = id > 0 ? `模式: 编辑 #${id}` : '模式: 编辑';
    return;
  }
  chip.textContent = '模式: 新增';
}

function renderKpi(summary = {}) {
  const cards = [
    ['持仓数量', fmtNum(summary.count || 0, 0)],
    ['总成本', fmtNum(summary.total_cost || 0)],
    ['总市值', fmtNum(summary.total_market_value || 0)],
    ['浮动盈亏', fmtNum(summary.total_pnl || 0)],
    ['实现盈亏', fmtNum(summary.total_realized_pnl || 0)],
    ['总收益率', fmtPct(summary.total_pnl_pct || 0)],
  ];

  $('#posKpi').innerHTML = cards
    .map(([k, v]) => `<article class="focus-kpi"><p>${k}</p><strong>${v}</strong></article>`)
    .join('');
}

function positionSnapshotCell(row) {
  const insight = row.insight || {};
  const pnlPct = Number(row.pnl_pct || 0);
  const realized = Number(row.realized_pnl_total || 0);
  const stopDist = row.distance_to_stop_pct;
  const takeDist = row.distance_to_take_pct;
  const pnlClass = numClass(pnlPct);
  const realizedClass = numClass(realized);

  return `
    <div class="position-snapshot">
      <div class="position-snapshot-top">
        <span class="pill ${pnlPct >= 0 ? 'up' : 'down'}">浮动 ${fmtPct(pnlPct)}</span>
        <span class="pill ${realized >= 0 ? 'up' : 'down'}">已实现 ${fmtNum(realized)}</span>
      </div>
      <div class="notice">${trendLabel(insight.trend_direction)} · <span class="zone ${zoneClass(insight.position_zone)}">${zoneLabel(insight.position_zone)}</span></div>
      <div class="position-snapshot-lines">
        <span class="${pnlClass}">距止损 ${fmtPct(stopDist)}</span>
        <span class="${realizedClass}">距止盈 ${fmtPct(takeDist)}</span>
      </div>
      <div class="notice">建议：${(insight.action_advice || row.operation_advice || '-').toString().slice(0, 34)}</div>
    </div>
  `;
}

function renderTable(rows) {
  const node = $('#positionTable');
  if (!rows.length) {
    node.innerHTML = '<tr><td class="muted">暂无持仓，请先新增。</td></tr>';
    return;
  }

  node.innerHTML = `
    <thead>
      <tr>
        <th>标的</th><th>现价/成本</th><th>浮动盈亏</th><th>实现盈亏</th><th>支撑/压力</th><th>止损/止盈</th><th>位置</th><th>趋势/收益</th><th>交易</th><th>操作</th>
      </tr>
    </thead>
    <tbody>
      ${rows.map((r) => {
        const insight = r.insight || {};
        const pnlClass = Number(r.pnl || 0) >= 0 ? 'up' : 'down';
        const realizedClass = Number(r.realized_pnl_total || 0) >= 0 ? 'up' : 'down';
        const selectedClass = Number(r.id) === Number(state.selectedPositionId || 0) ? 'is-selected' : '';

        return `<tr data-row-id="${r.id}" class="${selectedClass}">
          <td><strong>${r.symbol}</strong><br/><span class="notice">${r.name || '-'}</span></td>
          <td>${fmtNum(insight.current_price)} / ${fmtNum(r.cost_price)}</td>
          <td><span class="pill ${pnlClass}">${fmtNum(r.pnl)} (${fmtPct(r.pnl_pct)})</span></td>
          <td><span class="pill ${realizedClass}">${fmtNum(r.realized_pnl_total || 0)}</span></td>
          <td>${fmtNum(insight.support_price)} / ${fmtNum(insight.resistance_price)}</td>
          <td>${fmtNum(insight.stop_loss_price)} / ${fmtNum(insight.take_profit_price)}</td>
          <td><span class="zone ${zoneClass(insight.position_zone)}">${zoneLabel(insight.position_zone)}</span></td>
          <td>${positionSnapshotCell(r)}</td>
          <td>${r.trade_count || 0}<br/><span class="notice">${r.last_trade_type ? `${tradeLabel(r.last_trade_type)} @ ${r.last_traded_at || '-'}` : '-'}</span></td>
          <td>
            <button class="btn ghost tiny" data-edit-pos="${r.id}">编辑</button>
            <button class="btn ghost tiny" data-trade-pos="${r.id}">交易</button>
            <button class="btn ghost tiny" data-detail-pos="${r.id}">详情</button>
            <button class="btn tiny" data-del-pos="${r.id}">删除</button>
          </td>
        </tr>`;
      }).join('')}
    </tbody>
  `;

  $$('[data-edit-pos]').forEach((b) => b.addEventListener('click', () => editPosition(Number(b.dataset.editPos))));
  $$('[data-trade-pos]').forEach((b) => b.addEventListener('click', () => focusTrade(Number(b.dataset.tradePos))));
  $$('[data-detail-pos]').forEach((b) => b.addEventListener('click', () => openDetail(Number(b.dataset.detailPos))));
  $$('[data-del-pos]').forEach((b) => b.addEventListener('click', () => deletePosition(Number(b.dataset.delPos))));

  $$('[data-row-id]').forEach((rowNode) => {
    rowNode.addEventListener('click', (e) => {
      if (e.target.closest('button')) return;
      const id = Number(rowNode.dataset.rowId || 0);
      if (!id) return;

      state.selectedPositionId = id;
      renderTable(state.positions);
      setTradeTarget(state.positions.find((x) => Number(x.id) === id) || null);
      Promise.all([loadTrades(id), loadNotes(id), loadPositionDetail(id, false)]).catch(() => {});

      if (!state.sidePanelOpen) {
        const now = Date.now();
        if ((now - state.sideHintShownAt) > 3000) {
          state.sideHintShownAt = now;
          showToast('已选中持仓，点击右下角按钮展开右侧操作面板');
        }
      }
    });
  });
}

function renderTradeList(trades) {
  const node = $('#tradeList');
  if (!node) return;

  if (!trades.length) {
    node.innerHTML = '<p class="muted">暂无交易记录，选择持仓后可记录加仓/减仓/清仓。</p>';
    return;
  }

  node.innerHTML = trades.map((t, idx) => {
    const pnlClass = Number(t.realized_pnl || 0) >= 0 ? 'up' : 'down';
    const pnlText = t.realized_pnl === null || t.realized_pnl === undefined
      ? '-'
      : `<span class="pill ${pnlClass}">${fmtNum(t.realized_pnl)}</span>`;
    const undoBtn = idx === 0 ? `<button class="btn ghost tiny" data-undo-trade="${t.id}">撤销本笔</button>` : '';

    return `<article class="focus-card">
      <h4>${tradeLabel(t.trade_type)} · 数量 ${fmtNum(t.quantity, 4)} · 价格 ${fmtNum(t.price, 4)}</h4>
      <p>前仓位 ${fmtNum(t.before_quantity, 4)} / 后仓位 ${fmtNum(t.after_quantity, 4)} · 前成本 ${fmtNum(t.before_cost_price, 4)} / 后成本 ${fmtNum(t.after_cost_price, 4)}</p>
      <div class="focus-meta">
        <span>手续费 ${fmtNum(t.fee, 4)}</span>
        <span>成交额 ${fmtNum(t.amount, 4)}</span>
        <span>实现盈亏 ${pnlText}</span>
        <span>${t.traded_at || '-'}</span>
        ${undoBtn}
      </div>
      ${t.note ? `<p style="margin-top:6px;">备注: ${t.note}</p>` : ''}
    </article>`;
  }).join('');

  $$('[data-undo-trade]').forEach((btn) => {
    btn.addEventListener('click', () => undoTrade(Number(btn.dataset.undoTrade)));
  });
}

async function undoTrade(tradeId) {
  const positionId = Number(state.selectedPositionId || 0);
  if (!positionId || !tradeId) return;
  if (!confirm('确认撤销最近一笔交易吗？')) return;

  try {
    await api(`/api/positions/${positionId}/trades/${tradeId}/undo`, {
      method: 'POST',
      body: '{}',
    });
    showToast('最近一笔交易已撤销');
    await loadData();
    await Promise.all([loadTrades(positionId), loadNotes(positionId), loadPositionDetail(positionId, false)]);
  } catch (err) {
    showToast(`撤销失败: ${err.message}`);
  }
}

function renderNoteList(notes) {
  const node = $('#noteList');
  if (!node) return;

  if (!notes.length) {
    node.innerHTML = '<p class="muted">暂无同步记录，行情同步和建议同步后会自动出现。</p>';
    return;
  }

  node.innerHTML = notes.map((n) => `
    <article class="focus-card">
      <h4>${noteTypeLabel(n.note_type)}</h4>
      <p>${n.content || '-'}</p>
      <div class="focus-meta">
        <span>${n.created_at || '-'}</span>
        ${n.review_score !== null && n.review_score !== undefined ? `<span>评分 ${fmtNum(n.review_score, 0)}</span>` : ''}
      </div>
    </article>
  `).join('');
}

function setPositionForm(data = null) {
  const form = $('#positionForm');
  form.reset();
  if (form.elements.id) {
    form.elements.id.value = '';
  }
  state.formMode = 'create';
  if (!data) {
    updatePositionFormMode();
    return;
  }

  const fields = ['id', 'symbol', 'name', 'market', 'status', 'quantity', 'cost_price', 'current_price', 'stop_loss_price', 'take_profit_price', 'strategy', 'operation_advice'];
  fields.forEach((k) => {
    if (form.elements[k] && data[k] !== undefined && data[k] !== null) {
      form.elements[k].value = data[k];
    }
  });
  state.formMode = 'edit';
  updatePositionFormMode();
}

function switchToCreateMode(showMessage = false) {
  setPositionForm();
  if (showMessage) {
    showToast('已切换到新增模式，不会覆盖已有持仓');
  }
}

function setTradeTarget(position) {
  const tradeForm = $('#tradeForm');
  const target = $('#tradeTarget');

  if (!position) {
    state.selectedPositionId = null;
    state.detailPayload = null;
    tradeForm.reset();
    tradeForm.elements.position_id.value = '';
    target.textContent = '未选择持仓';
    renderTradeList([]);
    renderNoteList([]);
    clearTrendPanel();
    return;
  }

  state.selectedPositionId = Number(position.id);
  tradeForm.elements.position_id.value = String(position.id);
  tradeForm.elements.price.value = position?.insight?.current_price ?? position.current_price ?? '';
  tradeForm.elements.quantity.value = '';
  tradeForm.elements.fee.value = '0';
  tradeForm.elements.note.value = '';
  target.textContent = `${position.symbol} ${position.name || ''} (#${position.id})`;
}

async function loadTrades(positionId) {
  if (!positionId) {
    renderTradeList([]);
    return;
  }

  try {
    const data = await api(`/api/positions/${positionId}/trades?limit=60`);
    renderTradeList(data.trades || []);
  } catch (e) {
    renderTradeList([]);
    showToast(`加载交易记录失败: ${e.message}`);
  }
}

async function loadNotes(positionId) {
  if (!positionId) {
    renderNoteList([]);
    return;
  }

  try {
    const data = await api(`/api/positions/${positionId}/notes?limit=80`);
    renderNoteList(data.notes || []);
  } catch (e) {
    renderNoteList([]);
    showToast(`加载同步记录失败: ${e.message}`);
  }
}

function parseTimeToMs(v) {
  if (!v) return null;
  const text = String(v).trim();
  if (!text) return null;
  const ts = Date.parse(text.includes('T') ? text : text.replace(' ', 'T'));
  return Number.isFinite(ts) ? ts : null;
}

function clearTrendCanvas(message = '请选择一只持仓查看趋势图') {
  const canvas = $('#positionTrendCanvas');
  if (!canvas) return;

  const rect = canvas.getBoundingClientRect();
  const width = Math.max(420, Math.floor(rect.width || 720));
  const height = 250;
  const dpr = window.devicePixelRatio || 1;
  canvas.width = Math.floor(width * dpr);
  canvas.height = Math.floor(height * dpr);
  canvas.style.width = `${width}px`;
  canvas.style.height = `${height}px`;

  const ctx = canvas.getContext('2d');
  if (!ctx) return;
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  ctx.clearRect(0, 0, width, height);
  ctx.fillStyle = 'rgba(6, 16, 22, 0.92)';
  ctx.fillRect(0, 0, width, height);
  ctx.fillStyle = '#9eb2c1';
  ctx.font = '14px "Noto Sans SC", sans-serif';
  ctx.fillText(message, 20, 36);
}

function drawPositionTrendChart(quotes, overview, trades = []) {
  const canvas = $('#positionTrendCanvas');
  if (!canvas) return;

  const rect = canvas.getBoundingClientRect();
  const width = Math.max(420, Math.floor(rect.width || 760));
  const height = 250;
  const dpr = window.devicePixelRatio || 1;
  canvas.width = Math.floor(width * dpr);
  canvas.height = Math.floor(height * dpr);
  canvas.style.width = `${width}px`;
  canvas.style.height = `${height}px`;

  const ctx = canvas.getContext('2d');
  if (!ctx) return;
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  ctx.clearRect(0, 0, width, height);

  const series = (Array.isArray(quotes) ? quotes : [])
    .map((x) => ({
      price: Number(x?.price),
      ts: parseTimeToMs(x?.quote_time),
    }))
    .filter((x) => Number.isFinite(x.price) && x.price > 0);

  if (series.length < 2) {
    clearTrendCanvas('该持仓暂缺少足够行情样本，无法绘制趋势图');
    return;
  }

  const levels = [
    { key: 'current', label: '当前', value: Number(overview.current_price), color: '#ffc756', dash: [6, 3] },
    { key: 'cost', label: '成本', value: Number(overview.cost_price), color: '#58d7ff', dash: [4, 3] },
    { key: 'stop', label: '止损', value: Number(overview.stop_loss_price), color: '#59e6a5', dash: [3, 3] },
    { key: 'take', label: '止盈', value: Number(overview.take_profit_price), color: '#ff7b7b', dash: [3, 3] },
    { key: 'support', label: '支撑', value: Number(overview.support_price), color: '#6fe4ff', dash: [2, 3] },
    { key: 'resistance', label: '压力', value: Number(overview.resistance_price), color: '#ffbf63', dash: [2, 3] },
  ].filter((x) => Number.isFinite(x.value) && x.value > 0);

  const prices = series.map((x) => x.price);
  const allValues = prices.concat(levels.map((x) => x.value));
  const minRaw = Math.min(...allValues);
  const maxRaw = Math.max(...allValues);
  const pad = Math.max((maxRaw - minRaw) * 0.12, maxRaw * 0.006, 0.01);
  const min = minRaw - pad;
  const max = maxRaw + pad;

  const left = 50;
  const right = width - 16;
  const top = 16;
  const bottom = height - 30;
  const plotW = right - left;
  const plotH = bottom - top;
  const yOf = (value) => top + (1 - ((value - min) / Math.max(max - min, 0.00001))) * plotH;

  ctx.fillStyle = 'rgba(6, 16, 22, 0.92)';
  ctx.fillRect(0, 0, width, height);

  ctx.strokeStyle = 'rgba(255,255,255,0.08)';
  ctx.lineWidth = 1;
  for (let i = 0; i <= 4; i += 1) {
    const y = top + (plotH / 4) * i;
    ctx.beginPath();
    ctx.moveTo(left, y);
    ctx.lineTo(right, y);
    ctx.stroke();
  }

  const points = prices.map((p, idx) => {
    const x = left + (idx / (prices.length - 1)) * plotW;
    const y = yOf(p);
    return { x, y };
  });

  const area = ctx.createLinearGradient(left, top, left, bottom);
  area.addColorStop(0, 'rgba(88,215,255,0.32)');
  area.addColorStop(1, 'rgba(88,215,255,0.04)');

  ctx.beginPath();
  points.forEach((pt, i) => {
    if (i === 0) ctx.moveTo(pt.x, pt.y);
    else ctx.lineTo(pt.x, pt.y);
  });
  ctx.lineTo(points[points.length - 1].x, bottom);
  ctx.lineTo(points[0].x, bottom);
  ctx.closePath();
  ctx.fillStyle = area;
  ctx.fill();

  ctx.beginPath();
  points.forEach((pt, i) => {
    if (i === 0) ctx.moveTo(pt.x, pt.y);
    else ctx.lineTo(pt.x, pt.y);
  });
  ctx.strokeStyle = '#58d7ff';
  ctx.lineWidth = 2;
  ctx.stroke();

  levels.forEach((lv) => {
    const y = yOf(lv.value);
    ctx.save();
    ctx.setLineDash(lv.dash || []);
    ctx.strokeStyle = lv.color;
    ctx.lineWidth = 1.2;
    ctx.beginPath();
    ctx.moveTo(left, y);
    ctx.lineTo(right, y);
    ctx.stroke();
    ctx.restore();

    ctx.fillStyle = lv.color;
    ctx.font = '11px "Noto Sans SC", sans-serif';
    const textY = Math.max(top + 10, Math.min(bottom - 2, y - 3));
    ctx.fillText(`${lv.label} ${fmtNum(lv.value, 3)}`, right - 150, textY);
  });

  const markerTrades = Array.isArray(trades) ? [...trades].slice(0, 10).reverse() : [];
  const tsList = series.map((x) => x.ts);
  const nearestIndex = (ts) => {
    if (!Number.isFinite(ts)) return -1;
    let best = -1;
    let diff = Number.POSITIVE_INFINITY;
    for (let i = 0; i < tsList.length; i += 1) {
      const v = tsList[i];
      if (!Number.isFinite(v)) continue;
      const d = Math.abs(v - ts);
      if (d < diff) {
        diff = d;
        best = i;
      }
    }
    return best;
  };

  markerTrades.forEach((t) => {
    const idx = nearestIndex(parseTimeToMs(t.traded_at));
    if (idx < 0 || idx >= points.length) return;

    const pt = points[idx];
    const tp = (t.trade_type || '').toLowerCase();
    const color = tp === 'add' ? '#59e6a5' : (tp === 'reduce' ? '#ffbf63' : '#ff7b7b');
    const label = tp === 'add' ? '加' : (tp === 'reduce' ? '减' : '清');

    ctx.fillStyle = color;
    ctx.beginPath();
    ctx.arc(pt.x, pt.y, 3.8, 0, Math.PI * 2);
    ctx.fill();

    ctx.fillStyle = color;
    ctx.font = '10px "Noto Sans SC", sans-serif';
    ctx.fillText(label, pt.x + 5, pt.y - 5);
  });

  const latest = prices[prices.length - 1];
  ctx.fillStyle = '#9eb2c1';
  ctx.font = '12px "Noto Sans SC", sans-serif';
  ctx.fillText(`最低 ${fmtNum(minRaw, 3)}`, 10, bottom - 4);
  ctx.fillText(`最高 ${fmtNum(maxRaw, 3)}`, 10, top + 12);
  ctx.fillStyle = '#ffc756';
  ctx.fillText(`最新 ${fmtNum(latest, 3)}`, width - 108, top + 12);
}

function renderTrendLegend(overview) {
  const node = $('#positionTrendLegend');
  if (!node) return;

  const items = [
    ['当前', overview.current_price, 'current'],
    ['成本', overview.cost_price, 'cost'],
    ['止损', overview.stop_loss_price, 'stop'],
    ['止盈', overview.take_profit_price, 'take'],
    ['支撑', overview.support_price, 'support'],
    ['压力', overview.resistance_price, 'resistance'],
  ].filter((x) => Number.isFinite(Number(x[1])) && Number(x[1]) > 0);

  node.innerHTML = items.length
    ? items.map(([label, value, cls]) => `<span class="legend-chip ${cls}">${label}: ${fmtNum(value, 3)}</span>`).join('')
    : '<span class="notice">暂无关键价位数据</span>';
}

function renderTrendMetrics(overview, trades) {
  const node = $('#positionTrendMetrics');
  if (!node) return;

  const latestTrade = Array.isArray(trades) && trades.length ? trades[0] : null;
  const cards = [
    ['趋势/位置', `${trendLabel(overview.trend_direction)} · ${zoneLabel(overview.position_zone)}`],
    ['浮动收益', `<span class="${numClass(overview.pnl_pct)}">${fmtNum(overview.pnl)} (${fmtPct(overview.pnl_pct)})</span>`],
    ['已实现收益', `<span class="${numClass(overview.realized_pnl_total)}">${fmtNum(overview.realized_pnl_total || 0)}</span>`],
    ['当前/成本', `${fmtNum(overview.current_price)} / ${fmtNum(overview.cost_price)}`],
    ['距止损/止盈', `${fmtPct(overview.distance_to_stop_pct)} / ${fmtPct(overview.distance_to_take_pct)}`],
    ['最近操作', latestTrade ? `${tradeLabel(latestTrade.trade_type)} @ ${fmtNum(latestTrade.price)} (${latestTrade.traded_at || '-'})` : '-'],
    ['操作建议', (overview.action_advice || overview.operation_advice || '-').toString()],
  ];

  node.innerHTML = cards
    .map(([k, v], idx) => `<article class="detail-metric ${idx === cards.length - 1 ? 'full' : ''}"><p>${k}</p><strong>${v}</strong></article>`)
    .join('');
}

function clearTrendPanel() {
  const title = $('#trendPanelTitle');
  const sub = $('#trendPanelSub');
  const legend = $('#positionTrendLegend');
  const metrics = $('#positionTrendMetrics');

  if (title) title.textContent = '趋势复盘表现';
  if (sub) sub.textContent = '请选择一只持仓查看趋势、收益和关键价位。';
  if (legend) legend.innerHTML = '<span class="notice">暂无图例数据</span>';
  if (metrics) metrics.innerHTML = '<article class="detail-metric full"><p>提示</p><strong>先在表格中点击一个持仓，右侧会展示趋势图与收益拆解。</strong></article>';
  clearTrendCanvas();
}

function renderTrendPanel(payload) {
  if (!payload || !payload.overview) {
    clearTrendPanel();
    return;
  }

  const overview = payload.overview;
  const title = $('#trendPanelTitle');
  const sub = $('#trendPanelSub');
  if (title) {
    title.textContent = `${overview.symbol || '-'} ${overview.name || ''}`.trim();
  }
  if (sub) {
    sub.textContent = `趋势 ${trendLabel(overview.trend_direction)} · 区域 ${zoneLabel(overview.position_zone)} · 更新时间 ${payload.generated_at || '-'}`;
  }

  drawPositionTrendChart(payload.quote_series || [], overview, payload.recent_trades || []);
  renderTrendLegend(overview);
  renderTrendMetrics(overview, payload.recent_trades || []);
}

async function loadPositionDetail(positionId, showToastOnError = false) {
  if (!positionId) {
    state.detailPayload = null;
    clearTrendPanel();
    return;
  }

  state.detailLoading = true;
  try {
    const payload = await api(`/api/positions/${positionId}/detail?limit=220`, { timeoutMs: 25000 });
    if (Number(positionId) !== Number(state.selectedPositionId || 0)) {
      return;
    }
    state.detailPayload = payload;
    state.detailFetchedAt = Date.now();
    renderTrendPanel(payload);
  } catch (e) {
    if (showToastOnError) {
      showToast(`加载趋势失败: ${e.message}`);
    }
  } finally {
    state.detailLoading = false;
  }
}

function editPosition(id) {
  const row = state.positions.find((x) => Number(x.id) === id);
  if (!row) return;
  setPositionForm(row);
  setTradeTarget(row);
  setSidePanelOpen(true, false);
  renderTable(state.positions);
  Promise.all([loadTrades(id), loadNotes(id), loadPositionDetail(id, false)]).catch(() => {});
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function focusTrade(id) {
  const row = state.positions.find((x) => Number(x.id) === id);
  if (!row) return;
  setTradeTarget(row);
  setSidePanelOpen(true, false);
  renderTable(state.positions);
  Promise.all([loadTrades(id), loadNotes(id), loadPositionDetail(id, false)]).catch(() => {});
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function openDetail(id) {
  if (!id) return;
  openExternalPage(`/position-detail.html?id=${encodeURIComponent(String(id))}`);
}

async function deletePosition(id) {
  if (!id) return;
  if (!confirm(`确认删除持仓 #${id} 吗？`)) return;

  try {
    await api(`/api/positions/${id}`, { method: 'DELETE' });
    showToast('持仓已删除');

    if (state.selectedPositionId === id) {
      setTradeTarget(null);
    }

    await loadData();
  } catch (e) {
    showToast(`删除失败: ${e.message}`);
  }
}

function updateSidePanelButton() {
  const btn = $('#toggleSidePanelBtn');
  if (!btn) return;
  btn.textContent = `右侧面板: ${state.sidePanelOpen ? '开' : '关'}`;
}

function updateSidePanelFab() {
  const fab = $('#sidePanelFab');
  if (!fab) return;
  fab.textContent = state.sidePanelOpen ? '收起右侧面板' : '展开右侧面板';
  fab.setAttribute('aria-expanded', state.sidePanelOpen ? 'true' : 'false');
}

function applySidePanelState() {
  document.body.classList.toggle('side-panel-open', state.sidePanelOpen);
  const panel = $('#positionSidePanel');
  if (panel) {
    panel.setAttribute('aria-hidden', state.sidePanelOpen ? 'false' : 'true');
  }
  updateSidePanelButton();
  updateSidePanelFab();
}

function setSidePanelOpen(open, showTip = false) {
  state.sidePanelOpen = !!open;
  try {
    localStorage.setItem(SIDE_PANEL_STORAGE_KEY, state.sidePanelOpen ? '1' : '0');
  } catch (_) {
    // ignore
  }
  applySidePanelState();

  if (state.sidePanelOpen) {
    const firstInput = $('#positionForm input[name="symbol"]');
    if (firstInput && typeof firstInput.focus === 'function') {
      setTimeout(() => firstInput.focus(), 80);
    }
    if (state.detailPayload) {
      setTimeout(() => renderTrendPanel(state.detailPayload), 120);
    }
  }

  if (showTip) {
    showToast(state.sidePanelOpen ? '已展开右侧面板' : '已收起右侧面板');
  }
}

async function loadData() {
  if (state.loading) return;
  state.loading = true;

  try {
    const data = await api('/api/positions/analysis');
    state.positions = data.positions || [];
    renderKpi(data.summary || {});
    renderTable(state.positions);
    $('#generatedAt').textContent = data.generated_at || '-';

    if (state.selectedPositionId) {
      const selected = state.positions.find((x) => Number(x.id) === Number(state.selectedPositionId));
      if (selected) {
        setTradeTarget(selected);
        await Promise.all([loadTrades(state.selectedPositionId), loadNotes(state.selectedPositionId), loadPositionDetail(state.selectedPositionId, false)]);
      } else {
        setTradeTarget(null);
      }
    }
  } finally {
    state.loading = false;
  }
}

async function ensureAuth() {
  try {
    const me = await api('/api/auth/me');
    state.me = me.user;
  } catch {
    location.href = '/';
    throw new Error('unauthorized');
  }
}

function wirePositionForm() {
  const form = $('#positionForm');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = form.querySelector('button[type="submit"]');
    const oldText = submitBtn ? submitBtn.textContent : '';
    const fd = new FormData(e.target);
    const payload = Object.fromEntries(fd.entries());

    ['quantity', 'cost_price', 'current_price', 'stop_loss_price', 'take_profit_price'].forEach((k) => {
      if (payload[k] === '') payload[k] = null;
      else payload[k] = Number(payload[k]);
    });

    const id = state.formMode === 'edit' ? Number(payload.id || 0) : 0;

    try {
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = '保存中...';
      }

      if (id > 0) {
        payload.id = id;
        await api('/api/positions', { method: 'PUT', body: JSON.stringify(payload) });
        showToast('持仓已更新');
      } else {
        delete payload.id;
        await api('/api/positions', { method: 'POST', body: JSON.stringify(payload) });
        showToast('持仓已新增');
      }

      switchToCreateMode(false);
      await loadData();
      setTimeout(() => {
        loadData().catch(() => {});
      }, 500);
    } catch (err) {
      showToast(`保存失败: ${err.message}`);
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = oldText || '保存持仓';
      }
    }
  });

  $('#switchToCreateBtn')?.addEventListener('click', () => switchToCreateMode(true));
  $('#resetFormBtn').addEventListener('click', () => switchToCreateMode(false));
}

function wireTradeForm() {
  const form = $('#tradeForm');
  const tradeTypeSelect = form.elements.trade_type;
  const qtyInput = form.elements.quantity;

  tradeTypeSelect.addEventListener('change', () => {
    const type = tradeTypeSelect.value;
    if (type === 'clear') {
      qtyInput.value = '';
      qtyInput.placeholder = '清仓自动使用当前仓位';
    } else {
      qtyInput.placeholder = '请输入本次成交数量';
    }
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const positionId = Number(form.elements.position_id.value || 0);
    if (!positionId) {
      showToast('请先在表格中选择要交易的持仓');
      return;
    }

    const payload = {
      trade_type: form.elements.trade_type.value,
      quantity: form.elements.quantity.value === '' ? null : Number(form.elements.quantity.value),
      price: Number(form.elements.price.value),
      fee: form.elements.fee.value === '' ? 0 : Number(form.elements.fee.value),
      note: form.elements.note.value || '',
    };

    try {
      const res = await api(`/api/positions/${positionId}/trades`, {
        method: 'POST',
        body: JSON.stringify(payload),
      });

      showToast(`交易已记录: ${tradeLabel(res.trade_type)}`);
      await loadData();
      await Promise.all([loadTrades(positionId), loadNotes(positionId), loadPositionDetail(positionId, false)]);
    } catch (err) {
      showToast(`交易记录失败: ${err.message}`);
    }
  });
}

function wireActions() {
  $('#openDashboardBtn')?.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    openExternalPage('/');
  });

  $('#openWatchlistBtn')?.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    openExternalPage('/watchlist.html');
  });

  $('#openSectorsBtn')?.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    openExternalPage('/sectors.html');
  });
  $('#openOpportunitiesBtn')?.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    openExternalPage('/opportunities.html');
  });
  $('#openHealthBtn')?.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    openExternalPage('/health.html');
  });

  $('#newPositionBtn')?.addEventListener('click', () => {
    switchToCreateMode(true);
    setSidePanelOpen(true, false);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  $('#toggleSidePanelBtn')?.addEventListener('click', () => {
    setSidePanelOpen(!state.sidePanelOpen, true);
  });

  $('#sidePanelFab')?.addEventListener('click', () => {
    setSidePanelOpen(!state.sidePanelOpen, true);
  });

  $('#closeSidePanelBtn')?.addEventListener('click', () => {
    setSidePanelOpen(false, true);
  });

  $('#sidePanelMask')?.addEventListener('click', () => {
    setSidePanelOpen(false);
  });

  $('#refreshTrendBtn')?.addEventListener('click', async () => {
    if (!state.selectedPositionId) {
      showToast('请先选择一只持仓');
      return;
    }
    await loadPositionDetail(state.selectedPositionId, true);
  });

  $('#refreshBtn').addEventListener('click', () => loadData().catch((e) => showToast(e.message)));

  $('#logoutBtn').addEventListener('click', async () => {
    await api('/api/auth/logout', { method: 'POST', body: '{}' }).catch(() => {});
    location.href = '/';
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && state.sidePanelOpen) {
      setSidePanelOpen(false);
    }
  });

  window.addEventListener('resize', () => {
    if (state.detailPayload) {
      renderTrendPanel(state.detailPayload);
    }
  });
}

async function boot() {
  try {
    const saved = localStorage.getItem(SIDE_PANEL_STORAGE_KEY);
    state.sidePanelOpen = saved === '1';
  } catch (_) {
    state.sidePanelOpen = false;
  }

  applySidePanelState();
  clearTrendPanel();
  switchToCreateMode(false);

  await ensureAuth();
  wirePositionForm();
  wireTradeForm();
  wireActions();

  await loadData();

  setInterval(() => {
    loadData().catch(() => {});
  }, 15000);
}

boot().catch(() => {});

