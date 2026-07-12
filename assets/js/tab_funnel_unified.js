/** tab_funnel_unified.js */
window.TabFunnelUnified = {
  async render(root, ctx) {
    root.innerHTML =
      '<h2>Воронка Общая</h2><p class="tab-note">1С и Битрикс рядом. Клик по сегменту — детализация.</p>' +
      '<div id="fu-stats" class="funnel-stats-row"></div>' +
      '<div class="charts-grid">' +
      '<div class="chart-slot"><h4>Объёмы</h4><div id="fu-cmp"></div></div>' +
      '<div class="chart-slot"><h4>1С: возвраты</h4><div id="fu-out-1c"></div></div>' +
      '<div class="chart-slot"><h4>Битрикс: успех</h4><div id="fu-out-bx"></div></div>' +
      '<div class="chart-slot chart-slot-wide"><h4>Динамика</h4>' +
      '<p class="tab-note tab-note-compact">По месяцам: операции 1С (date_operation) и созданные сделки Битрикс (deal_created_at)</p>' +
      '<div id="fu-trend"></div></div>' +
      '</div>' +
      '<p class="tab-note tab-note-compact">Блоки «возвраты» и «успех» — разные системы, напрямую не сравнивать.</p>';
    var data = await ctx.api('api/funnel_unified.php', Object.assign({ granularity: 'month' }, ctx.filters));
    var s = data.stats;
    document.getElementById('fu-stats').innerHTML = [
      ['1С операций', s.ops_total],
      ['1С возвратов', s.ops_refunds],
      ['1С прибыль', Math.round(s.ops_profit)],
      ['Битрикс создано', s.deals_created],
      ['Битрикс успех', s.deals_success],
      ['Конверсия Б24', s.deals_conversion_pct == null ? '—' : s.deals_conversion_pct.toFixed(1) + '%'],
    ].map(function (x) {
      return '<div class="funnel-stat-card"><div class="funnel-stat-value">' + x[1] + '</div><div class="funnel-stat-label">' + x[0] + '</div></div>';
    }).join('');

    function drillComparison(item) {
      var label = item.label;
      if (label.indexOf('1С') >= 0) {
        ctx.goToDetails({ view: 'operations_1c', source: '1c' });
      } else if (label.indexOf('успех') >= 0) {
        ctx.goToDetails({ view: 'deals_bitrix', source: 'bitrix', deal_result: 'Успех' });
      } else {
        ctx.goToDetails({ view: 'deals_bitrix', source: 'bitrix' });
      }
    }

    SimpleCharts.barChart('fu-cmp', Object.keys(data.comparison || {}).map(function (k) {
      return { label: k, value: data.comparison[k] };
    }), { onClick: drillComparison });

    var outcomes = data.outcomes || {};
    var opsRefunds = outcomes['1С возвраты'] || 0;
    var opsSales = (s.ops_total || 0) - opsRefunds;
    SimpleCharts.barChart('fu-out-1c', [
      { label: 'Возвраты', value: opsRefunds },
      { label: 'Продажи', value: opsSales },
    ], {
      color: '#ff5757',
      onClick: function (item) {
        ctx.goToDetails({
          view: 'operations_1c',
          source: '1c',
          operation_type: item.label === 'Возвраты' ? 'refund' : 'sales',
        });
      },
    });

    var bxSuccess = outcomes['Битрикс успех'] || 0;
    var bxOther = (s.deals_created || 0) - bxSuccess;
    SimpleCharts.barChart('fu-out-bx', [
      { label: 'Успех', value: bxSuccess },
      { label: 'Не успех', value: bxOther },
    ], {
      color: '#9bcb56',
      onClick: function (item) {
        if (item.label === 'Успех') {
          ctx.goToDetails({ view: 'deals_bitrix', source: 'bitrix', deal_result: 'Успех' });
        } else {
          ctx.goToDetails({ view: 'deals_bitrix', source: 'bitrix' });
        }
      },
    });

    SimpleCharts.groupBars('fu-trend', (data.trend || []).map(function (r) {
      return { period: r.period, a: r.ops_count, b: r.deals_created };
    }), { legendA: '1С операции', legendB: 'Битрикс создано' });
  },
};
