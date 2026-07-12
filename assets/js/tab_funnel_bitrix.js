/** tab_funnel_bitrix.js */
window.TabFunnelBitrix = {
  async render(root, ctx) {
    var self = this;
    root.innerHTML =
      '<h2>Воронка Битрикс</h2><p class="tab-note">Все сделки, период по дате создания. Клик по сегменту — детализация.</p>' +
      '<div id="fb-stats" class="funnel-stats-row"></div>' +
      '<div class="charts-grid">' +
      '<div class="chart-slot"><h4>Результат</h4><div id="fb-pie"></div></div>' +
      '<div class="chart-slot"><h4>Статусы</h4><div id="fb-status"></div></div>' +
      '<div class="chart-slot"><h4>Причины проигрыша</h4><div id="fb-lost"></div></div>' +
      '<div class="chart-slot"><h4>Созданные vs оплаченные</h4><div id="fb-trend"></div></div>' +
      '</div><div class="table-slot"><h4>Таблица причин</h4><div id="fb-table"></div></div>';
    var data = await ctx.api('api/funnel_bitrix.php', Object.assign({ granularity: 'month' }, ctx.filters));
    var s = data.stats;
    document.getElementById('fb-stats').innerHTML = [
      ['Создано', s.total_created],
      ['Успех', s.success_count],
      ['Конверсия', (s.conversion_pct == null ? '—' : s.conversion_pct.toFixed(1) + '%')],
      ['Оплачено в периоде', s.paid_in_period],
    ].map(function (x) {
      return '<div class="funnel-stat-card"><div class="funnel-stat-value">' + x[1] + '</div><div class="funnel-stat-label">' + x[0] + '</div></div>';
    }).join('');

    var drillBase = { view: 'deals_bitrix', source: 'bitrix' };
    function drill(field, item) {
      var d = Object.assign({}, drillBase);
      d[field] = item.label;
      ctx.goToDetails(d);
    }

    SimpleCharts.pieChart('fb-pie', Object.keys(data.results || {}).map(function (k) {
      return { label: k, value: data.results[k] };
    }), { onClick: function (item) { drill('deal_result', item); } });
    SimpleCharts.barChart('fb-status', Object.keys(data.statuses || {}).map(function (k) {
      return { label: k, value: data.statuses[k] };
    }), { onClick: function (item) { drill('deal_status', item); } });
    SimpleCharts.barChart('fb-lost', Object.keys(data.lost || {}).map(function (k) {
      return { label: k, value: data.lost[k] };
    }), { color: '#ff5757', onClick: function (item) { drill('lost_deal_reason', item); } });
    SimpleCharts.groupBars('fb-trend', (data.trend || []).map(function (r) {
      return { period: r.period, a: r.created, b: r.paid };
    }), { legendA: 'Создано', legendB: 'Оплачено' });
    document.getElementById('fb-table').innerHTML = tableHtml(
      ['Причина', 'Сделок', 'Доля %'],
      (data.lost_table || []).map(function (r) { return [r.lost_deal_reason, r.count, r.share_pct]; })
    );
  },
};
