/**
 * charts_simple.js
 *
 * Простые графики без внешних библиотек (бар / pie через SVG).
 * opts.onClick(item) — drill-down по клику на сегмент.
 */
(function (global) {
  function clear(el) {
    while (el.firstChild) el.removeChild(el.firstChild);
  }

  function bindChartClick(el, items, onClick, attr) {
    if (!onClick) return;
    attr = attr || 'data-bar-idx';
    el.style.cursor = 'pointer';
    el.onclick = function (ev) {
      var node = ev.target.closest('[' + attr + '], [data-pie-idx], [data-bar-idx]');
      if (!node) return;
      var idx = parseInt(
        node.getAttribute(attr) || node.getAttribute('data-pie-idx') || node.getAttribute('data-bar-idx'),
        10
      );
      if (!isNaN(idx) && items[idx]) {
        onClick(items[idx], idx);
      }
    };
  }

  function formatShortNum(n) {
    n = Math.abs(Number(n) || 0);
    if (n >= 1e6) return (n / 1e6).toFixed(1).replace('.', ',') + ' млн';
    if (n >= 1e3) return Math.round(n / 1e3) + ' т';
    return String(Math.round(n));
  }

  function formatPieValue(val, total, fmt) {
    var pct = total ? ((Number(val) || 0) / total * 100).toFixed(1).replace('.', ',') : '0';
    if (fmt === 'count') {
      var c = Math.round(Number(val) || 0).toLocaleString('ru-RU');
      return c + ' (' + pct + ' %)';
    }
    var rub = Math.round(Number(val) || 0).toLocaleString('ru-RU');
    return rub + ' ₽ (' + pct + ' %)';
  }

  function formatPeriodLabel(period) {
    if (!period) return '';
    var p = String(period);
    if (/^\d{4}-\d{2}-\d{2}$/.test(p)) {
      var d = p.split('-');
      return d[2] + '.' + d[1];
    }
    if (/^\d{4}-\d{2}$/.test(p)) {
      var m = p.split('-');
      return m[1] + '.' + m[0].slice(2);
    }
    return p.length > 8 ? p.slice(0, 8) : p;
  }

  function barChart(containerId, items, opts) {
    opts = opts || {};
    var el = document.getElementById(containerId);
    if (!el) return;
    clear(el);
    items = (items || []).slice(0, opts.limit || 12);
    if (!items.length) {
      el.innerHTML = '<p class="chart-empty">Нет данных для выбранных фильтров</p>';
      return;
    }
    var max = Math.max.apply(null, items.map(function (i) { return Number(i.value) || 0; })) || 1;
    var width = el.clientWidth || 480;
    var rowH = 28;
    var height = items.length * rowH + 20;
    var labelW = 140;
    var color = opts.color || '#00a2e8';
    var svg = '<svg width="' + width + '" height="' + height + '" xmlns="http://www.w3.org/2000/svg" class="chart-svg-clickable">';
    items.forEach(function (item, idx) {
      var y = idx * rowH + 4;
      var w = Math.max(2, ((Number(item.value) || 0) / max) * (width - labelW - 60));
      var label = String(item.label || '').slice(0, 22);
      var hover = opts.onClick ? ' class="chart-bar-segment"' : '';
      svg += '<text x="0" y="' + (y + 16) + '" font-size="12" fill="#535c69"' + (opts.onClick ? ' data-bar-idx="' + idx + '" class="chart-bar-label"' : '') + '>' + escapeXml(label) + '</text>';
      svg += '<rect x="' + labelW + '" y="' + y + '" width="' + w + '" height="18" fill="' + color + '" rx="2" data-bar-idx="' + idx + '"' + hover + '/>';
      svg += '<text x="' + (labelW + w + 6) + '" y="' + (y + 14) + '" font-size="11" fill="#828b95" data-bar-idx="' + idx + '">' + escapeXml(String(item.value)) + '</text>';
    });
    svg += '</svg>';
    el.innerHTML = svg;
    bindChartClick(el, items, opts.onClick);
  }

  function pieChart(containerId, items, opts) {
    opts = opts || {};
    var el = document.getElementById(containerId);
    if (!el) return;
    clear(el);
    items = (items || []).filter(function (i) { return (Number(i.value) || 0) > 0; });
    var total = items.reduce(function (s, i) { return s + (Number(i.value) || 0); }, 0);
    if (!total) {
      el.innerHTML = '<p class="chart-empty">Нет данных</p>';
      return;
    }
    var fmt = opts.valueFormat || 'rub';
    var colors = ['#9bcb56', '#ff5757', '#00a2e8', '#828b95', '#ff9a00', '#2067b0', '#535c69', '#2067b0'];
    var cx = 120, cy = 120, r = 90;
    var angle = -Math.PI / 2;
    var paths = '';
    var legend = '<div class="pie-legend pie-legend-compact">';
    items.forEach(function (item, idx) {
      var val = Number(item.value) || 0;
      if (val <= 0) return;
      var frac = val / total;
      var next = angle + frac * Math.PI * 2;
      var x1 = cx + r * Math.cos(angle);
      var y1 = cy + r * Math.sin(angle);
      var x2 = cx + r * Math.cos(next);
      var y2 = cy + r * Math.sin(next);
      var large = frac > 0.5 ? 1 : 0;
      var color = colors[idx % colors.length];
      var cls = opts.onClick ? ' class="chart-pie-segment"' : '';
      paths += '<path d="M' + cx + ',' + cy + ' L' + x1 + ',' + y1 + ' A' + r + ',' + r + ' 0 ' + large + ' 1 ' + x2 + ',' + y2 + ' Z" fill="' + color + '" data-pie-idx="' + idx + '"' + cls + '/>';
      var legCls = opts.onClick ? ' class="pie-legend-item"' : '';
      var legLabel = item.legendLabel || item.label;
      legend += '<div' + legCls + ' data-pie-idx="' + idx + '"><span class="swatch" style="background:' + color + '"></span> ' +
        escapeXml(legLabel) + ': ' + formatPieValue(val, total, fmt) + '</div>';
      angle = next;
    });
    legend += '</div>';
    el.innerHTML = '<div class="pie-wrap chart-clickable"><svg width="240" height="240" viewBox="0 0 240 240">' + paths + '</svg>' + legend + '</div>';
    bindChartClick(el, items, opts.onClick);
  }

  function escapeXml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function groupBars(containerId, series, opts) {
    opts = opts || {};
    var el = document.getElementById(containerId);
    if (!el) return;
    clear(el);
    series = series || [];
    if (!series.length) {
      el.innerHTML = '<p class="chart-empty">Нет данных</p>';
      return;
    }
    var max = 1;
    series.forEach(function (r) {
      max = Math.max(max, Number(r.a) || 0, Number(r.b) || 0);
    });
    var width = el.clientWidth || 560;
    var padL = 48;
    var padR = 12;
    var padTop = 8;
    var chartH = 160;
    var padBottom = 36;
    var plotW = width - padL - padR;
    var slotW = plotW / series.length;
    var barW = Math.max(6, Math.min(20, slotW / 2 - 3));
    var baseY = padTop + chartH;
    var height = padTop + chartH + padBottom + 28;
    var legendA = opts.legendA || '';
    var legendB = opts.legendB || '';
    var labelStep = series.length > 24 ? 3 : (series.length > 12 ? 2 : 1);

    var svg = '<svg width="' + width + '" height="' + height + '" class="chart-svg-clickable">';

    for (var t = 0; t <= 4; t++) {
      var tickVal = max * (t / 4);
      var ty = baseY - (tickVal / max) * chartH;
      svg += '<line x1="' + padL + '" y1="' + ty + '" x2="' + (width - padR) + '" y2="' + ty + '" stroke="#eef2f4" stroke-width="1"/>';
      svg += '<text x="' + (padL - 4) + '" y="' + (ty + 4) + '" font-size="10" fill="#828b95" text-anchor="end">' + escapeXml(formatShortNum(tickVal)) + '</text>';
    }

    series.forEach(function (r, idx) {
      var x = padL + idx * slotW + (slotW - barW * 2 - 2) / 2;
      var h1 = ((Number(r.a) || 0) / max) * chartH;
      var h2 = ((Number(r.b) || 0) / max) * chartH;
      var clickAttr = opts.onClick ? ' data-period-idx="' + idx + '" class="chart-bar-segment"' : '';
      svg += '<rect x="' + x + '" y="' + (baseY - h1) + '" width="' + barW + '" height="' + h1 + '" fill="#00a2e8"' + clickAttr + '/>';
      svg += '<rect x="' + (x + barW + 2) + '" y="' + (baseY - h2) + '" width="' + barW + '" height="' + h2 + '" fill="#9bcb56"' + clickAttr + '/>';
      if (idx % labelStep === 0 || idx === series.length - 1) {
        var lx = padL + idx * slotW + slotW / 2;
        svg += '<text x="' + lx + '" y="' + (baseY + 14) + '" font-size="10" fill="#535c69" text-anchor="middle">' +
          escapeXml(formatPeriodLabel(r.period)) + '</text>';
      }
    });

    svg += '<line x1="' + padL + '" y1="' + baseY + '" x2="' + (width - padR) + '" y2="' + baseY + '" stroke="#dfe3e6" stroke-width="1"/>';
    svg += '</svg>';

    if (legendA || legendB) {
      svg += '<div class="pie-legend"><span class="swatch" style="background:#00a2e8"></span> ' + escapeXml(legendA || '—') +
        ' &nbsp; <span class="swatch" style="background:#9bcb56"></span> ' + escapeXml(legendB || '—') + '</div>';
    }
    el.innerHTML = svg;

    if (opts.onClick) {
      bindChartClick(el, series, opts.onClick, 'data-period-idx');
    }
  }

  global.SimpleCharts = { barChart: barChart, pieChart: pieChart, groupBars: groupBars };
})(window);
