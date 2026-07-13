/** tab_forecast.js — Прогноз и риски */
window.TabForecast = {
  fmtRub: function (n) {
    return Math.round(Number(n) || 0).toLocaleString('ru-RU') + ' ₽';
  },
  fmtPct: function (n) {
    return n == null ? '—' : Number(n).toFixed(1).replace('.', ',') + ' %';
  },

  tableHtml: function (headers, rows) {
    var h = '<table class="data-table"><thead><tr>' +
      headers.map(function (x) { return '<th>' + x + '</th>'; }).join('') + '</tr></thead><tbody>';
    rows.forEach(function (row) {
      h += '<tr>' + row.map(function (c) { return '<td>' + c + '</td>'; }).join('') + '</tr>';
    });
    return h + '</tbody></table>';
  },

  escapeHtml: function (s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  },

  async render(root, ctx) {
    var self = this;
    root.innerHTML =
      '<h2>Прогноз и риски</h2>' +
      '<p class="tab-note" id="fc-note"></p>' +
      '<div class="forecast-top-row">' +
      '<div class="chart-slot forecast-gauge-slot"><h4>Прогноз к плану</h4><div id="fc-gauge" class="echart-box echart-box-square"></div></div>' +
      '<div class="forecast-kpi-col" id="fc-kpis"></div>' +
      '</div>' +
      '<div class="table-slot"><h4>Сделки под риском</h4><div id="fc-risk"></div></div>' +
      '<div class="table-slot"><h4>Просроченные</h4><div id="fc-overdue"></div></div>' +
      '<div id="fc-signals" class="insights-block"></div>';

    var data = await ctx.api('api/forecast.php', ctx.filters);
    document.getElementById('fc-note').textContent = data.approximation_note || '';

    var f = data.forecast || {};
    EChartsHelper.gaugeChart('fc-gauge', f.plan_pct, { title: 'К плану' });

    document.getElementById('fc-kpis').innerHTML = [
      ['Закрыто в периоде', self.fmtRub(f.closed_amount)],
      ['Weighted pipeline', self.fmtRub(f.weighted_pipeline)],
      ['На финальных стадиях', self.fmtRub(f.final_stage_amount) + ' (' + (f.final_stage_count || 0) + ')'],
      ['Прогноз итого', self.fmtRub(f.forecast_total)],
      ['План', self.fmtRub(f.plan_total)],
      ['Открытых сделок', (data.pipeline && data.pipeline.open_count) || 0],
    ].map(function (x) {
      return '<div class="funnel-stat-card"><div class="funnel-stat-value">' + x[1] + '</div><div class="funnel-stat-label">' + x[0] + '</div></div>';
    }).join('');

    document.getElementById('fc-risk').innerHTML = (data.at_risk || []).length
      ? self.tableHtml(
        ['№', 'Клиент', 'Агент', 'Сумма', 'Стадия', 'Причины'],
        (data.at_risk || []).map(function (r) {
          return [
            r.deal_no, r.client, r.agent_display,
            Math.round(r.sales_amount).toLocaleString('ru-RU'),
            r.deal_status, (r.reasons || []).join('; '),
          ];
        })
      )
      : '<p class="tab-note">Нет сделок под риском по правилам (порог суммы и SLA в настройках).</p>';

    var od = data.overdue || {};
    document.getElementById('fc-overdue').innerHTML =
      '<p class="tab-note">Просрочено: ' + (od.count || 0) + ' на сумму ' + self.fmtRub(od.amount) + '</p>' +
      ((od.items || []).length
        ? self.tableHtml(
          ['№', 'Клиент', 'Агент', 'Сумма', 'Дата', 'Дней'],
          (od.items || []).slice(0, 20).map(function (r) {
            return [
              r.deal_no, r.client, r.agent_display,
              Math.round(r.sales_amount).toLocaleString('ru-RU'),
              r.due_date, r.days_overdue,
            ];
          })
        )
        : '');

    var signals = data.signals || [];
    document.getElementById('fc-signals').innerHTML = signals.length
      ? '<h3 class="chart-section-title">Сигналы</h3><div class="insights-cards">' +
        signals.map(function (sig) {
          return '<div class="insight-card ins-' + sig.level + '"><strong>' + self.escapeHtml(sig.title) + '</strong><p>' + self.escapeHtml(sig.detail) + '</p></div>';
        }).join('') + '</div>'
      : '';
  },
};
