const state = {
  me: null,
  rows: [],
  selectedId: null,
  autoTicking: false,
  sortByScore: true,
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

function scoreClass(score) {
  const n = Number(score || 0);
  if (n >= 78) return 'score-a-plus';
  if (n >= 65) return 'score-a';
  if (n >= 50) return 'score-b';
  return 'score-c';
}

function scoreText(row) {
  const score = Number(row.ranking_score || 0);
  const grade = row.ranking_grade || '-';
  const tag = row.ranking_tag || '-';
  return `<span class="score-badge ${scoreClass(score)}">${fmtNum(score, 0)} · ${grade}</span><br/><span class="notice">${tag}</span>`;
}

function renderKpi(rows) {
  const total = rows.length;
  const active = rows.filter((x) => x.status === 'active').length;
  const highPriority = rows.filter((x) => Number(x.priority || 999) <= 20).length;
  const avgScore = rows.length
    ? rows.reduce((s, x) => s + Number(x.ranking_score || 0), 0) / rows.length
    : 0;
  const focusCount = rows.filter((x) => Number(x.ranking_score || 0) >= 65).length;

  const cards = [
    ['池内股票', total],
    ['持续跟踪', active],
    ['高优先级', highPriority],
    ['平均评分', fmtNum(avgScore, 1)],
    ['重点/优先', focusCount],
  ];

  $('#watchKpi').innerHTML = cards
    .map(([k, v]) => `<article class="focus-kpi"><p>${k}</p><strong>${typeof v === 'number' ? fmtNum(v, 0) : v}</strong></article>`)
    .join('');
}

function compareRowsByScore(a, b) {
  const scoreA = Number(a.ranking_score || 0);
  const scoreB = Number(b.ranking_score || 0);
  if (scoreA !== scoreB) return scoreB - scoreA;

  const vrA = Number(a.volume_ratio || 0);
  const vrB = Number(b.volume_ratio || 0);
  if (vrA !== vrB) return vrB - vrA;

  const pA = Number(a.priority || 999);
  const pB = Number(b.priority || 999);
  if (pA !== pB) return pA - pB;

  return Number(b.id || 0) - Number(a.id || 0);
}

function compareRowsByPriority(a, b) {
  const pA = Number(a.priority || 999);
  const pB = Number(b.priority || 999);
  if (pA !== pB) return pA - pB;
  return Number(b.id || 0) - Number(a.id || 0);
}

function sortedRows(rows) {
  const cloned = [...rows];
  cloned.sort(state.sortByScore ? compareRowsByScore : compareRowsByPriority);
  return cloned;
}

function renderTable(rows) {
  const node = $('#watchTable');

  if (!rows.length) {
    node.innerHTML = '<tr><td class="muted">暂无关注股，右侧可新增。</td></tr>';
    return;
  }

  const data = sortedRows(rows);

  node.innerHTML = `
    <thead>
      <tr>
        <th>标的</th><th>板块/方向</th><th>综合评分</th><th>优先级</th><th>现价</th><th>量能</th><th>支撑/压力</th><th>位置</th><th>建议</th><th>OpenClaw</th><th>操作</th>
      </tr>
    </thead>
    <tbody>
      ${data.map((r) => {
        const volText = r.volume_ratio !== null && r.volume_ratio !== undefined
          ? `${fmtNum(r.volume_ratio, 2)}x`
          : '-';
        return `
          <tr data-row-id="${r.id}">
            <td><strong>${r.symbol}</strong><br/><span class="notice">${r.name || '-'}</span></td>
            <td>${r.sector_name || '-'}<br/><span class="notice">${trendLabel(r.trend_direction)}</span></td>
            <td>${scoreText(r)}</td>
            <td>${fmtNum(r.priority, 0)}<br/><span class="notice">${r.status}</span></td>
            <td>${fmtNum(r.current_price)}</td>
            <td>${volText}<br/><span class="notice">均量20: ${fmtNum(r.avg_volume_20, 0)}</span></td>
            <td>${fmtNum(r.support_price)} / ${fmtNum(r.resistance_price)}</td>
            <td><span class="zone ${zoneClass(r.position_zone)}">${zoneLabel(r.position_zone)}</span></td>
            <td>${r.ranking_action || r.action_advice || '-'}</td>
            <td>${(r.openclaw_note || '-').toString().slice(0, 56)}</td>
            <td>
              <button class="btn ghost tiny" data-edit="${r.id}">编辑</button>
              <button class="btn ghost tiny" data-analyze="${r.id}">分析</button>
              <button class="btn ghost tiny" data-detail="${r.id}">详情</button>
              <button class="btn tiny" data-delete="${r.id}">删除</button>
            </td>
          </tr>
        `;
      }).join('')}
    </tbody>
  `;

  $$('[data-edit]').forEach((b) => b.addEventListener('click', () => editRow(Number(b.dataset.edit))));
  $$('[data-analyze]').forEach((b) => b.addEventListener('click', () => analyzeOne(Number(b.dataset.analyze))));
  $$('[data-detail]').forEach((b) => b.addEventListener('click', () => openDetail(Number(b.dataset.detail))));
  $$('[data-delete]').forEach((b) => b.addEventListener('click', () => deleteRow(Number(b.dataset.delete))));

  $$('[data-row-id]').forEach((rowNode) => {
    rowNode.addEventListener('click', (e) => {
      if (e.target.closest('button')) return;
      const id = Number(rowNode.dataset.rowId);
      state.selectedId = id;
      loadInsights(id).catch(() => {});
    });
  });
}

function setForm(row = null) {
  const form = $('#watchForm');
  form.reset();

  if (!row) return;

  const fields = ['id', 'symbol', 'name', 'market', 'status', 'priority', 'thesis'];
  fields.forEach((k) => {
    if (form.elements[k] && row[k] !== undefined && row[k] !== null) {
      form.elements[k].value = row[k];
    }
  });
}

function editRow(id) {
  const row = state.rows.find((x) => Number(x.id) === id);
  if (!row) return;
  setForm(row);
  state.selectedId = id;
  loadInsights(id).catch(() => {});
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function deleteRow(id) {
  if (!id) return;
  if (!confirm(`确认删除关注股 #${id} 吗？`)) return;

  try {
    await api(`/api/watchlist/${id}`, { method: 'DELETE' });
    showToast('已删除');
    if (state.selectedId === id) {
      state.selectedId = null;
      $('#insightList').innerHTML = '';
    }
    await loadWatchlist();
  } catch (e) {
    showToast(`删除失败: ${e.message}`);
  }
}

async function analyzeOne(id) {
  if (!id) return;
  try {
    await api(`/api/watchlist/${id}/analyze`, { method: 'POST', body: '{}' });
    showToast('分析完成');
    await loadWatchlist();
    state.selectedId = id;
    await loadInsights(id);
  } catch (e) {
    showToast(`分析失败: ${e.message}`);
  }
}

async function analyzeAll(showMessage = true) {
  if (state.autoTicking) return;
  state.autoTicking = true;
  try {
    let ingestStatus = 'skipped';
    try {
      const ingest = await api('/api/system/ingest/once', { method: 'POST', body: '{}' });
      ingestStatus = (ingest.status || 'ok').toString();
    } catch (e) {
      ingestStatus = 'failed';
      if (showMessage) {
        showToast(`行情/新闻刷新失败，继续使用已有数据分析: ${e.message}`);
      }
    }
    let cursor = 0;
    let rounds = 0;
    let batchTotal = 0;
    let successTotal = 0;
    let failedTotal = 0;
    let hasMore = true;
    let totalActive = 0;
    while (hasMore && rounds < 30) {
      rounds += 1;
      const res = await api('/api/watchlist/analyze-all', {
        method: 'POST',
        body: JSON.stringify({ cursor, limit: 25 }),
        timeoutMs: 20000,
      });
      batchTotal += Number(res.batch_total || 0);
      successTotal += Number(res.success || 0);
      failedTotal += Number(res.failed || 0);
      cursor = Number(res.next_cursor || cursor);
      hasMore = !!res.has_more;
      totalActive = Number(res.total_active || totalActive);
      if (Number(res.batch_total || 0) === 0) {
        break;
      }
    }
    state.sortByScore = true;
    if (showMessage) {
      showToast(`全池分析完成并按综合评分重排: ${successTotal}/${Math.max(totalActive, batchTotal)}，失败 ${failedTotal} (数据刷新:${ingestStatus})`);
    }
    await loadWatchlist();
    if (state.selectedId) {
      await loadInsights(state.selectedId);
    }
  } catch (e) {
    if (showMessage) {
      showToast(`全池分析失败: ${e.message}`);
    }
  } finally {
    state.autoTicking = false;
  }
}
async function loadInsights(id) {
  if (!id) return;

  const data = await api(`/api/watchlist/${id}/insights?limit=20`);
  const rows = data.insights || [];
  const node = $('#insightList');

  if (!rows.length) {
    node.innerHTML = '<p class="muted">暂无分析历史，点击“分析”或“全池分析”。</p>';
    return;
  }

  node.innerHTML = rows
    .map((x) => `<article class="focus-card">
      <h4>${x.symbol} · ${zoneLabel(x.position_zone || 'unknown')} · 置信度 ${fmtNum(x.confidence)}</h4>
      <p>现价 ${fmtNum(x.current_price)} · 支撑 ${fmtNum(x.support_price)} · 压力 ${fmtNum(x.resistance_price)}</p>
      <p>${x.action_advice || '-'}</p>
      <div class="focus-meta">
        <span>止损 ${fmtNum(x.stop_loss_price)}</span>
        <span>止盈 ${fmtNum(x.take_profit_price)}</span>
        <span>${x.analyzed_at || '-'}</span>
      </div>
    </article>`)
    .join('');
}

function updateSortButton() {
  const btn = $('#sortByScoreBtn');
  if (!btn) return;
  btn.textContent = `综合排序: ${state.sortByScore ? '开' : '关'}`;
}

function renderAll() {
  renderKpi(state.rows);
  renderTable(state.rows);
  const mode = state.sortByScore ? '综合评分排序' : '优先级排序';
  $('#watchStamp').textContent = `${new Date().toLocaleString('zh-CN')} · ${mode}`;
  updateSortButton();
}

async function loadWatchlist() {
  if (state.loading) return;
  state.loading = true;
  try {
    const data = await api('/api/watchlist');
    const rows = data.watchlist || [];
    state.rows = rows;
    renderAll();
    if (state.selectedId) {
      await loadInsights(state.selectedId).catch(() => {});
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

function wireForm() {
  $('#watchForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const payload = Object.fromEntries(fd.entries());
    payload.priority = Number(payload.priority || 50);

    const id = Number(payload.id || 0);
    try {
      if (id > 0) {
        delete payload.id;
        await api(`/api/watchlist/${id}`, { method: 'PUT', body: JSON.stringify(payload) });
        state.selectedId = id;
        showToast('关注股已更新');
      } else {
        delete payload.id;
        const created = await api('/api/watchlist', { method: 'POST', body: JSON.stringify(payload) });
        state.selectedId = Number(created.id || 0);
        showToast('关注股已新增');
      }

      setForm();
      await loadWatchlist();
      if (state.selectedId) {
        await analyzeOne(state.selectedId);
      }
    } catch (err) {
      showToast(`保存失败: ${err.message}`);
    }
  });

  $('#resetFormBtn').addEventListener('click', () => setForm());
}

function wireActions() {
  $('#openDashboardBtn')?.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    openExternalPage('/');
  });

  $('#openPositionsBtn')?.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    openExternalPage('/positions.html');
  });
  $('#openSectorsBtn')?.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    openExternalPage('/sectors.html');
  });

  $('#sortByScoreBtn')?.addEventListener('click', () => {
    state.sortByScore = !state.sortByScore;
    renderAll();
    showToast(state.sortByScore ? '已启用综合评分排序' : '已切换为优先级排序');
  });

  $('#refreshBtn').addEventListener('click', () => loadWatchlist().catch((e) => showToast(e.message)));
  $('#analyzeAllBtn').addEventListener('click', () => analyzeAll(true));

  $('#logoutBtn').addEventListener('click', async () => {
    await api('/api/auth/logout', { method: 'POST', body: '{}' }).catch(() => {});
    location.href = '/';
  });
}

function openDetail(id) {
  if (!id) return;
  openExternalPage(`/watch-detail.html?id=${encodeURIComponent(String(id))}`);
}

async function boot() {
  await ensureAuth();
  wireForm();
  wireActions();
  updateSortButton();
  await loadWatchlist();

  setInterval(() => {
    loadWatchlist().catch(() => {});
  }, 20000);
}

boot().catch(() => {});

