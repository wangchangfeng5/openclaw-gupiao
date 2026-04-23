const state = {
  me: null,
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

function scoreClass(score) {
  const n = Number(score || 0);
  if (n >= 85) return 'score-a-plus';
  if (n >= 72) return 'score-a';
  if (n >= 60) return 'score-b';
  return 'score-c';
}

function sectorCards(payload) {
  return payload.hot_sectors || [];
}

function themeCards(payload) {
  return payload.custom_themes || [];
}

function flattenTopStocks(payload) {
  const map = new Map();
  const sectors = sectorCards(payload);
  const themes = themeCards(payload);

  sectors.forEach((sector) => {
    (sector.strong_stocks || []).forEach((stock) => {
      const key = `${stock.market || 'A_STOCK_MAIN'}:${stock.symbol}`;
      if (!map.has(key)) {
        map.set(key, {
          ...stock,
          sources: [`板块:${sector.sector_name}`],
        });
        return;
      }
      const curr = map.get(key);
      curr.hot_score = Math.max(Number(curr.hot_score || 0), Number(stock.hot_score || 0));
      curr.sources.push(`板块:${sector.sector_name}`);
    });
  });

  themes.forEach((theme) => {
    (theme.strong_stocks || []).forEach((stock) => {
      const key = `${stock.market || 'A_STOCK_MAIN'}:${stock.symbol}`;
      if (!map.has(key)) {
        map.set(key, {
          ...stock,
          sources: [`题材:${theme.theme_name}`],
        });
        return;
      }
      const curr = map.get(key);
      curr.hot_score = Math.max(Number(curr.hot_score || 0), Number(stock.hot_score || 0));
      curr.sources.push(`题材:${theme.theme_name}`);
    });
  });

  const rows = Array.from(map.values());
  rows.sort((a, b) => Number(b.hot_score || 0) - Number(a.hot_score || 0));
  return rows.slice(0, 28);
}

function renderKpi(payload) {
  const sectors = sectorCards(payload);
  const themes = themeCards(payload);
  const topStocks = flattenTopStocks(payload);
  const activeThemes = themes.filter((x) => (x.status || '') === 'active').length;
  const positive = topStocks.filter((x) => Number(x.change_pct || 0) > 0).length;

  const cards = [
    ['热门板块', fmtNum(sectors.length, 0)],
    ['强势股票', fmtNum(topStocks.length, 0)],
    ['我的题材', fmtNum(themes.length, 0)],
    ['启用题材', fmtNum(activeThemes, 0)],
    ['上涨占比', sectors.length ? fmtPct((positive / Math.max(1, topStocks.length)) * 100) : '-'],
  ];

  $('#sectorKpi').innerHTML = cards
    .map(([k, v]) => `<article class="focus-kpi"><p>${k}</p><strong>${v}</strong></article>`)
    .join('');
}

function stockCardHtml(stock, sourceTag) {
  const score = Number(stock.hot_score || 0);
  const source = sourceTag ? `<span class="score-badge ${scoreClass(score)}">${sourceTag}</span>` : '';
  return `<article class="focus-card">
    <h4>${stock.symbol} ${stock.name || ''}</h4>
    <p>${source} <span class="notice">${stock.sector_name || '-'}</span></p>
    <p>价格 ${fmtNum(stock.price)} · 涨跌 ${fmtPct(stock.change_pct)} · ${trendLabel(stock.trend_direction)}</p>
    <p>评分 ${fmtNum(score, 1)} · ${stock.action_advice || '-'}</p>
    <div class="focus-meta">
      <span>量 ${fmtNum(stock.volume, 0)}</span>
      <span>${stock.quote_time || '-'}</span>
    </div>
    <div style="margin-top:8px;">
      <button class="btn ghost tiny" data-add-watch-symbol="${stock.symbol}" data-add-watch-name="${stock.name || ''}" data-add-watch-market="${stock.market || 'A_STOCK_MAIN'}" data-add-watch-sector="${stock.sector_name || ''}">加入持续关注池</button>
    </div>
  </article>`;
}

function bindAddWatchButtons() {
  $$('[data-add-watch-symbol]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const symbol = (btn.dataset.addWatchSymbol || '').trim();
      if (!symbol) return;

      const payload = {
        symbol,
        name: (btn.dataset.addWatchName || '').trim(),
        market: (btn.dataset.addWatchMarket || 'A_STOCK_MAIN').trim(),
        priority: 20,
        status: 'active',
        thesis: `来自板块雷达: ${(btn.dataset.addWatchSector || '').trim() || '热门题材'}`,
      };

      try {
        await api('/api/watchlist', {
          method: 'POST',
          body: JSON.stringify(payload),
        });
        showToast(`${symbol} 已加入持续关注池`);
      } catch (e) {
        showToast(`加入关注池失败: ${e.message}`);
      }
    });
  });
}

function renderSectors(payload) {
  const rows = sectorCards(payload);
  const node = $('#hotSectorList');
  if (!node) return;

  if (!rows.length) {
    node.innerHTML = '<p class="muted">暂无热门板块数据</p>';
    return;
  }

  node.innerHTML = rows
    .map((sector) => {
      const stocks = sector.strong_stocks || [];
      return `<article class="focus-card">
        <h4>${sector.sector_name || '-'}</h4>
        <p>强度 ${fmtNum(sector.strength_score)} · 涨跌 ${fmtPct(sector.change_pct)} · 龙头 ${sector.leading_symbol || '-'}</p>
        <div class="focus-meta">
          <span>活跃数 ${fmtNum(sector.active_count, 0)}</span>
          <span>${sector.sample_time || '-'}</span>
          <span>${sector.source || '-'}</span>
        </div>
        <div class="focus-list compact" style="margin-top:10px;">
          ${stocks.length
            ? stocks.map((stock) => stockCardHtml(stock, '板块强势')).join('')
            : '<p class="muted">当前未识别到该板块强势股</p>'}
        </div>
      </article>`;
    })
    .join('');

  bindAddWatchButtons();
}

function fillThemeForm(theme = null) {
  const form = $('#themeForm');
  if (!form) return;
  form.reset();

  if (!theme) {
    form.elements.id.value = '';
    form.elements.status.value = 'active';
    form.elements.priority.value = '50';
    return;
  }

  form.elements.id.value = String(theme.id || '');
  form.elements.theme_name.value = theme.theme_name || '';
  form.elements.keywords.value = Array.isArray(theme.keywords_list) ? theme.keywords_list.join(',') : (theme.keywords || '');
  form.elements.note.value = theme.note || '';
  form.elements.priority.value = String(theme.priority || 50);
  form.elements.status.value = theme.status || 'active';
}

function renderThemes(payload) {
  const rows = themeCards(payload);
  const node = $('#themeList');
  if (!node) return;

  if (!rows.length) {
    node.innerHTML = '<p class="muted">暂无自定义题材，先新增你看好的题材。</p>';
    return;
  }

  node.innerHTML = rows
    .map((theme) => {
      const stocks = theme.strong_stocks || [];
      return `<article class="focus-card">
        <h4>${theme.theme_name}</h4>
        <p>关键词: ${(theme.keywords_list || []).join(' / ') || '-'}</p>
        <p>状态: ${theme.status || '-'} · 优先级 ${fmtNum(theme.priority, 0)} · 命中 ${fmtNum(theme.hot_count, 0)}</p>
        ${theme.note ? `<p>${theme.note}</p>` : ''}
        <div class="focus-meta">
          <span>${theme.updated_at || theme.created_at || '-'}</span>
          ${(theme.matched_sectors || []).length ? `<span>匹配板块: ${(theme.matched_sectors || []).join(' / ')}</span>` : ''}
        </div>
        <div class="focus-meta actions">
          <button class="btn ghost tiny" data-theme-edit="${theme.id}">编辑</button>
          <button class="btn tiny" data-theme-del="${theme.id}">删除</button>
        </div>
        <div class="focus-list compact" style="margin-top:8px;">
          ${stocks.length
            ? stocks.map((stock) => stockCardHtml(stock, '题材强势')).join('')
            : '<p class="muted">该题材暂无匹配强势股</p>'}
        </div>
      </article>`;
    })
    .join('');

  $$('[data-theme-edit]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = Number(btn.dataset.themeEdit || 0);
      const row = rows.find((x) => Number(x.id) === id);
      if (!row) return;
      fillThemeForm(row);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  });

  $$('[data-theme-del]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = Number(btn.dataset.themeDel || 0);
      if (!id) return;
      if (!confirm(`确认删除题材 #${id} 吗？`)) return;

      try {
        await api(`/api/market/themes/${id}`, { method: 'DELETE' });
        showToast('题材已删除');
        await loadData(false);
      } catch (e) {
        showToast(`删除失败: ${e.message}`);
      }
    });
  });

  bindAddWatchButtons();
}

function renderTopStocks(payload) {
  const rows = flattenTopStocks(payload);
  const node = $('#topStockList');
  if (!node) return;

  if (!rows.length) {
    node.innerHTML = '<p class="muted">暂无热门股数据</p>';
    return;
  }

  node.innerHTML = rows.map((stock, idx) => {
    const source = (stock.sources || []).slice(0, 3).join(' | ');
    return `<article class="focus-card">
      <h4>#${idx + 1} ${stock.symbol} ${stock.name || ''}</h4>
      <p>${source || '-'} · ${stock.sector_name || '-'}</p>
      <p>价格 ${fmtNum(stock.price)} · 涨跌 ${fmtPct(stock.change_pct)} · ${trendLabel(stock.trend_direction)}</p>
      <p>评分 ${fmtNum(stock.hot_score, 1)} · ${stock.action_advice || '-'}</p>
      <div class="focus-meta">
        <span>量 ${fmtNum(stock.volume, 0)}</span>
        <span>${stock.quote_time || '-'}</span>
      </div>
      <div style="margin-top:8px;">
        <button class="btn ghost tiny" data-add-watch-symbol="${stock.symbol}" data-add-watch-name="${stock.name || ''}" data-add-watch-market="${stock.market || 'A_STOCK_MAIN'}" data-add-watch-sector="${stock.sector_name || ''}">加入持续关注池</button>
      </div>
    </article>`;
  }).join('');

  bindAddWatchButtons();
}

function render(payload) {
  renderKpi(payload);
  renderSectors(payload);
  renderThemes(payload);
  renderTopStocks(payload);
  $('#sectorStamp').textContent = `${new Date().toLocaleString('zh-CN')} · 自动刷新 15s`;
}

async function maybeIngest(force = false) {
  const now = Date.now();
  if (!force && now - state.lastIngestAt < 60000) {
    return;
  }
  if (state.syncing) return;

  state.syncing = true;
  try {
    const result = await api('/api/system/ingest/once', { method: 'POST', body: '{}', timeoutMs: 8000 });
    const status = (result.status || '').toString().toLowerCase();
    if (['accepted', 'running', 'cooldown', 'ok', 'partial_failed'].includes(status)) {
      state.lastIngestAt = Date.now();
    }
  } catch (e) {
    if (force) {
      showToast(`同步失败: ${e.message}`);
    }
  } finally {
    state.syncing = false;
  }
}

async function loadData(forceSync = false) {
  if (forceSync) {
    await maybeIngest(true);
  } else {
    maybeIngest(false).catch(() => {});
  }

  const payload = await api('/api/market/themes/overview?sector_limit=16&stock_limit=8');
  state.payload = payload;
  render(payload);
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

function wireThemeForm() {
  const form = $('#themeForm');
  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    const payload = Object.fromEntries(fd.entries());
    payload.priority = Number(payload.priority || 50);

    const id = Number(payload.id || 0);
    try {
      if (id > 0) {
        delete payload.id;
        await api(`/api/market/themes/${id}`, {
          method: 'PUT',
          body: JSON.stringify(payload),
        });
        showToast('题材已更新');
      } else {
        delete payload.id;
        await api('/api/market/themes', {
          method: 'POST',
          body: JSON.stringify(payload),
        });
        showToast('题材已新增');
      }

      fillThemeForm(null);
      await loadData(false);
    } catch (err) {
      showToast(`保存失败: ${err.message}`);
    }
  });

  $('#resetThemeBtn')?.addEventListener('click', () => fillThemeForm(null));
}

function wireActions() {
  $('#openDashboardBtn')?.addEventListener('click', () => openExternalPage('/'));
  $('#openPositionsBtn')?.addEventListener('click', () => openExternalPage('/positions.html'));
  $('#openWatchlistBtn')?.addEventListener('click', () => openExternalPage('/watchlist.html'));
  $('#openOpportunitiesBtn')?.addEventListener('click', () => openExternalPage('/opportunities.html'));

  $('#syncNowBtn')?.addEventListener('click', async () => {
    await loadData(true).catch((e) => showToast(e.message));
  });

  $('#refreshBtn')?.addEventListener('click', async () => {
    await loadData(false).catch((e) => showToast(e.message));
  });

  $('#logoutBtn')?.addEventListener('click', async () => {
    await api('/api/auth/logout', { method: 'POST', body: '{}' }).catch(() => {});
    location.href = '/';
  });
}

async function boot() {
  await ensureAuth();
  wireThemeForm();
  wireActions();
  fillThemeForm(null);
  await loadData(false);

  setInterval(() => {
    loadData(false).catch(() => {});
  }, 20000);
}

boot().catch(() => {});
