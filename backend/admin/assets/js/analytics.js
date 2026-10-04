/* Analytics charts */
(function ($) {
  'use strict';
  const d = window.ANALYTICS;
  if (!d || !window.VCCharts) return;
  const C = VCCharts.colors;

  // Revenue / orders trend — one measure at a time on a single axis
  let trend = null;
  function drawTrend(measure) {
    if (trend) trend.destroy();
    const money = measure === 'revenue';
    trend = VCCharts.line(document.getElementById('trendChart'), d.labels,
      [{ name: money ? 'Revenue' : 'Orders', values: d[measure], color: C.s1 }], { money });
  }
  drawTrend('revenue');
  $('[data-measure]').on('click', function () {
    $('[data-measure]').removeClass('active').attr('aria-pressed', 'false');
    $(this).addClass('active').attr('aria-pressed', 'true');
    drawTrend(this.dataset.measure);
  });

  // Busiest hours: highlight the peak, quiet the rest
  const peak = Math.max(...d.hours);
  const hourChart = VCCharts.bar(document.getElementById('hourChart'), d.hourLabels, d.hours, { name: 'Orders' });
  hourChart.data.datasets[0].backgroundColor = d.hours.map((v) => (v === peak && peak > 0 ? C.s1 : C.s1 + '73'));
  hourChart.update();

  // Visitors: page views and unique visitors share one unit, so one axis
  VCCharts.line(document.getElementById('visitChart'), d.labels, [
    { name: 'Page views', values: d.views, color: C.s1 },
    { name: 'Unique visitors', values: d.visitors, color: C.s3 },
  ]);

  $('#range').on('change', function () { $('.custom-range').prop('hidden', this.value !== 'custom'); });
})(jQuery);
