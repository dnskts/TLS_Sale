/** tab_funnel_1c.js */
window.TabFunnel1c = {
  async render(root, ctx) {
    root.innerHTML =
      '<h2>Воронка 1С</h2>' +
      '<p class="tab-note">Все операции, период по date_operation. Клик по сегменту — детализация.</p>' +
      '<p class="tab-note">Подразделение 1С — поле из Excel (колонка «Подразделение»). Команда агента — в настройках справочника.</p>' +
      '<div id="f1-stats" class="funnel-stats-row"></div>' +
      '<div class="charts-grid">' +
      '<div class="chart-slot"><h4>Продажи vs возвраты</h4><div id="f1-pie"></div></div>' +
      '<div class="chart-slot"><h4>Категории</h4><div id="f1-cat"></div></div>' +
      '<div class="chart-slot"><h4>Каналы</h4><div id="f1-ch"></div></div>' +
      '<div class="chart-slot"><h4>Подразделение 1С</h4><div id="f1-dep"></div></div>' +
      '<div class="chart-slot"><h4>Динамика</h4><p class="tab-note tab-note-compact">По месяцам: продажи и возвраты (date_operation)</p><div id="f1-trend"></div></div>' +
      '</div>';
    var data = await ctx.api('api/funnel_1c.php', Object.assign({ granularity: 'month' }, ctx.filters));
    var s = data.stats;
    document.getElementById('f1-stats').innerHTML = [
      ['Операций', s.total_operations],
      ['Продажи', s.positive_count],
      ['Возвраты', s.refund_count],
      ['Доля возвратов', s.refund_pct == null ? '—' : s.refund_pct.toFixed(1) + '%'],
      ['Прибыль', Math.round(s.profit_total)],
    ].map(function (x) {
      return '<div class="funnel-stat-card"><div class="funnel-stat-value">' + x[1] + '</div><div class="funnel-stat-label">' + x[0] + '</div></div>';
    }).join('');

    var drillBase = { view: 'operations_1c', source: '1c' };
    function drill(field, item) {
      var d = Object.assign({}, drillBase);
      if (field === 'operation_type') {
        d.operation_type = item.label === 'Возвраты' ? 'refund' : 'sales';
      } else {
        d[field] = item.label;
      }
      ctx.goToDetails(d);
    }

    SimpleCharts.pieChart('f1-pie', Object.keys(data.operation_types || {}).map(function (k) {
      return { label: k, value: data.operation_types[k] };
    }), { onClick: function (item) { drill('operation_type', item); } });
    SimpleCharts.barChart('f1-cat', Object.keys(data.categories || {}).map(function (k) {
      return { label: k, value: data.categories[k] };
    }), { onClick: function (item) { drill('category', item); } });
    SimpleCharts.barChart('f1-ch', Object.keys(data.channels || {}).map(function (k) {
      return { label: k, value: data.channels[k] };
    }), { onClick: function (item) { drill('channel', item); } });
    SimpleCharts.barChart('f1-dep', Object.keys(data.departments || {}).map(function (k) {
      return { label: k, value: data.departments[k] };
    }), { onClick: function (item) { drill('department', item); } });
    SimpleCharts.groupBars('f1-trend', (data.trend || []).map(function (r) {
      return { period: r.period, a: r.sales, b: r.refunds };
    }), { legendA: 'Продажи', legendB: 'Возвраты' });
  },
};
