/* =====================================================================
   Chart.js theme for the admin — one place for chart styling.
   Rules: one y-axis, thin bars with 4px rounded ends, 2px lines,
   recessive grid, text in ink colours (never the series colour),
   hover tooltips on every chart.
   ===================================================================== */
(function () {
  'use strict';
  if (!window.Chart) return;
  const css = getComputedStyle(document.documentElement);
  const v = (name) => css.getPropertyValue(name).trim();

  const C = {
    s1: v('--chart-1') || '#2E7D4F',
    s2: v('--chart-2') || '#EDA100',
    s3: v('--chart-3') || '#4A3AA7',
    grid: v('--chart-grid') || '#EEE7D8',
    ink: v('--vc-ink') || '#1E2A22',
    muted: v('--vc-muted') || '#66705F',
    surface: '#FFFFFF',
  };

  Chart.defaults.font.family = v('--body') || 'Lato, system-ui, sans-serif';
  Chart.defaults.font.size = 12;
  Chart.defaults.color = C.muted;
  Chart.defaults.maintainAspectRatio = false;
  Chart.defaults.plugins.legend.display = false;
  Chart.defaults.plugins.tooltip.backgroundColor = '#1E2A22';
  Chart.defaults.plugins.tooltip.padding = 10;
  Chart.defaults.plugins.tooltip.cornerRadius = 6;
  Chart.defaults.plugins.tooltip.displayColors = false;
  Chart.defaults.plugins.tooltip.titleFont = { weight: '700' };
  Chart.defaults.interaction = { mode: 'index', intersect: false };

  const inr = (n) => '₹' + Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 });
  const inrShort = (n) => n >= 100000 ? '₹' + (n / 100000).toFixed(1).replace(/\.0$/, '') + 'L'
    : n >= 1000 ? '₹' + (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k' : '₹' + n;

  const axes = (money, opts = {}) => ({
    x: { grid: { display: false }, border: { color: C.grid }, ticks: { maxRotation: 0, autoSkipPadding: 12, color: C.muted } },
    y: {
      beginAtZero: true, grid: { color: C.grid }, border: { display: false },
      ticks: { color: C.muted, precision: 0, maxTicksLimit: 5, callback: money ? (val) => inrShort(val) : undefined },
      ...opts.y,
    },
  });

  /** Single-series vertical bars (revenue per day, orders per hour…). */
  function bar(canvas, labels, values, { money = false, name = 'Value', color = C.s1, highlightLast = false } = {}) {
    const colors = values.map((_, i) => (highlightLast && i < values.length - 1 ? color + 'B3' : color));
    return new Chart(canvas, {
      type: 'bar',
      data: { labels, datasets: [{ label: name, data: values, backgroundColor: colors, hoverBackgroundColor: color, borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: 'bottom', maxBarThickness: 28, categoryPercentage: .8, barPercentage: .85 }] },
      options: { scales: axes(money), plugins: { tooltip: { callbacks: { label: (ctx) => `${name}: ${money ? inr(ctx.parsed.y) : ctx.parsed.y}` } } } },
    });
  }

  /** One or more lines on a single shared y-axis. series: [{name, values, color}] */
  function line(canvas, labels, series, { money = false } = {}) {
    return new Chart(canvas, {
      type: 'line',
      data: {
        labels,
        datasets: series.map((s, i) => ({
          label: s.name, data: s.values, borderColor: s.color || [C.s1, C.s3, C.s2][i], backgroundColor: (s.color || C.s1) + '1A',
          borderWidth: 2, pointRadius: 0, pointHoverRadius: 5, pointHoverBorderWidth: 2, pointHoverBorderColor: C.surface,
          pointBackgroundColor: s.color || [C.s1, C.s3, C.s2][i], tension: 0, fill: series.length === 1,
        })),
      },
      options: {
        scales: axes(money),
        plugins: {
          legend: { display: series.length > 1, position: 'top', align: 'end', labels: { usePointStyle: true, pointStyle: 'line', color: C.ink, boxWidth: 18 } },
          tooltip: { displayColors: series.length > 1, callbacks: { label: (ctx) => `${ctx.dataset.label}: ${money ? inr(ctx.parsed.y) : ctx.parsed.y}` } },
        },
      },
    });
  }

  window.VCCharts = { colors: C, bar, line, inr };
})();
