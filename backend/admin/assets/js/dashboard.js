/* Dashboard: 14-day chart and live refresh of the latest-orders panel */
(function ($) {
  'use strict';
  const d = window.DASH;
  const canvas = document.getElementById('dayChart');
  if (d && canvas && window.VCCharts) {
    VCCharts.bar(canvas, d.labels, d.values, { money: d.money, name: d.name, highlightLast: true });
  }

  function refreshRecent() {
    $.ajax({ url: 'index.php', data: { partial: 'recent' }, dataType: 'html' }).done((html) => { $('#recentOrders').html(html); });
  }
  document.addEventListener('vc:new-orders', refreshRecent);
  document.addEventListener('vc:poll', (e) => {
    const p = e.detail && e.detail.pending;
    if (typeof p === 'number') $('#kpiPending').text(p).closest('.kpi').toggleClass('attention', p > 0);
  });
})(jQuery);
