const state = {
  reviews: [],
  current: null,
  me: null,
  calendarMonth: new Date(new Date().getFullYear(), new Date().getMonth(), 1),
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
  } catch (_) {}
  if (typeof opened.focus === 'function') {
    opened.focus();
  }
}

function fmtNum(v, digits = 2) {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '-';
  return Number(v).toLocaleString('zh-CN', { maximumFractionDigits: digits });
}

function escHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function textOr(v, fallback = '-') {
  const s = String(v ?? '').trim();
  return s === '' ? fallback : s;
}

function parseDateKey(dateText) {
  const text = String(dateText || '').trim();
  const m = text.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!m) return null;
  const y = Number(m[1]);
  const mo = Number(m[2]);
  const d = Number(m[3]);
  if (!Number.isInteger(y) || !Number.isInteger(mo) || !Number.isInteger(d)) return null;
  return new Date(y, mo - 1, d);
}

function toDateKey(dateObj) {
  const y = dateObj.getFullYear();
  const m = String(dateObj.getMonth() + 1).padStart(2, '0');
  const d = String(dateObj.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

function monthLabel(dateObj) {
  return `${dateObj.getFullYear()}年${dateObj.getMonth() + 1}月`;
}

function monthStart(dateObj) {
  return new Date(dateObj.getFullYear(), dateObj.getMonth(), 1);
}

function buildRiskTag(review) {
  if (!review) return '';
  const total = Number(review.total_pnl || 0);
  const missing = Number(review?.auto_metrics?.stoploss_missing_count || 0);
  const losses = Number(review.loss_trade_count || 0);
  const wins = Number(review.win_trade_count || 0);
  const regime = String(review?.auto_metrics?.market_overview?.regime || '');

  if (regime === 'hot') return '市场偏热';
  if (regime === 'cold') return '市场偏弱';
  if (missing >= 2) return '止损缺失';
  if (total < 0 && losses > wins) return '回撤日';
  if (total > 0 && wins >= losses) return '执行优';
  return '中性';
}

function renderCalendar() {
  const node = $('#reviewCalendar');
  const label = $('#calendarMonthLabel');
  if (!node || !label) return;

  const month = monthStart(state.calendarMonth);
  label.textContent = monthLabel(month);

  const reviewMap = new Map();
  state.reviews.forEach((r) => {
    const key = String(r.review_date || '').trim();
    if (key) reviewMap.set(key, r);
  });

  const y = month.getFullYear();
  const m = month.getMonth();
  const firstDay = new Date(y, m, 1);
  const lastDay = new Date(y, m + 1, 0);
  const totalDays = lastDay.getDate();
  const startWeekday = (firstDay.getDay() + 6) % 7;
  const weekNames = ['一', '二', '三', '四', '五', '六', '日'];

  let html = '<div class="review-calendar-head">' + weekNames.map((w) => `<span>${w}</span>`).join('') + '</div>';
  html += '<div class="review-calendar-grid">';

  for (let i = 0; i < startWeekday; i += 1) {
    html += '<article class="review-day review-day-empty"></article>';
  }

  for (let day = 1; day <= totalDays; day += 1) {
    const d = new Date(y, m, day);
    const key = toDateKey(d);
    const row = reviewMap.get(key) || null;
    const isToday = key === toDateKey(new Date());
    const active = Number(row?.id || 0) === Number(state.current?.id || 0);
    const pnl = Number(row?.total_pnl || 0);
    const pnlClass = !row ? '' : (pnl > 0 ? 'pnl-pos' : (pnl < 0 ? 'pnl-neg' : 'pnl-flat'));
    const tag = buildRiskTag(row);

    html += `
      <article class="review-day ${row ? 'has-data' : 'no-data'} ${active ? 'is-active' : ''} ${isToday ? 'is-today' : ''} ${pnlClass}" data-day="${key}" data-review-id="${Number(row?.id || 0)}">
        <div class="review-day-top">
          <strong>${day}</strong>
          ${row ? `<span class="day-chip">${escHtml(tag)}</span>` : ''}
        </div>
        <div class="review-day-body">
          ${row ? `<p>合计 ${fmtNum(row.total_pnl || 0)}</p>` : '<p class="muted">无记录</p>'}
          ${row ? `<p>交易 ${fmtNum(row.trade_count || 0, 0)} 笔</p>` : ''}
          ${row ? `<p>评分 ${fmtNum(row?.self_score ?? row?.auto_metrics?.score ?? 0, 0)}</p>` : ''}
        </div>
      </article>
    `;
  }

  html += '</div>';
  node.innerHTML = html;

  $$('[data-day]').forEach((el) => {
    el.addEventListener('click', () => {
      const id = Number(el.dataset.reviewId || 0);
      if (!id) {
        showToast(`${el.dataset.day} 暂无复盘记录`);
        return;
      }
      const row = state.reviews.find((x) => Number(x.id) === id);
      if (!row) return;
      setCurrent(row, false);
    });
  });
}

function renderKpi(review) {
  const node = $('#reviewKpi');
  if (!node) return;

  if (!review) {
    node.innerHTML = [
      ['复盘日期', '-'],
      ['已实现收益', '-'],
      ['浮动收益', '-'],
      ['合计收益', '-'],
      ['交易笔数', '-'],
      ['执行评分', '-'],
    ].map(([k, v]) => `<article class="focus-kpi"><p>${escHtml(k)}</p><strong>${escHtml(v)}</strong></article>`).join('');
    return;
  }

  const autoScore = Number(review?.auto_metrics?.score ?? 0);
  const selfScore = review?.self_score === null || review?.self_score === undefined ? null : Number(review.self_score);
  const scoreText = selfScore !== null && Number.isFinite(selfScore)
    ? `${selfScore} (自评) / ${autoScore} (系统)`
    : `${autoScore} (系统)`;

  const cards = [
    ['复盘日期', textOr(review.review_date, '-')],
    ['已实现收益', fmtNum(review.realized_pnl || 0)],
    ['浮动收益', fmtNum(review.unrealized_pnl || 0)],
    ['合计收益', fmtNum(review.total_pnl || 0)],
    ['交易笔数', fmtNum(review.trade_count || 0, 0)],
    ['执行评分', scoreText],
  ];

  node.innerHTML = cards
    .map(([k, v]) => `<article class="focus-kpi"><p>${escHtml(k)}</p><strong>${escHtml(v)}</strong></article>`)
    .join('');
}

function renderAuto(review) {
  $('#autoStamp').textContent = review?.updated_at || review?.generated_at || '-';
  $('#autoSummary').innerHTML = `
    <h4>收益总结</h4>
    <p>${escHtml(textOr(review?.auto_profit_summary, '暂无自动复盘内容，请点击“生成今日日志”'))}</p>
  `;
  $('#autoIssues').textContent = textOr(review?.auto_issues, '-');
  $('#autoShortcomings').textContent = textOr(review?.auto_shortcomings, '-');
  $('#autoSuggestions').textContent = textOr(review?.auto_suggestions, '-');

  const m = review?.auto_metrics || {};
  const market = m.market_overview || {};
  const rating = m.today_rating || {};
  const strengths = Array.isArray(m.strength_points) ? m.strength_points : [];
  const improvements = Array.isArray(m.improvement_points) ? m.improvement_points : [];
  const holdings = Array.isArray(m.top_holdings) ? m.top_holdings : [];
  const lossTrades = Array.isArray(m.top_loss_trades) ? m.top_loss_trades : [];
  const winTrades = Array.isArray(m.top_win_trades) ? m.top_win_trades : [];
  const hotSectors = Array.isArray(market.hot_sectors) ? market.hot_sectors : [];
  const weakSectors = Array.isArray(market.weak_sectors) ? market.weak_sectors : [];

  const holdingHtml = holdings.length
    ? holdings.slice(0, 6).map((x, i) => `<span class="industry-leader-chip small">#${i + 1} ${escHtml(textOr(x.symbol))} ${escHtml(textOr(x.name, ''))} ${fmtNum(x.weight_pct)}%</span>`).join('')
    : '<span class="notice">暂无持仓明细</span>';

  const lossHtml = lossTrades.length
    ? lossTrades.slice(0, 4).map((x) => `<li>${escHtml(textOr(x.symbol, '-'))} ${escHtml(textOr(x.name, ''))} ${fmtNum(x.realized_pnl)}</li>`).join('')
    : '<li>-</li>';

  const winHtml = winTrades.length
    ? winTrades.slice(0, 4).map((x) => `<li>${escHtml(textOr(x.symbol, '-'))} ${escHtml(textOr(x.name, ''))} ${fmtNum(x.realized_pnl)}</li>`).join('')
    : '<li>-</li>';

  const strengthHtml = strengths.length
    ? strengths.map((x) => `<li>${escHtml(textOr(x, '-'))}</li>`).join('')
    : '<li>-</li>';

  const improveHtml = improvements.length
    ? improvements.map((x) => `<li>${escHtml(textOr(x, '-'))}</li>`).join('')
    : '<li>-</li>';

  const hotSectorHtml = hotSectors.length
    ? hotSectors.slice(0, 5).map((x, i) => `<span class="industry-leader-chip small">热${i + 1} ${escHtml(textOr(x.sector_name, '-'))} ${fmtNum(x.change_pct)}%</span>`).join('')
    : '<span class="notice">暂无热点板块</span>';
  const weakSectorHtml = weakSectors.length
    ? weakSectors.slice(0, 5).map((x, i) => `<span class="industry-leader-chip small">弱${i + 1} ${escHtml(textOr(x.sector_name, '-'))} ${fmtNum(x.change_pct)}%</span>`).join('')
    : '<span class="notice">暂无弱势板块</span>';

  $('#autoMetricsBox').innerHTML = `
    <h4>结构拆解</h4>
    <p class="notice">今日评分 ${escHtml(textOr(rating.grade, '-'))}(${fmtNum(rating.score || 0, 0)}) · ${escHtml(textOr(rating.comment, '-'))}</p>
    <p class="notice">市场：上涨 ${fmtNum(market.up_count || 0, 0)} / 下跌 ${fmtNum(market.down_count || 0, 0)} / 平盘 ${fmtNum(market.flat_count || 0, 0)} · 平均涨跌 ${fmtNum(market.avg_change_pct || 0)}% · 涨停近似 ${fmtNum(market.limit_up_count || 0, 0)} / 跌停近似 ${fmtNum(market.limit_down_count || 0, 0)} · 市场状态 ${escHtml(textOr(market.regime, 'neutral'))}</p>
    <p class="notice">胜率 ${m.win_rate_pct === null || m.win_rate_pct === undefined ? '-' : `${fmtNum(m.win_rate_pct)}%`} · 平均盈利 ${fmtNum(m.avg_win_pnl)} · 平均亏损 ${fmtNum(m.avg_loss_pnl)} · 盈亏比 ${fmtNum(m.profit_factor)}</p>
    <p class="notice">仓位集中度 Top1 ${fmtNum(m.concentration_top1_pct || 0)}% / Top3 ${fmtNum(m.concentration_top3_pct || 0)}%，未设止损 ${fmtNum(m.stoploss_missing_count || 0, 0)} 只</p>
    <div class="industry-leader-list">${hotSectorHtml}</div>
    <div class="industry-leader-list">${weakSectorHtml}</div>
    <div class="industry-leader-list">${holdingHtml}</div>
    <div class="review-mini-grid">
      <article>
        <h5>做得好的</h5>
        <ul>${strengthHtml}</ul>
      </article>
      <article>
        <h5>需要提高的</h5>
        <ul>${improveHtml}</ul>
      </article>
      <article>
        <h5>今日盈利交易</h5>
        <ul>${winHtml}</ul>
      </article>
      <article>
        <h5>今日亏损交易</h5>
        <ul>${lossHtml}</ul>
      </article>
    </div>
  `;
}

function fillSelfForm(review) {
  const form = $('#selfReviewForm');
  if (!form) return;
  form.elements.id.value = review?.id ? String(review.id) : '';
  form.elements.self_profit_summary.value = review?.self_profit_summary || '';
  form.elements.self_issues.value = review?.self_issues || '';
  form.elements.self_shortcomings.value = review?.self_shortcomings || '';
  form.elements.self_suggestions.value = review?.self_suggestions || '';
  form.elements.self_plan.value = review?.self_plan || '';
  form.elements.self_score.value = review?.self_score ?? '';
  $('#selfStamp').textContent = review?.updated_at || '-';
}

function renderHistory() {
  const node = $('#reviewHistoryList');
  if (!node) return;

  if (!state.reviews.length) {
    node.innerHTML = '<p class="muted">暂无历史复盘记录</p>';
    return;
  }

  const currentId = Number(state.current?.id || 0);
  node.innerHTML = state.reviews.map((r) => {
    const active = Number(r.id) === currentId ? 'is-selected' : '';
    return `
      <article class="focus-card daily-history-item ${active}" data-review-id="${Number(r.id || 0)}">
        <h4>${escHtml(textOr(r.review_date, '-'))} · 合计 ${fmtNum(r.total_pnl || 0)}</h4>
        <p>已实现 ${fmtNum(r.realized_pnl || 0)} · 浮动 ${fmtNum(r.unrealized_pnl || 0)} · 交易 ${fmtNum(r.trade_count || 0, 0)} 笔</p>
      </article>
    `;
  }).join('');

  $$('[data-review-id]').forEach((el) => {
    el.addEventListener('click', () => {
      const id = Number(el.dataset.reviewId || 0);
      const row = state.reviews.find((x) => Number(x.id) === id);
      if (!row) return;
      setCurrent(row, true);
    });
  });
}

function setCurrent(review, syncMonth = true) {
  state.current = review || null;
  if (syncMonth && state.current?.review_date) {
    const d = parseDateKey(state.current.review_date);
    if (d) {
      state.calendarMonth = monthStart(d);
    }
  }

  renderKpi(state.current);
  renderAuto(state.current || {});
  fillSelfForm(state.current || {});
  renderHistory();
  renderCalendar();
}

async function loadData() {
  const prevId = Number(state.current?.id || 0);
  const [latestRes, listRes] = await Promise.all([
    api('/api/reviews/daily/latest'),
    api('/api/reviews/daily?days=180'),
  ]);

  state.reviews = Array.isArray(listRes.reviews) ? listRes.reviews : [];

  let target = null;
  if (prevId > 0) {
    target = state.reviews.find((x) => Number(x.id) === prevId) || null;
  }
  if (!target) {
    const latest = latestRes.review || null;
    if (latest && latest.id) {
      target = state.reviews.find((x) => Number(x.id) === Number(latest.id)) || latest;
    }
  }
  if (!target && state.reviews.length) {
    target = state.reviews[0];
  }

  setCurrent(target, true);
}

async function ensureAuth() {
  try {
    const me = await api('/api/auth/me');
    state.me = me.user || null;
  } catch {
    location.href = '/';
    throw new Error('unauthorized');
  }
}

function wireActions() {
  $('#openDashboardBtn')?.addEventListener('click', () => openExternalPage('/'));
  $('#openPositionsBtn')?.addEventListener('click', () => openExternalPage('/positions.html'));

  $('#calendarPrevBtn')?.addEventListener('click', () => {
    state.calendarMonth = new Date(state.calendarMonth.getFullYear(), state.calendarMonth.getMonth() - 1, 1);
    renderCalendar();
  });
  $('#calendarNextBtn')?.addEventListener('click', () => {
    state.calendarMonth = new Date(state.calendarMonth.getFullYear(), state.calendarMonth.getMonth() + 1, 1);
    renderCalendar();
  });

  $('#refreshBtn')?.addEventListener('click', async () => {
    try {
      await loadData();
      showToast('复盘已刷新');
    } catch (e) {
      showToast(`刷新失败: ${e.message}`);
    }
  });

  $('#generateTodayBtn')?.addEventListener('click', async () => {
    try {
      const data = await api('/api/reviews/daily/generate', {
        method: 'POST',
        body: JSON.stringify({ slot: 'manual' }),
      });
      showToast('今日日志已生成');
      const review = data.review || null;
      await loadData();
      if (review?.id) {
        const found = state.reviews.find((x) => Number(x.id) === Number(review.id));
        if (found) {
          setCurrent(found, true);
        }
      }
    } catch (e) {
      showToast(`生成失败: ${e.message}`);
    }
  });

  $('#selfReviewForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const id = Number(form.elements.id.value || 0);
    if (!id) {
      showToast('请先生成或选择一条复盘记录');
      return;
    }

    const payload = {
      self_profit_summary: form.elements.self_profit_summary.value,
      self_issues: form.elements.self_issues.value,
      self_shortcomings: form.elements.self_shortcomings.value,
      self_suggestions: form.elements.self_suggestions.value,
      self_plan: form.elements.self_plan.value,
      self_score: form.elements.self_score.value === '' ? null : Number(form.elements.self_score.value),
    };

    const btn = $('#saveSelfBtn');
    const oldText = btn ? btn.textContent : '';

    try {
      if (btn) {
        btn.disabled = true;
        btn.textContent = '保存中...';
      }
      await api(`/api/reviews/daily/${id}`, {
        method: 'PUT',
        body: JSON.stringify(payload),
      });
      showToast('我的复盘已保存');
      await loadData();
    } catch (err) {
      showToast(`保存失败: ${err.message}`);
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.textContent = oldText || '保存我的复盘';
      }
    }
  });

  $('#logoutBtn')?.addEventListener('click', async () => {
    await api('/api/auth/logout', { method: 'POST', body: '{}' }).catch(() => {});
    location.href = '/';
  });
}

async function boot() {
  await ensureAuth();
  wireActions();
  await loadData();
}

boot().catch(() => {});
