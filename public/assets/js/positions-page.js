const state = {
  positions: [],
  me: null,
  selectedPositionId: null,
  loading: false,
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

function zoneClass(zone) {
  return ['support_zone', 'middle_zone', 'resistance_zone', 'unknown'].includes(zone) ? zone : 'unknown';
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

function noteTypeLabel(type) {
  if (type === 'auto_sync') return '行情同步';
  if (type === 'openclaw_sync') return '建议同步';
  if (type === 'advice') return '人工建议';
  return type || '记录';
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

function renderTable(rows) {
  const node = $('#positionTable');
  if (!rows.length) {
    node.innerHTML = '<tr><td class="muted">暂无持仓，请先新增。</td></tr>';
    return;
  }

  node.innerHTML = `
    <thead>
      <tr>
        <th>标的</th><th>现价/成本</th><th>浮动盈亏</th><th>实现盈亏</th><th>支撑/压力</th><th>止损/止盈</th><th>位置</th><th>交易</th><th>操作</th>
      </tr>
    </thead>
    <tbody>
      ${rows.map((r) => {
        const insight = r.insight || {};
        const pnlClass = Number(r.pnl || 0) >= 0 ? 'up' : 'down';
        const realizedClass = Number(r.realized_pnl_total || 0) >= 0 ? 'up' : 'down';
        return `<tr>
          <td><strong>${r.symbol}</strong><br/><span class="notice">${r.name || '-'}</span></td>
          <td>${fmtNum(insight.current_price)} / ${fmtNum(r.cost_price)}</td>
          <td><span class="pill ${pnlClass}">${fmtNum(r.pnl)} (${fmtPct(r.pnl_pct)})</span></td>
          <td><span class="pill ${realizedClass}">${fmtNum(r.realized_pnl_total || 0)}</span></td>
          <td>${fmtNum(insight.support_price)} / ${fmtNum(insight.resistance_price)}</td>
          <td>${fmtNum(insight.stop_loss_price)} / ${fmtNum(insight.take_profit_price)}</td>
          <td><span class="zone ${zoneClass(insight.position_zone)}">${zoneLabel(insight.position_zone)}</span></td>
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
    await Promise.all([loadTrades(positionId), loadNotes(positionId)]);
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
  if (!data) return;

  const fields = ['id', 'symbol', 'name', 'market', 'status', 'quantity', 'cost_price', 'current_price', 'stop_loss_price', 'take_profit_price', 'strategy', 'operation_advice'];
  fields.forEach((k) => {
    if (form.elements[k] && data[k] !== undefined && data[k] !== null) {
      form.elements[k].value = data[k];
    }
  });
}

function setTradeTarget(position) {
  const tradeForm = $('#tradeForm');
  const target = $('#tradeTarget');

  if (!position) {
    state.selectedPositionId = null;
    tradeForm.reset();
    tradeForm.elements.position_id.value = '';
    target.textContent = '未选择持仓';
    renderTradeList([]);
    renderNoteList([]);
    return;
  }

  state.selectedPositionId = Number(position.id);
  tradeForm.elements.position_id.value = String(position.id);
  tradeForm.elements.price.value = position.current_price ?? '';
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

function editPosition(id) {
  const row = state.positions.find((x) => Number(x.id) === id);
  if (!row) return;
  setPositionForm(row);
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function focusTrade(id) {
  const row = state.positions.find((x) => Number(x.id) === id);
  if (!row) return;
  setTradeTarget(row);
  Promise.all([loadTrades(id), loadNotes(id)]).catch(() => {});
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
        await Promise.all([loadTrades(state.selectedPositionId), loadNotes(state.selectedPositionId)]);
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

    const id = Number(payload.id || 0);

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

      setPositionForm();
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

  $('#resetFormBtn').addEventListener('click', () => setPositionForm());
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
      await Promise.all([loadTrades(positionId), loadNotes(positionId)]);
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

  $('#refreshBtn').addEventListener('click', () => loadData().catch((e) => showToast(e.message)));

  $('#logoutBtn').addEventListener('click', async () => {
    await api('/api/auth/logout', { method: 'POST', body: '{}' }).catch(() => {});
    location.href = '/';
  });
}

async function boot() {
  await ensureAuth();
  wirePositionForm();
  wireTradeForm();
  wireActions();

  await loadData();

  setInterval(() => {
    loadData().catch(() => {});
  }, 20000);
}

boot().catch(() => {});
