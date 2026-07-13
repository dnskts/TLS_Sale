/** tab_overview.js — вкладка «Обзор» (по образцу Сводного отчёта) */
window.TabOverview = {
  pieSlot: function (id, title, note) {
    return '<div class="chart-slot chart-slot-pie">' +
      '<h4 class="chart-slot-title">' + title + '</h4>' +
      (note ? '<p class="tab-note tab-note-compact">' + note + '</p>' : '') +
      '<div id="' + id + '"></div></div>';
  },

  metricValue: function (row, metric) {
    if (metric === 'profit') return Math.round(row.profit || 0);
    if (metric === 'count') return row.count || 0;
    return Math.round(row.sales || 0);
  },

  renderPieGroup: function (prefix, dataKey, data, metric, ctx, drillFn) {
    var self = this;
    var rows = data[dataKey] || [];
    SimpleCharts.pieChart(prefix, rows.map(function (r) {
      return { label: r.label, value: self.metricValue(r, metric) };
    }), {
      valueFormat: metric === 'count' ? 'count' : 'rub',
      onClick: drillFn ? function (item) { drillFn(item); } : undefined,
    });
  },

  monthRange: function (period) {
    var p = String(period || '');
    if (!/^\d{4}-\d{2}$/.test(p)) return null;
    var parts = p.split('-');
    var y = parseInt(parts[0], 10);
    var m = parseInt(parts[1], 10);
    var last = new Date(y, m, 0).getDate();
    var mm = parts[1];
    return { date_from: p + '-01', date_to: p + '-' + String(last).padStart(2, '0') };
  },

  async render(root, ctx) {
    var self = this;
    root.innerHTML =
      '<h2>Обзор</h2>' +
      '<div class="charts-grid">' +
      '<div class="chart-slot chart-slot-wide">' +
      '<h4 class="chart-slot-title">Динамика продаж</h4>' +
      '<p class="tab-note tab-note-compact">По месяцам: продажи и прибыль (sales_unified). Клик по столбцу — детализация за месяц.</p>' +
      '<div id="ov-trend"></div></div></div>' +

      '<h3 class="chart-section-title">Свод продажи</h3>' +
      '<p class="tab-note tab-note-compact">Сумма продаж после возврата · клик по сегменту — детализация</p>' +
      '<div class="charts-grid-4">' +
      self.pieSlot('ov-src', 'Источник продаж', '1С — операции · Битрикс — оплаченные сделки') +
      self.pieSlot('ov-team', 'Команда') +
      self.pieSlot('ov-ctype', 'Тип клиента') +
      self.pieSlot('ov-cat', 'Категория') +
      self.pieSlot('ov-ch', 'Канал') +
      '</div>' +

      '<h3 class="chart-section-title">Свод прибыль</h3>' +
      '<p class="tab-note tab-note-compact">Прибыль РС ТЛС без НДС</p>' +
      '<div class="charts-grid-4">' +
      self.pieSlot('ov-pteam', 'Команда') +
      self.pieSlot('ov-pctype', 'Тип клиента') +
      self.pieSlot('ov-pcat', 'Категория') +
      self.pieSlot('ov-pch', 'Канал') +
      '</div>' +

      '<h3 class="chart-section-title">Свод обращения</h3>' +
      '<p class="tab-note tab-note-compact">Сделки Битрикс (созданные в периоде) · клик — детализация</p>' +
      '<div class="charts-grid-4">' +
      self.pieSlot('ov-ares', 'Результат сделки') +
      self.pieSlot('ov-afun', 'Воронка') +
      '<div class="chart-slot chart-slot-wide chart-slot-span-2">' +
      '<h4 class="chart-slot-title">Создано сделок по месяцам</h4><div id="ov-appeals-trend"></div></div>' +
      '</div>' +

      '<h3 class="chart-section-title">Статистика по клиенту</h3>' +
      '<div class="charts-grid-4">' +
      self.pieSlot('ov-cl-type', 'Тип клиента') +
      self.pieSlot('ov-cl-card', 'Тип карты') +
      '<div class="chart-slot chart-slot-span-2">' +
      '<h4 class="chart-slot-title">Топ-10 клиентов по продажам</h4><div id="ov-top-clients"></div></div>' +
      '</div>';

    var data = ctx.overviewData;
    if (!data) {
      data = await ctx.api('api/overview.php', Object.assign({ granularity: 'month' }, ctx.filters));
    }

    function drillSales(extra) {
      ctx.goToDetails(Object.assign({ view: 'sales' }, extra));
    }
    function drillDeals(extra) {
      ctx.goToDetails(Object.assign({ view: 'deals_bitrix' }, extra));
    }

    SimpleCharts.groupBars('ov-trend', (data.trend || []).map(function (r) {
      return { period: r.period, a: r.sales, b: r.profit };
    }), {
      legendA: 'Продажи',
      legendB: 'Прибыль',
      onClick: function (item) {
        var range = self.monthRange(item.period);
        if (range) drillSales(range);
      },
    });

    SimpleCharts.pieChart('ov-src', (data.by_source || []).map(function (r) {
      return {
        label: r.label,
        legendLabel: r.label === '1С' ? '1С — операции' : 'Битрикс — сделки',
        value: Math.round(r.sales),
      };
    }), {
      valueFormat: 'rub',
      onClick: function (item) {
        drillSales({ source: item.label === '1С' ? '1c' : 'bitrix' });
      },
    });

    self.renderPieGroup('ov-team', 'by_team', data, 'sales', ctx, function (item) {
      drillSales({ teams: [item.label] });
    });
    self.renderPieGroup('ov-ctype', 'by_client_type', data, 'sales', ctx, function (item) {
      drillSales({ client_type: item.label });
    });
    self.renderPieGroup('ov-cat', 'by_category', data, 'sales', ctx, function (item) {
      drillSales({ category: item.label });
    });
    self.renderPieGroup('ov-ch', 'by_channel', data, 'sales', ctx, function (item) {
      drillSales({ channel: item.label });
    });

    self.renderPieGroup('ov-pteam', 'profit_by_team', data, 'profit', ctx, function (item) {
      drillSales({ teams: [item.label] });
    });
    self.renderPieGroup('ov-pctype', 'profit_by_client_type', data, 'profit', ctx, function (item) {
      drillSales({ client_type: item.label });
    });
    self.renderPieGroup('ov-pcat', 'profit_by_category', data, 'profit', ctx, function (item) {
      drillSales({ category: item.label });
    });
    self.renderPieGroup('ov-pch', 'profit_by_channel', data, 'profit', ctx, function (item) {
      drillSales({ channel: item.label });
    });

    SimpleCharts.pieChart('ov-ares', (data.appeals_by_result || []).map(function (r) {
      return { label: r.label, value: r.count };
    }), {
      valueFormat: 'count',
      onClick: function (item) { drillDeals({ deal_result: item.label }); },
    });
    SimpleCharts.pieChart('ov-afun', (data.appeals_by_funnel || []).map(function (r) {
      return { label: r.label, value: r.count };
    }), {
      valueFormat: 'count',
      onClick: function (item) { drillDeals({ appeal_funnel: item.label }); },
    });
    SimpleCharts.groupBars('ov-appeals-trend', (data.appeals_trend || []).map(function (r) {
      return { period: r.period, a: r.count, b: 0 };
    }), {
      legendA: 'Создано',
      onClick: function (item) {
        var range = self.monthRange(item.period);
        if (range) drillDeals(range);
      },
    });

    self.renderPieGroup('ov-cl-type', 'clients_by_type', data, 'sales', ctx, function (item) {
      drillSales({ client_type: item.label });
    });
    self.renderPieGroup('ov-cl-card', 'clients_by_card', data, 'sales', ctx, function (item) {
      drillSales({ card_type: item.label });
    });
    SimpleCharts.barChart('ov-top-clients', (data.top_clients || []).map(function (r) {
      return { label: r.label, value: Math.round(r.sales) };
    }), {
      onClick: function (item) { drillSales({ clients: [item.label] }); },
    });
  },
};
