/**
 * echarts_charts.js — обёртка ECharts для аналитических вкладок.
 */
(function (global) {
  function getEl(id) {
    var el = typeof id === 'string' ? document.getElementById(id) : id;
    return el;
  }

  function ensureChart(el) {
    if (!global.echarts) return null;
    var existing = global.echarts.getInstanceByDom(el);
    if (existing) existing.dispose();
    return global.echarts.init(el, null, { renderer: 'svg' });
  }

  function emptyHtml(msg) {
    return '<p class="chart-empty">' + (msg || 'Нет данных') + '</p>';
  }

  function fmtRub(n) {
    return Math.round(Number(n) || 0).toLocaleString('ru-RU') + ' ₽';
  }

  function mount(id, option, onClick) {
    var el = getEl(id);
    if (!el) return;
    if (!global.echarts) {
      el.innerHTML = emptyHtml('ECharts не загружен');
      return;
    }
    var chart = ensureChart(el);
    if (!chart) return;
    chart.setOption(option);
    if (onClick) {
      el.style.cursor = 'pointer';
      chart.off('click');
      chart.on('click', function (params) {
        onClick(params);
      });
    }
    if (!el._echartsResize) {
      el._echartsResize = true;
      window.addEventListener('resize', function () {
        var c = global.echarts.getInstanceByDom(el);
        if (c) c.resize();
      });
    }
    return chart;
  }

  function funnelChart(id, items, opts) {
    opts = opts || {};
    var el = getEl(id);
    if (!el) return;
    if (!items || !items.length) {
      el.innerHTML = emptyHtml();
      return;
    }
    var data = items.map(function (i) {
      return { name: i.stage || i.label, value: i.count || i.value || 0, amount: i.amount || 0 };
    });
    var height = Math.max(360, Math.min(720, data.length * 34 + 80));
    el.style.height = height + 'px';
    el.classList.add('echart-box-funnel');
    var chart = mount(id, {
      tooltip: {
        trigger: 'item',
        formatter: function (p) {
          var d = data[p.dataIndex] || {};
          return (d.name || '') + '<br>Сделок: ' + (d.value || 0) +
            (d.amount ? '<br>Сумма: ' + fmtRub(d.amount) : '');
        },
      },
      series: [{
        type: 'funnel',
        left: '4%',
        width: '46%',
        top: 16,
        bottom: 16,
        minSize: '6%',
        maxSize: '100%',
        sort: 'descending',
        gap: 2,
        label: {
          show: true,
          position: 'right',
          fontSize: 11,
          formatter: function (p) {
            var name = String(p.name || '');
            return (name.length > 28 ? name.slice(0, 26) + '…' : name) + ': ' + p.value;
          },
        },
        labelLine: { show: true, length: 12, lineStyle: { width: 1 } },
        emphasis: { label: { fontSize: 12, fontWeight: 'bold' } },
        data: data,
      }],
    }, opts.onClick);
    if (chart) chart.resize();
  }

  function barChart(id, items, opts) {
    opts = opts || {};
    var el = getEl(id);
    if (!el) return;
    if (!items || !items.length) {
      el.innerHTML = emptyHtml();
      return;
    }
    var labels = items.map(function (i) { return i.stage || i.label || ''; });
    var values = items.map(function (i) { return i.count || i.value || 0; });
    var colors = items.map(function (i) { return i.color || opts.color || '#00a2e8'; });
    mount(id, {
      tooltip: { trigger: 'axis' },
      grid: { left: 120, right: 24, top: 16, bottom: 24 },
      xAxis: { type: 'value' },
      yAxis: { type: 'category', data: labels.slice().reverse(), axisLabel: { width: 110, overflow: 'truncate' } },
      series: [{
        type: 'bar',
        data: values.slice().reverse().map(function (v, idx) {
          var cIdx = values.length - 1 - idx;
          return { value: v, itemStyle: { color: colors[cIdx] || '#00a2e8' } };
        }),
      }],
    }, opts.onClick);
  }

  function paretoChart(id, items, opts) {
    var el = getEl(id);
    if (!el) return;
    if (!items || !items.length) {
      el.innerHTML = emptyHtml();
      return;
    }
    var labels = items.map(function (i) { return i.reason || i.label; });
    mount(id, {
      tooltip: { trigger: 'axis' },
      legend: { data: ['Сделок', 'Накопительно %'] },
      grid: { left: 48, right: 48, bottom: 80 },
      xAxis: { type: 'category', data: labels, axisLabel: { rotate: 35, interval: 0 } },
      yAxis: [
        { type: 'value', name: 'Сделок' },
        { type: 'value', name: '%', max: 100 },
      ],
      series: [
        { name: 'Сделок', type: 'bar', data: items.map(function (i) { return i.count; }), itemStyle: { color: '#ff5757' } },
        { name: 'Накопительно %', type: 'line', yAxisIndex: 1, data: items.map(function (i) { return i.cumulative_pct; }), itemStyle: { color: '#f59e0b' } },
      ],
    }, opts && opts.onClick);
  }

  function boxPlotChart(id, box, title) {
    var el = getEl(id);
    if (!el) return;
    if (!box) {
      el.innerHTML = emptyHtml();
      return;
    }
    mount(id, {
      title: title ? { text: title, left: 'center', textStyle: { fontSize: 13 } } : undefined,
      tooltip: {
        formatter: function () {
          return 'Медиана: ' + box.median + ' дн.<br>Q1–Q3: ' + box.q1 + '–' + box.q3 +
            '<br>Среднее: ' + box.mean + ' дн.<br>n=' + box.count;
        },
      },
      grid: { left: 100, right: 48, top: title ? 40 : 24, bottom: 40 },
      xAxis: { type: 'value', name: 'Дней', nameLocation: 'middle', nameGap: 24 },
      yAxis: { type: 'category', data: ['Цикл сделки'] },
      series: [{
        type: 'boxplot',
        data: [[box.min, box.q1, box.median, box.q3, box.max]],
        itemStyle: { color: '#00a2e8', borderColor: '#0077a8' },
        layout: 'horizontal',
      }],
    });
  }

  function gaugeChart(id, pct, opts) {
    opts = opts || {};
    var el = getEl(id);
    if (!el) return;
    var val = pct == null ? 0 : Math.min(150, Math.max(0, Number(pct)));
    mount(id, {
      series: [{
        type: 'gauge',
        min: 0,
        max: 150,
        progress: { show: true, width: 14 },
        axisLine: { lineStyle: { width: 14 } },
        detail: {
          valueAnimation: true,
          formatter: '{value}%',
          fontSize: 22,
        },
        data: [{ value: Math.round(val * 10) / 10, name: opts.title || 'К плану' }],
      }],
    });
  }

  function scatterChart(id, points, opts) {
    opts = opts || {};
    var el = getEl(id);
    if (!el) return;
    if (!points || !points.length) {
      el.innerHTML = emptyHtml();
      return;
    }
    mount(id, {
      tooltip: {
        formatter: function (p) {
          var d = p.data;
          return (d[2] || '') + '<br>Активность: ' + d[0] + '%<br>Выручка: ' + fmtRub(d[1]);
        },
      },
      grid: { left: 48, right: 24, top: 24, bottom: 48 },
      xAxis: { name: 'Активность %', nameLocation: 'middle', nameGap: 28 },
      yAxis: { name: 'Выручка', axisLabel: { formatter: function (v) { return fmtRub(v); } } },
      series: [{
        type: 'scatter',
        symbolSize: 12,
        data: points.map(function (p) { return [p.x, p.y, p.name]; }),
        itemStyle: { color: '#00a2e8' },
      }],
    }, opts.onClick);
  }

  function hBarChart(id, items, opts) {
    opts = opts || {};
    var el = getEl(id);
    if (!el) return;
    if (!items || !items.length) {
      el.innerHTML = emptyHtml();
      return;
    }
    mount(id, {
      tooltip: { trigger: 'axis' },
      grid: { left: 120, right: 40, top: 16, bottom: 24 },
      xAxis: { type: 'value', max: opts.max || 150, axisLabel: { formatter: '{value}%' } },
      yAxis: { type: 'category', data: items.map(function (i) { return i.label; }).reverse() },
      series: [{
        type: 'bar',
        data: items.map(function (i) { return i.value; }).reverse(),
        itemStyle: { color: opts.color || '#00a2e8' },
        label: { show: true, position: 'right', formatter: function (p) { return p.value + '%'; } },
      }],
    }, opts.onClick);
  }

  global.EChartsHelper = {
    mount: mount,
    funnelChart: funnelChart,
    barChart: barChart,
    paretoChart: paretoChart,
    boxPlotChart: boxPlotChart,
    gaugeChart: gaugeChart,
    scatterChart: scatterChart,
    hBarChart: hBarChart,
    fmtRub: fmtRub,
  };
})(window);
