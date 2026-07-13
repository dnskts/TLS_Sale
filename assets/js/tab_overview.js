/** tab_overview.js — вкладка «Обзор» (по образцу Сводного отчёта) */
window.TabOverview = {
  sectionBlock: function (title, note, innerHtml) {
    return '<div class="chart-slot chart-slot-wide chart-section-block">' +
      '<h4 class="chart-slot-title">' + title + '</h4>' +
      (note ? '<p class="tab-note tab-note-compact">' + note + '</p>' : '') +
      innerHtml + '</div>';
  },

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
      '<div class="charts-grid">' +
      '<div class="chart-slot chart-slot-wide">' +
      '<h4 class="chart-slot-title">Динамика продаж</h4>' +
      '<p class="tab-note tab-note-compact">По месяцам: продажи и прибыль.</p>' +
      '<div id="ov-trend"></div></div></div>' +

      self.sectionBlock('Свод продажи', 'Сумма продаж после возврата',
        '<div class="charts-grid-4">' +
      self.pieSlot('ov-src', 'Источник продаж', '1С и Битрикс: продажи минус возвраты') +
      self.pieSlot('ov-team', 'Команда') +
      self.pieSlot('ov-ctype', 'Тип клиента', '1С: колонка «Тип клиента» (пока нет — отдельный сегмент) · Битрикс') +
      self.pieSlot('ov-cat', 'Категория') +
      self.pieSlot('ov-ch', 'Канал') +
      '</div>') +

      self.sectionBlock('Свод прибыль', 'Прибыль РС ТЛС без НДС',
        '<div class="charts-grid-4">' +
      self.pieSlot('ov-pteam', 'Команда') +
      self.pieSlot('ov-pctype', 'Тип клиента') +
      self.pieSlot('ov-pcat', 'Категория') +
      self.pieSlot('ov-pch', 'Канал') +
      '</div>') +

      self.sectionBlock('Свод обращения', 'Сделки Битрикс (= кейс). Пироги — за период фильтра; график по месяцам — с начала года',
        '<div class="charts-grid-4">' +
      self.pieSlot('ov-ares', 'Результат сделки') +
      self.pieSlot('ov-afun', 'Воронка') +
      '<div class="chart-slot chart-slot-wide chart-slot-span-2">' +
      '<h4 class="chart-slot-title">Создано сделок по месяцам</h4>' +
      '<p class="tab-note tab-note-compact">По дате создания в Битрикс, с января по конец выбранного периода</p>' +
      '<div id="ov-appeals-trend"></div></div>' +
      '</div>') +

      self.sectionBlock('Статистика по клиенту', '',
        '<div class="charts-grid-4">' +
      self.pieSlot('ov-cl-type', 'Тип клиента') +
      self.pieSlot('ov-cl-card', 'Тип карты') +
      '<div class="chart-slot chart-slot-span-2">' +
      '<h4 class="chart-slot-title">Топ-10 клиентов по продажам</h4><div id="ov-top-clients"></div></div>' +
      '</div>') +

      self.sectionBlock('Структура продаж', '',
        '<div class="charts-grid" id="ov-structure-grid"></div>');

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
      horizontal: true,
      legendA: 'Продажи',
      legendB: 'Прибыль',
    });

    SimpleCharts.pieChart('ov-src', (data.by_source || []).map(function (r) {
      return {
        label: r.label,
        legendLabel: r.label === '1С' ? '1С — продажи и возвраты' : 'Битрикс — успех и возвраты',
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
      valueFormat: 'count',
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
      valueFormat: 'rub',
      onClick: function (item) { drillSales({ clients: [item.label] }); },
    });

    var structLabels = {
      category: 'Категория', channel: 'Канал', client_type: 'Тип клиента',
      card_type: 'Тип карты', request_type: 'Тип запроса', partner: 'Партнёр',
    };
    var structKeys = Object.keys(structLabels);
    document.getElementById('ov-structure-grid').innerHTML = structKeys.map(function (key) {
      return '<div class="chart-slot"><h4 class="chart-slot-title">' + structLabels[key] + '</h4><div id="ov-st-' + key + '"></div></div>';
    }).join('');
    var structData = await ctx.api('api/structure.php', ctx.filters);
    structKeys.forEach(function (key) {
      SimpleCharts.barChart('ov-st-' + key, (structData[key] || []).map(function (r) {
        return { label: r.label, value: Math.round(r.sales), count: r.count || 0 };
      }), {
        valueFormat: 'rub',
        showCount: true,
        countLabel: 'сделок',
      });
    });
  },
};
