/** tab_quality.js — Качество и аналитика потерь */
window.TabQuality = {
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

  async render(root, ctx) {
    var self = this;
    root.innerHTML =
      '<h2>Качество и аналитика потерь</h2>' +
      '<p class="tab-note" id="ql-notes"></p>' +
      '<div id="ql-cycle-stats" class="funnel-stats-row"></div>' +
      '<div class="charts-grid">' +
      '<div class="chart-slot chart-slot-wide"><h4>Pareto: причины проигрыша</h4><div id="ql-pareto" class="echart-box echart-box-tall"></div></div>' +
      '<div class="chart-slot"><h4>Box plot: цикл сделки</h4><div id="ql-box" class="echart-box echart-box-tall"></div></div>' +
      '</div>' +
      '<div class="table-slot"><h4>Эффективность каналов / источников</h4>' +
      '<p class="tab-note tab-note-compact">Источник лида (если есть), иначе канал связи из Битрикс. «Не указан» — оба поля пустые.</p>' +
      '<div id="ql-channels"></div></div>';

    var data = await ctx.api('api/quality.php', ctx.filters);
    document.getElementById('ql-notes').textContent =
      [data.cycle_note, data.source_note].filter(Boolean).join(' · ');

    var c = data.cycle || {};
    document.getElementById('ql-cycle-stats').innerHTML = [
      ['Средний цикл', c.avg_days != null ? c.avg_days + ' дн.' : '—'],
      ['Успех', c.avg_success_days != null ? c.avg_success_days + ' дн.' : '—'],
      ['Проиграна', c.avg_lost_days != null ? c.avg_lost_days + ' дн.' : '—'],
    ].map(function (x) {
      return '<div class="funnel-stat-card"><div class="funnel-stat-value">' + x[1] + '</div><div class="funnel-stat-label">' + x[0] + '</div></div>';
    }).join('');

    EChartsHelper.paretoChart('ql-pareto', data.pareto_lost || [], {
      onClick: function (params) {
        if (params.name) ctx.goToDetails({ view: 'deals_bitrix', lost_deal_reason: params.name });
      },
    });
    EChartsHelper.boxPlotChart('ql-box', c.box_all || c.box_success, 'Длительность цикла (дни)');

    document.getElementById('ql-channels').innerHTML = self.tableHtml(
      ['Источник / канал', 'Создано', 'Успех', 'Конверсия', 'Ср. чек'],
      (data.channel_conversion || []).map(function (r) {
        return [
          '<a href="#" class="chart-drill-link" data-channel="' + escapeHtml(r.channel) + '">' + escapeHtml(r.channel) + '</a>',
          r.created, r.success, self.fmtPct(r.conversion_pct),
          r.avg_check != null ? Math.round(r.avg_check).toLocaleString('ru-RU') + ' ₽' : '—',
        ];
      })
    );
    document.querySelectorAll('#ql-channels .chart-drill-link').forEach(function (a) {
      a.onclick = function (ev) {
        ev.preventDefault();
        ctx.goToDetails({ view: 'deals_bitrix', source: 'bitrix', channel: a.getAttribute('data-channel') });
      };
    });
  },
};

function escapeHtml(s) {
  return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
