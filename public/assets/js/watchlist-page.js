const state = {
  me: null,
  rows: [],
  selectedId: null,
  autoTicking: false,
  sortByScore: true,
  sidePanelOpen: false,
  sideHintShownAt: 0,
  reviewDetailId: null,
  reviewDetailData: null,
  reviewDetailFetchedAt: 0,
  globalSnapshots: [],
  globalSnapshotLatest: null,
  loading: false,
};

const $ = (s) => document.querySelector(s);
const $$ = (s) => Array.from(document.querySelectorAll(s));
const SIDE_PANEL_STORAGE_KEY = 'watchlist_side_panel_open_v1';

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

function fmtPct(v, digits = 2) {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '-';
  const n = Number(v);
  return `${n >= 0 ? '+' : ''}${n.toFixed(digits)}%`;
}

function fmtRate(v, digits = 1) {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '-';
  return `${Number(v).toFixed(digits)}%`;
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

function reviewGradeFromMetrics(score, accuracyRate, avgReturn5d, winRate5d, sampleCount = 0) {
  let v = Number(score);
  if (!Number.isFinite(v)) {
    const acc = Number(accuracyRate || 0);
    const ret5 = Number(avgReturn5d || 0);
    const win5 = Number(winRate5d || 0);
    const sampleBoost = Math.min(Number(sampleCount || 0), 30) * 0.4;
    v = (acc * 0.45) + (ret5 * 2.1) + (win5 * 0.32) + sampleBoost;
  }

  if (v >= 82) return { label: 'S', className: 'grade-s', score: v };
  if (v >= 70) return { label: 'A', className: 'grade-a', score: v };
  if (v >= 55) return { label: 'B', className: 'grade-b', score: v };
  return { label: 'C', className: 'grade-c', score: v };
}

function renderKpi(rows) {
  const total = rows.length;
  const active = rows.filter((x) => x.status === 'active').length;
  const highPriority = rows.filter((x) => Number(x.priority || 999) <= 20).length;
  const avgScore = rows.length
    ? rows.reduce((s, x) => s + Number(x.ranking_score || 0), 0) / rows.length
    : 0;
  const focusCount = rows.filter((x) => Number(x.ranking_score || 0) >= 65).length;
  const reviewedRows = rows.filter((x) => Number(x.review_total || 0) > 0);
  const avgAccuracy = reviewedRows.length
    ? reviewedRows.reduce((s, x) => s + Number(x.review_accuracy_rate || 0), 0) / reviewedRows.length
    : 0;

  const cards = [
    ['池内股票', total],
    ['持续跟踪', active],
    ['高优先级', highPriority],
    ['平均评分', fmtNum(avgScore, 1)],
    ['重点/优先', focusCount],
    ['复盘均准确率', reviewedRows.length ? `${fmtNum(avgAccuracy, 1)}%` : '-'],
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

function sparklineSvg(points = []) {
  const vals = Array.isArray(points)
    ? points.map((v) => Number(v)).filter((v) => Number.isFinite(v))
    : [];

  if (vals.length < 2) {
    return '<span class="notice">样本不足</span>';
  }

  const width = 206;
  const height = 58;
  const pad = 4;
  const minVal = Math.min(...vals, 0);
  const maxVal = Math.max(...vals, 0);
  const span = Math.max(0.001, maxVal - minVal);
  const step = vals.length > 1 ? (width - pad * 2) / (vals.length - 1) : 0;

  const path = vals.map((v, idx) => {
    const x = pad + step * idx;
    const y = pad + (1 - (v - minVal) / span) * (height - pad * 2);
    return `${idx === 0 ? 'M' : 'L'}${x.toFixed(2)} ${y.toFixed(2)}`;
  }).join(' ');

  const zeroY = pad + (1 - (0 - minVal) / span) * (height - pad * 2);
  const latest = vals[vals.length - 1];
  const stroke = latest >= 0 ? '#ff7b7b' : '#59e6a5';
  const fill = latest >= 0 ? 'rgba(255, 123, 123, 0.18)' : 'rgba(89, 230, 165, 0.18)';
  const areaPath = `${path} L${(width - pad).toFixed(2)} ${(height - pad).toFixed(2)} L${pad.toFixed(2)} ${(height - pad).toFixed(2)} Z`;

  return `<svg class="mini-sparkline" viewBox="0 0 ${width} ${height}" preserveAspectRatio="none" aria-hidden="true">
    <line x1="0" y1="${zeroY.toFixed(2)}" x2="${width}" y2="${zeroY.toFixed(2)}" stroke="rgba(255,255,255,0.22)" stroke-width="1" />
    <path d="${areaPath}" fill="${fill}" stroke="none" />
    <path d="${path}" fill="none" stroke="${stroke}" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" />
  </svg>`;
}

function clearReviewPanels() {
  $('#reviewGlobalKpi') && ($('#reviewGlobalKpi').innerHTML = '<p class="notice">暂无复盘数据</p>');
  $('#reviewGlobalChartAccuracy') && ($('#reviewGlobalChartAccuracy').innerHTML = '<p class="notice">暂无准确率趋势</p>');
  $('#reviewGlobalChartReturn') && ($('#reviewGlobalChartReturn').innerHTML = '<p class="notice">暂无收益率趋势</p>');
  $('#reviewRankTable') && ($('#reviewRankTable').innerHTML = '<tr><td class="muted">暂无个股复盘排行</td></tr>');
  $('#reviewDetailTitle') && ($('#reviewDetailTitle').textContent = '个股复盘详情');
  $('#reviewDetailSummary') && ($('#reviewDetailSummary').innerHTML = '<p class="notice">请选择一只股票查看复盘详情</p>');
  $('#reviewDetailChart') && ($('#reviewDetailChart').innerHTML = '<p class="notice">暂无个股复盘趋势</p>');
  $('#reviewHistoryList') && ($('#reviewHistoryList').innerHTML = '<p class="muted">暂无复盘历史记录</p>');
}

function normalizeReviewPoint(v) {
  const n = Number(v);
  return Number.isFinite(n) ? n : null;
}

function buildLineChartSvg(points = [], opts = {}) {
  const width = Number(opts.width || 720);
  const height = Number(opts.height || 230);
  const pad = 30;
  const formatFn = typeof opts.formatFn === 'function' ? opts.formatFn : (v) => fmtPct(v);
  const vals = points.map(normalizeReviewPoint).filter((x) => x !== null);
  if (vals.length < 2) {
    return '<p class="notice">样本不足，暂无法绘制趋势图</p>';
  }

  const minVal = Math.min(...vals, 0);
  const maxVal = Math.max(...vals, 0);
  const span = Math.max(0.001, maxVal - minVal);
  const w = width - pad * 2;
  const h = height - pad * 2;
  const step = vals.length > 1 ? w / (vals.length - 1) : 0;

  const yPos = (v) => pad + (1 - (v - minVal) / span) * h;
  const path = vals.map((v, i) => `${i === 0 ? 'M' : 'L'}${(pad + i * step).toFixed(2)} ${yPos(v).toFixed(2)}`).join(' ');
  const areaPath = `${path} L${(pad + (vals.length - 1) * step).toFixed(2)} ${(pad + h).toFixed(2)} L${pad.toFixed(2)} ${(pad + h).toFixed(2)} Z`;
  const zeroY = yPos(0);
  const latest = vals[vals.length - 1];
  const stroke = latest >= 0 ? '#ff7b7b' : '#59e6a5';
  const fill = latest >= 0 ? 'rgba(255,123,123,0.20)' : 'rgba(89,230,165,0.20)';

  const gridLines = [0, 0.25, 0.5, 0.75, 1]
    .map((ratio) => {
      const y = pad + h * ratio;
      return `<line x1="${pad}" y1="${y.toFixed(2)}" x2="${(pad + w).toFixed(2)}" y2="${y.toFixed(2)}" stroke="rgba(255,255,255,0.09)" stroke-width="1" />`;
    })
    .join('');

  return `
    <svg class="review-trend-svg" viewBox="0 0 ${width} ${height}" preserveAspectRatio="none" aria-hidden="true">
      ${gridLines}
      <line x1="${pad}" y1="${zeroY.toFixed(2)}" x2="${(pad + w).toFixed(2)}" y2="${zeroY.toFixed(2)}" stroke="rgba(255,255,255,0.22)" stroke-width="1.2" />
      <path d="${areaPath}" fill="${fill}" stroke="none" />
      <path d="${path}" fill="none" stroke="${stroke}" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
      <text x="${pad}" y="${(pad - 8).toFixed(2)}" fill="#9eb2c1" font-size="12">最高 ${formatFn(maxVal)}</text>
      <text x="${pad}" y="${(pad + h + 16).toFixed(2)}" fill="#9eb2c1" font-size="12">最低 ${formatFn(minVal)}</text>
      <text x="${(pad + w - 6).toFixed(2)}" y="${(pad - 8).toFixed(2)}" text-anchor="end" fill="#ffc756" font-size="12">最新 ${formatFn(latest)}</text>
    </svg>
  `;
}

function pickReviewReturnPoint(item) {
  const cands = [
    item?.return_5d_pct,
    item?.return_3d_pct,
    item?.return_1d_pct,
  ];
  for (const c of cands) {
    const n = normalizeReviewPoint(c);
    if (n !== null) return n;
  }
  return null;
}

function aggregateGlobalReviewSeries(rows = []) {
  const seriesList = rows
    .map((row) => (Array.isArray(row.review_sparkline_points) ? row.review_sparkline_points : [])
      .map(normalizeReviewPoint)
      .filter((x) => x !== null))
    .filter((arr) => arr.length >= 2);

  if (!seriesList.length) return [];

  const maxLen = Math.max(...seriesList.map((x) => x.length));
  const out = [];
  for (let i = 0; i < maxLen; i += 1) {
    const vals = [];
    for (const s of seriesList) {
      const idx = s.length - maxLen + i;
      if (idx >= 0 && idx < s.length) vals.push(s[idx]);
    }
    if (vals.length) {
      out.push(vals.reduce((a, b) => a + b, 0) / vals.length);
    }
  }
  return out;
}

function mapSnapshotSeries(rows = [], field) {
  if (!Array.isArray(rows)) return [];
  return rows
    .map((r) => normalizeReviewPoint(r?.[field]))
    .filter((v) => v !== null);
}

function snapshotSlotLabel(slot) {
  const s = String(slot || '').toLowerCase();
  if (s === 'midday') return '午盘';
  if (s === 'close') return '收盘';
  if (s === 'manual') return '手动';
  return slot || '-';
}

function renderReviewDashboard(rows = []) {
  const reviewed = rows.filter((x) => Number(x.review_total || 0) > 0);
  const totalStocks = rows.length;
  const coveredStocks = reviewed.length;
  const snapshotSeries = Array.isArray(state.globalSnapshots) ? state.globalSnapshots : [];
  const latest = state.globalSnapshotLatest || {};

  const totalSamples = Number(latest.sample_count || 0) || reviewed.reduce((s, x) => s + Number(x.review_total || 0), 0);
  const accuracy = normalizeReviewPoint(latest.accuracy_rate)
    ?? (totalSamples > 0 ? reviewed.reduce((s, x) => s + Number(x.review_accuracy_rate || 0) * Number(x.review_total || 0), 0) / totalSamples : 0);
  const ret3 = normalizeReviewPoint(latest.avg_return_3d_pct)
    ?? (totalSamples > 0 ? reviewed.reduce((s, x) => s + Number(x.review_avg_return_3d_pct || 0) * Number(x.review_total || 0), 0) / totalSamples : 0);
  const ret5 = normalizeReviewPoint(latest.avg_return_5d_pct)
    ?? (totalSamples > 0 ? reviewed.reduce((s, x) => s + Number(x.review_avg_return_5d_pct || 0) * Number(x.review_total || 0), 0) / totalSamples : 0);
  const win5 = normalizeReviewPoint(latest.win_rate_5d)
    ?? (totalSamples > 0 ? reviewed.reduce((s, x) => s + Number(x.review_win_rate_5d || 0) * Number(x.review_total || 0), 0) / totalSamples : 0);
  const latestDate = latest.snapshot_date || '-';
  const latestSlot = snapshotSlotLabel(latest.snapshot_slot || '-');
  const globalGrade = reviewGradeFromMetrics(latest.score, accuracy, ret5, win5, totalSamples);

  const globalKpi = $('#reviewGlobalKpi');
  if (globalKpi) {
    const cards = [
      ['覆盖个股', `${coveredStocks}/${totalStocks}`],
      ['快照样本', fmtNum(totalSamples, 0)],
      ['全局准确率', fmtRate(accuracy)],
      ['全局3日收益', `<span class="${numClass(ret3)}">${fmtPct(ret3)}</span>`],
      ['全局5日收益', `<span class="${numClass(ret5)}">${fmtPct(ret5)}</span>`],
      ['5日胜率', fmtRate(win5)],
      ['全局复盘评级', `<span class="review-grade ${globalGrade.className}">${globalGrade.label}</span><span class="notice"> ${fmtNum(globalGrade.score, 1)} 分</span>`],
      ['最新快照', `${latestDate} ${latestSlot}`],
    ];
    globalKpi.innerHTML = cards
      .map(([k, v]) => `<article class="review-kpi"><p>${k}</p><strong>${v}</strong></article>`)
      .join('');
  }

  const accSeries = mapSnapshotSeries(snapshotSeries, 'accuracy_rate');
  const retSeries = mapSnapshotSeries(snapshotSeries, 'avg_return_5d_pct');
  const accChart = $('#reviewGlobalChartAccuracy');
  const retChart = $('#reviewGlobalChartReturn');
  if (accChart) {
    accChart.innerHTML = accSeries.length >= 2
      ? `<h4 class="review-chart-title">全局准确率趋势（越高越好）</h4>${buildLineChartSvg(accSeries, { width: 920, height: 250, formatFn: (v) => fmtRate(v) })}`
      : '<p class="notice">暂无全局准确率快照趋势</p>';
  }
  if (retChart) {
    retChart.innerHTML = retSeries.length >= 2
      ? `<h4 class="review-chart-title">全局5日收益趋势（越高越好）</h4>${buildLineChartSvg(retSeries, { width: 920, height: 250 })}`
      : '<p class="notice">暂无全局收益率快照趋势</p>';
  }

  const rankTable = $('#reviewRankTable');
  if (rankTable) {
    if (!reviewed.length) {
      rankTable.innerHTML = '<tr><td class="muted">暂无个股复盘排行</td></tr>';
    } else {
      const ranked = [...reviewed].sort((a, b) => {
        const sa = Number(a.snapshot_score ?? a.review_score ?? 0)
          || ((Number(a.snapshot_accuracy_rate ?? a.review_accuracy_rate ?? 0) * 0.45)
          + (Number(a.snapshot_avg_return_5d_pct ?? a.review_avg_return_5d_pct ?? 0) * 2.1)
          + (Number(a.snapshot_win_rate_5d ?? a.review_win_rate_5d ?? 0) * 0.32)
          + (Math.min(Number(a.snapshot_sample_count ?? a.review_total ?? 0), 30) * 0.4));
        const sb = Number(b.snapshot_score ?? b.review_score ?? 0)
          || ((Number(b.snapshot_accuracy_rate ?? b.review_accuracy_rate ?? 0) * 0.45)
          + (Number(b.snapshot_avg_return_5d_pct ?? b.review_avg_return_5d_pct ?? 0) * 2.1)
          + (Number(b.snapshot_win_rate_5d ?? b.review_win_rate_5d ?? 0) * 0.32)
          + (Math.min(Number(b.snapshot_sample_count ?? b.review_total ?? 0), 30) * 0.4));
        return sb - sa;
      }).slice(0, 12);

      rankTable.innerHTML = `
        <thead>
          <tr>
            <th>#</th><th>个股</th><th>评级</th><th>样本</th><th>准确率</th><th>5日收益</th><th>快照</th><th>趋势</th><th>操作</th>
          </tr>
        </thead>
        <tbody>
          ${ranked.map((row, idx) => `
            <tr>
              <td>${idx + 1}</td>
              <td><strong>${row.symbol}</strong><br/><span class="notice">${row.name || '-'}</span></td>
              <td>${(() => {
                const g = reviewGradeFromMetrics(
                  row.snapshot_score ?? row.review_score,
                  row.snapshot_accuracy_rate ?? row.review_accuracy_rate,
                  row.snapshot_avg_return_5d_pct ?? row.review_avg_return_5d_pct,
                  row.snapshot_win_rate_5d ?? row.review_win_rate_5d,
                  row.snapshot_sample_count ?? row.review_total ?? 0,
                );
                return `<span class="review-grade ${g.className}">${g.label}</span><span class="notice"> ${fmtNum(g.score, 1)}</span>`;
              })()}</td>
              <td>${fmtNum(row.snapshot_sample_count ?? row.review_total, 0)}</td>
              <td>${fmtRate(row.snapshot_accuracy_rate ?? row.review_accuracy_rate)}</td>
              <td><span class="${numClass(row.snapshot_avg_return_5d_pct ?? row.review_avg_return_5d_pct)}">${fmtPct(row.snapshot_avg_return_5d_pct ?? row.review_avg_return_5d_pct)}</span></td>
              <td>${row.snapshot_date || '-'} ${snapshotSlotLabel(row.snapshot_slot || '-')}</td>
              <td>${sparklineSvg(row.snapshot_series_points || row.review_sparkline_points || [])}</td>
              <td><button class="btn ghost tiny" data-review-focus="${row.id}">查看</button></td>
            </tr>
          `).join('')}
        </tbody>
      `;

      $$('[data-review-focus]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const id = Number(btn.dataset.reviewFocus);
          if (!id) return;
          state.selectedId = id;
          renderTable(state.rows);
          loadReviewDetail(id, true).catch(() => {});
        });
      });
    }
  }
}

function renderReviewDetailBox(row, detail) {
  const titleNode = $('#reviewDetailTitle');
  const summaryNode = $('#reviewDetailSummary');
  const chartNode = $('#reviewDetailChart');
  const historyNode = $('#reviewHistoryList');
  const openBtn = $('#openSelectedDetailBtn');

  if (!titleNode || !summaryNode || !chartNode || !historyNode) return;

  if (!row) {
    titleNode.textContent = '个股复盘详情';
    summaryNode.innerHTML = '<p class="notice">请选择一只股票查看复盘详情</p>';
    chartNode.innerHTML = '<p class="notice">暂无个股复盘趋势</p>';
    historyNode.innerHTML = '<p class="muted">暂无复盘历史记录</p>';
    if (openBtn) openBtn.disabled = true;
    return;
  }

  if (openBtn) openBtn.disabled = false;
  titleNode.textContent = `个股复盘详情 · ${row.symbol} ${row.name || ''}`.trim();

  const overview = detail?.overview || {};
  const snapshotSeries = Array.isArray(detail?.snapshot_series) ? detail.snapshot_series : [];
  const snapshotLast = snapshotSeries.length ? snapshotSeries[snapshotSeries.length - 1] : null;
  const detailGrade = reviewGradeFromMetrics(
    snapshotLast?.score ?? row.snapshot_score ?? row.review_score,
    overview.review_accuracy_rate,
    overview.avg_return_5d_pct,
    row.snapshot_win_rate_5d ?? row.review_win_rate_5d,
    overview.review_total || 0,
  );
  summaryNode.innerHTML = `
    <article class="review-kpi">
      <p>复盘样本</p>
      <strong>${fmtNum(overview.review_total, 0)}</strong>
    </article>
    <article class="review-kpi">
      <p>准确率</p>
      <strong>${fmtRate(overview.review_accuracy_rate)}</strong>
    </article>
    <article class="review-kpi">
      <p>3日均收益</p>
      <strong class="${numClass(overview.avg_return_3d_pct)}">${fmtPct(overview.avg_return_3d_pct)}</strong>
    </article>
    <article class="review-kpi">
      <p>5日均收益</p>
      <strong class="${numClass(overview.avg_return_5d_pct)}">${fmtPct(overview.avg_return_5d_pct)}</strong>
    </article>
    <article class="review-kpi">
      <p>最新快照</p>
      <strong>${snapshotLast ? `${snapshotLast.snapshot_date || '-'} ${snapshotSlotLabel(snapshotLast.snapshot_slot || '-')}` : '-'}</strong>
    </article>
    <article class="review-kpi">
      <p>复盘评级</p>
      <strong><span class="review-grade ${detailGrade.className}">${detailGrade.label}</span> ${fmtNum(detailGrade.score, 1)} 分</strong>
    </article>
  `;

  const reviews = Array.isArray(detail?.reviews) ? detail.reviews : [];
  const snapshotAccSeries = snapshotSeries
    .map((x) => normalizeReviewPoint(x?.accuracy_rate))
    .filter((x) => x !== null);
  const snapshotReturnSeries = snapshotSeries
    .map((x) => normalizeReviewPoint(x?.avg_return_5d_pct))
    .filter((x) => x !== null);
  const returnSeries = snapshotReturnSeries.length >= 2
    ? snapshotReturnSeries
    : [...reviews].reverse().map(pickReviewReturnPoint).filter((x) => x !== null);
  const chartBlocks = [];

  if (snapshotAccSeries.length >= 2) {
    chartBlocks.push(`
      <div class="review-chart-card inline">
        <h4 class="review-chart-title">个股准确率趋势（快照）</h4>
        ${buildLineChartSvg(snapshotAccSeries, { width: 620, height: 220, formatFn: (v) => fmtRate(v) })}
      </div>
    `);
  }
  if (returnSeries.length >= 2) {
    chartBlocks.push(`
      <div class="review-chart-card inline">
        <h4 class="review-chart-title">个股5日收益趋势</h4>
        ${buildLineChartSvg(returnSeries, { width: 620, height: 220 })}
      </div>
    `);
  }

  chartNode.innerHTML = chartBlocks.length
    ? `<div class="review-detail-chart-grid">${chartBlocks.join('')}</div>`
    : '<p class="notice">该股票快照样本不足，暂无法绘图</p>';

  if (!reviews.length) {
    historyNode.innerHTML = '<p class="muted">该股票暂无复盘历史</p>';
    return;
  }

  historyNode.innerHTML = reviews.slice(0, 16).map((x) => `
    <article class="focus-card review-history-card">
      <h4>${x.evaluated_at || '-'} · ${x.expected_direction || 'neutral'} · ${(Number(x.is_accurate) === 1 ? '判断有效' : '判断偏差')}</h4>
      <p>
        1日: <span class="${numClass(x.return_1d_pct)}">${fmtPct(x.return_1d_pct)}</span>
        · 3日: <span class="${numClass(x.return_3d_pct)}">${fmtPct(x.return_3d_pct)}</span>
        · 5日: <span class="${numClass(x.return_5d_pct)}">${fmtPct(x.return_5d_pct)}</span>
      </p>
      <div class="focus-meta">
        <span>基准价 ${fmtNum(x.base_price)}</span>
        <span>${x.evaluation_note || '-'}</span>
      </div>
    </article>
  `).join('');
}

async function loadReviewDetail(id, showToastOnError = false) {
  const row = state.rows.find((x) => Number(x.id) === Number(id)) || null;
  if (!id || !row) {
    renderReviewDetailBox(null, null);
    return;
  }

  state.reviewDetailId = Number(id);
  try {
    const [reviewData, snapData] = await Promise.all([
      api(`/api/watchlist/${id}/reviews?limit=40`, { timeoutMs: 25000 }),
      api(`/api/watchlist/${id}/review/snapshots?days=45`, { timeoutMs: 25000 }).catch(() => ({ series: [] })),
    ]);

    const data = {
      ...reviewData,
      snapshot_series: Array.isArray(snapData?.series) ? snapData.series : [],
    };
    state.reviewDetailData = data;
    state.reviewDetailFetchedAt = Date.now();
    renderReviewDetailBox(row, data);
  } catch (e) {
    if (showToastOnError) {
      showToast(`加载复盘详情失败: ${e.message}`);
    }
    renderReviewDetailBox(row, {
      overview: {
        review_total: row.review_total,
        review_accuracy_rate: row.review_accuracy_rate,
        avg_return_3d_pct: row.review_avg_return_3d_pct,
        avg_return_5d_pct: row.review_avg_return_5d_pct,
      },
      reviews: [],
      snapshot_series: [],
    });
  }
}

function reviewCell(row) {
  const total = Number(row.snapshot_sample_count || row.review_total || 0);
  if (total <= 0) {
    return '<span class="notice">未复盘</span>';
  }

  const accRate = Number(row.snapshot_accuracy_rate ?? row.review_accuracy_rate ?? 0);
  const avg3 = row.snapshot_avg_return_3d_pct ?? row.review_avg_return_3d_pct;
  const avg5 = row.snapshot_avg_return_5d_pct ?? row.review_avg_return_5d_pct;
  const win5 = row.snapshot_win_rate_5d ?? row.review_win_rate_5d;
  const grade = reviewGradeFromMetrics(row.snapshot_score ?? row.review_score, accRate, avg5, win5, total);
  const snapshotTimeText = row.snapshot_date
    ? `${row.snapshot_date} ${snapshotSlotLabel(row.snapshot_slot || '-')}`
    : '未生成快照';
  const spark = sparklineSvg(row.snapshot_series_points || row.review_sparkline_points || []);

  return `
    <div class="review-cell">
      <div class="review-top">
        <span class="review-chip">样本 ${total}</span>
        <span class="review-chip">5日胜率 ${fmtRate(win5)}</span>
        <span class="review-grade ${grade.className}">${grade.label}</span>
      </div>
      <div class="notice">快照: ${snapshotTimeText}</div>
      <div>
        <span class="notice">准确率</span>
        <strong>${fmtRate(accRate)}</strong>
      </div>
      <div class="review-returns">
        3日: <span class="${numClass(avg3)}">${fmtPct(avg3)}</span>
        · 5日: <span class="${numClass(avg5)}">${fmtPct(avg5)}</span>
      </div>
      ${spark}
    </div>
  `;
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
        <th>标的</th><th>板块/方向</th><th>综合评分</th><th>优先级</th><th>现价</th><th>量能</th><th>支撑/压力</th><th>位置</th><th>建议</th><th>复盘表现</th><th>OpenClaw</th><th>操作</th>
      </tr>
    </thead>
    <tbody>
      ${data.map((r) => {
        const volText = r.volume_ratio !== null && r.volume_ratio !== undefined
          ? `${fmtNum(r.volume_ratio, 2)}x`
          : '-';
        return `
          <tr data-row-id="${r.id}" class="${Number(r.id) === Number(state.selectedId || 0) ? 'is-selected' : ''}">
            <td><strong>${r.symbol}</strong><br/><span class="notice">${r.name || '-'}</span></td>
            <td>${r.sector_name || '-'}<br/><span class="notice">${trendLabel(r.trend_direction)}</span></td>
            <td>${scoreText(r)}</td>
            <td>${fmtNum(r.priority, 0)}<br/><span class="notice">${r.status}</span></td>
            <td>${fmtNum(r.current_price)}</td>
            <td>${volText}<br/><span class="notice">均量20: ${fmtNum(r.avg_volume_20, 0)}</span></td>
            <td>${fmtNum(r.support_price)} / ${fmtNum(r.resistance_price)}</td>
            <td><span class="zone ${zoneClass(r.position_zone)}">${zoneLabel(r.position_zone)}</span></td>
            <td>${r.ranking_action || r.action_advice || '-'}</td>
            <td>${reviewCell(r)}</td>
            <td>${(r.openclaw_note || '-').toString().slice(0, 56)}</td>
            <td>
              <button class="btn ghost tiny" data-edit="${r.id}">编辑</button>
              <button class="btn ghost tiny" data-analyze="${r.id}">分析</button>
              <button class="btn ghost tiny" data-review="${r.id}">复盘</button>
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
  $$('[data-review]').forEach((b) => b.addEventListener('click', () => reviewOne(Number(b.dataset.review))));
  $$('[data-detail]').forEach((b) => b.addEventListener('click', () => openDetail(Number(b.dataset.detail))));
  $$('[data-delete]').forEach((b) => b.addEventListener('click', () => deleteRow(Number(b.dataset.delete))));

  $$('[data-row-id]').forEach((rowNode) => {
    rowNode.addEventListener('click', (e) => {
      if (e.target.closest('button')) return;
      const id = Number(rowNode.dataset.rowId);
      state.selectedId = id;
      loadInsights(id).catch(() => {});
      loadReviewDetail(id, false).catch(() => {});
      renderTable(state.rows);
      if (!state.sidePanelOpen) {
        const now = Date.now();
        if ((now - state.sideHintShownAt) > 3000) {
          state.sideHintShownAt = now;
          showToast('已选中股票，点击右下角按钮展开右侧面板');
        }
      }
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
  setSidePanelOpen(true);
  setForm(row);
  state.selectedId = id;
  renderTable(state.rows);
  loadInsights(id).catch(() => {});
  loadReviewDetail(id, false).catch(() => {});
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
    await loadReviewDetail(id, false).catch(() => {});
  } catch (e) {
    showToast(`分析失败: ${e.message}`);
  }
}

async function reviewOne(id, showMessage = true) {
  if (!id) return;
  try {
    const res = await api(`/api/watchlist/${id}/review`, { method: 'POST', body: '{}', timeoutMs: 30000 });
    if (showMessage) {
      const total = Number(res?.overview?.review_total || 0);
      const acc = Number(res?.overview?.review_accuracy_rate || 0);
      showToast(`复盘完成：样本 ${total}，准确率 ${fmtNum(acc, 1)}%`);
    }
    await loadWatchlist();
    if (state.selectedId === id) {
      await loadInsights(id).catch(() => {});
      await loadReviewDetail(id, false).catch(() => {});
    }
  } catch (e) {
    if (showMessage) {
      showToast(`复盘失败: ${e.message}`);
    }
  }
}

async function reviewAll(showMessage = true) {
  try {
    const res = await api('/api/watchlist/review-all', {
      method: 'POST',
      body: JSON.stringify({ watchlist_limit: 240, insight_limit: 36 }),
      timeoutMs: 120000,
    });
    if (showMessage) {
      const summary = res.summary || {};
      showToast(`复盘完成：${Number(summary.watchlists || 0)}只，更新${Number(summary.upserts || 0)}条`);
    }
    await loadWatchlist();
    if (state.selectedId) {
      await loadInsights(state.selectedId).catch(() => {});
      await loadReviewDetail(state.selectedId, false).catch(() => {});
    }
  } catch (e) {
    if (showMessage) {
      showToast(`全池复盘失败: ${e.message}`);
    }
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
    await reviewAll(false);
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
  const panel = $('#watchSidePanel');
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
    const firstInput = $('#watchForm input[name="symbol"]');
    if (firstInput && typeof firstInput.focus === 'function') {
      setTimeout(() => firstInput.focus(), 80);
    }
  }
  if (showTip) {
    showToast(state.sidePanelOpen ? '已展开右侧面板' : '已收起右侧面板');
  }
}

function renderAll() {
  renderKpi(state.rows);
  renderTable(state.rows);
  renderReviewDashboard(state.rows);
  const mode = state.sortByScore ? '综合评分排序' : '优先级排序';
  $('#watchStamp').textContent = `${new Date().toLocaleString('zh-CN')} · ${mode}`;
  updateSortButton();
  updateSidePanelButton();
}

async function loadWatchlist() {
  if (state.loading) return;
  state.loading = true;
  try {
    const [data, globalSnap] = await Promise.all([
      api('/api/watchlist'),
      api('/api/watchlist/review/snapshots/global?days=45', { timeoutMs: 25000 }).catch(() => ({ series: [], latest: null })),
    ]);

    const rows = data.watchlist || [];
    state.globalSnapshots = Array.isArray(globalSnap?.series) ? globalSnap.series : [];
    state.globalSnapshotLatest = globalSnap?.latest || null;
    state.rows = rows;
    renderAll();
    if (state.selectedId && rows.some((x) => Number(x.id) === Number(state.selectedId))) {
      await loadInsights(state.selectedId).catch(() => {});
      if (state.reviewDetailId !== Number(state.selectedId) || !state.reviewDetailData) {
        await loadReviewDetail(state.selectedId, false).catch(() => {});
      } else {
        const freshRow = rows.find((x) => Number(x.id) === Number(state.selectedId)) || null;
        renderReviewDetailBox(freshRow, state.reviewDetailData);
      }
    } else {
      state.selectedId = null;
      state.reviewDetailId = null;
      state.reviewDetailData = null;
      renderReviewDetailBox(null, null);
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
  $('#openHealthBtn')?.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    openExternalPage('/health.html');
  });

  $('#sortByScoreBtn')?.addEventListener('click', () => {
    state.sortByScore = !state.sortByScore;
    renderAll();
    showToast(state.sortByScore ? '已启用综合评分排序' : '已切换为优先级排序');
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

  $('#refreshBtn').addEventListener('click', () => loadWatchlist().catch((e) => showToast(e.message)));
  $('#analyzeAllBtn').addEventListener('click', () => analyzeAll(true));
  $('#reviewAllBtn')?.addEventListener('click', () => reviewAll(true));
  $('#refreshReviewDashboardBtn')?.addEventListener('click', async () => {
    const globalSnap = await api('/api/watchlist/review/snapshots/global?days=45', { timeoutMs: 25000 }).catch(() => ({ series: [], latest: null }));
    state.globalSnapshots = Array.isArray(globalSnap?.series) ? globalSnap.series : [];
    state.globalSnapshotLatest = globalSnap?.latest || null;
    renderReviewDashboard(state.rows);
    if (state.selectedId) {
      await loadReviewDetail(state.selectedId, true).catch(() => {});
    } else {
      showToast('已刷新全局复盘看板');
    }
  });
  $('#openSelectedDetailBtn')?.addEventListener('click', () => {
    if (!state.selectedId) {
      showToast('请先在表格中选择一只股票');
      return;
    }
    openDetail(state.selectedId);
  });

  $('#logoutBtn').addEventListener('click', async () => {
    await api('/api/auth/logout', { method: 'POST', body: '{}' }).catch(() => {});
    location.href = '/';
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && state.sidePanelOpen) {
      setSidePanelOpen(false);
    }
  });
}

function openDetail(id) {
  if (!id) return;
  openExternalPage(`/watch-detail.html?id=${encodeURIComponent(String(id))}`);
}

async function boot() {
  try {
    const saved = localStorage.getItem(SIDE_PANEL_STORAGE_KEY);
    state.sidePanelOpen = saved === '1';
  } catch (_) {
    state.sidePanelOpen = false;
  }
  applySidePanelState();
  clearReviewPanels();
  if ($('#openSelectedDetailBtn')) {
    $('#openSelectedDetailBtn').disabled = true;
  }

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

