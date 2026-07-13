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

  function formatRubFull(n) {
    return Math.round(Number(n) || 0).toLocaleString('ru-RU') + ' ₽';
  }

  function formatBarValue(val, opts, item) {
    opts = opts || {};
    item = item || {};
    var base;
    if (opts.valueFormat === 'rub') {
      base = Math.round(Number(val) || 0).toLocaleString('ru-RU') + ' ₽';
    } else {
      base = String(val);
    }
    if (opts.showCount && item.count != null) {
      var n = Math.round(Number(item.count) || 0).toLocaleString('ru-RU');
      var unit = opts.countLabel ? ' ' + opts.countLabel : '';
      base += ' · ' + n + unit;
    }
    return base;
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
    var color = opts.color || '#00a2e8';
    var html = '<div class="bar-chart-html">';
    items.forEach(function (item, idx) {
      var pct = Math.max(0, ((Number(item.value) || 0) / max) * 100);
      var fullLabel = String(item.label || '');
      var valueText = formatBarValue(item.value, opts, item);
      var tip = fullLabel + ' — ' + valueText;
      var rowCls = 'bar-chart-row' + (opts.onClick ? ' bar-chart-row-clickable' : '');
      html += '<div class="' + rowCls + '" data-bar-idx="' + idx + '" title="' + escapeXml(tip) + '">' +
        '<span class="bar-chart-label" title="' + escapeXml(fullLabel) + '">' + escapeXml(fullLabel) + '</span>' +
        '<div class="bar-chart-track"><div class="bar-chart-fill" style="width:' + pct + '%;background:' + color + '"></div></div>' +
        '<span class="bar-chart-value">' + escapeXml(valueText) + '</span>' +
        '</div>';
    });
    html += '</div>';
    el.innerHTML = html;
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
    var colors = ['#00a2e8', '#9bcb56', '#ff9a00', '#2067b0', '#ff5757', '#535c69', '#828b95', '#47d1dc'];
    var cx = 120, cy = 120, rOuter = 94, rInner = 54;
    var angle = -Math.PI / 2;
    var paths = '';
    var legend = '<div class="pie-legend pie-legend-compact pie-legend-modern">';
    items.forEach(function (item, idx) {
      var val = Number(item.value) || 0;
      if (val <= 0) return;
      var frac = val / total;
      var next = angle + frac * Math.PI * 2;
      var x1o = cx + rOuter * Math.cos(angle);
      var y1o = cy + rOuter * Math.sin(angle);
      var x2o = cx + rOuter * Math.cos(next);
      var y2o = cy + rOuter * Math.sin(next);
      var x1i = cx + rInner * Math.cos(next);
      var y1i = cy + rInner * Math.sin(next);
      var x2i = cx + rInner * Math.cos(angle);
      var y2i = cy + rInner * Math.sin(angle);
      var large = frac > 0.5 ? 1 : 0;
      var color = colors[idx % colors.length];
      var cls = opts.onClick ? ' class="chart-pie-segment"' : '';
      var legLabel = item.legendLabel || item.label || '—';
      var tipText = legLabel + ' — ' + formatPieValue(val, total, fmt);
      paths += '<path d="M' + x1o + ',' + y1o +
        ' A' + rOuter + ',' + rOuter + ' 0 ' + large + ' 1 ' + x2o + ',' + y2o +
        ' L' + x1i + ',' + y1i +
        ' A' + rInner + ',' + rInner + ' 0 ' + large + ' 0 ' + x2i + ',' + y2i +
        ' Z" fill="' + color + '" stroke="#fff" stroke-width="2" data-pie-idx="' + idx + '"' + cls + '>' +
        '<title>' + escapeXml(tipText) + '</title></path>';
      var legCls = opts.onClick ? ' class="pie-legend-item"' : '';
      var pctStr = (frac * 100).toFixed(1).replace('.', ',') + ' %';
      legend += '<div' + legCls + ' data-pie-idx="' + idx + '" title="' + escapeXml(tipText) + '">' +
        '<span class="swatch" style="background:' + color + '"></span>' +
        '<span class="pie-legend-text">' + escapeXml(legLabel) + '</span>' +
        '<span class="pie-legend-meta">' + formatPieValue(val, total, fmt) + ' · ' + pctStr + '</span></div>';
      angle = next;
    });
    var centerVal = fmt === 'count'
      ? Math.round(total).toLocaleString('ru-RU')
      : Math.round(total).toLocaleString('ru-RU') + ' ₽';
    var shadowId = 'pie-shadow-' + String(containerId).replace(/[^a-z0-9_-]/gi, '');
    legend += '</div>';
    el.innerHTML =
      '<div class="pie-wrap chart-clickable pie-wrap-modern">' +
      '<svg width="240" height="240" viewBox="0 0 240 240" class="pie-svg-modern">' +
      '<defs><filter id="' + shadowId + '" x="-20%" y="-20%" width="140%" height="140%">' +
      '<feDropShadow dx="0" dy="1" stdDeviation="2" flood-opacity="0.12"/></filter></defs>' +
      '<g filter="url(#' + shadowId + ')">' + paths + '</g>' +
      '<text x="' + cx + '" y="' + (cy - 6) + '" text-anchor="middle" font-size="10" fill="#828b95">Всего</text>' +
      '<text x="' + cx + '" y="' + (cy + 12) + '" text-anchor="middle" font-size="12" font-weight="600" fill="#333">' +
      escapeXml(centerVal) + '</text></svg>' + legend + '</div>';
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
    if (opts.horizontal) {
      groupBarsHorizontal(el, series, opts, max);
      return;
    }
    var width = el.clientWidth || 560;
    var padL = 48;
    var padR = 12;
    var padTop = 8;
    var chartH = 160;
    var padBottom = 36;
    var plotW = width - padL - padR;
    var slotW = plotW / series.length;
    var legendA = opts.legendA || '';
    var legendB = opts.legendB || '';
    var dual = !!legendB;
    var barW = Math.max(6, Math.min(28, dual ? slotW / 2 - 3 : slotW * 0.55));
    var baseY = padTop + chartH;
    var height = padTop + chartH + padBottom + 28;
    var labelStep = series.length > 24 ? 3 : (series.length > 12 ? 2 : 1);

    var svg = '<svg width="' + width + '" height="' + height + '" class="chart-svg-clickable">';

    for (var t = 0; t <= 4; t++) {
      var tickVal = max * (t / 4);
      var ty = baseY - (tickVal / max) * chartH;
      svg += '<line x1="' + padL + '" y1="' + ty + '" x2="' + (width - padR) + '" y2="' + ty + '" stroke="#eef2f4" stroke-width="1"/>';
      svg += '<text x="' + (padL - 4) + '" y="' + (ty + 4) + '" font-size="10" fill="#828b95" text-anchor="end">' + escapeXml(formatShortNum(tickVal)) + '</text>';
    }

    series.forEach(function (r, idx) {
      var x = padL + idx * slotW + (dual ? (slotW - barW * 2 - 2) / 2 : (slotW - barW) / 2);
      var h1 = ((Number(r.a) || 0) / max) * chartH;
      var h2 = ((Number(r.b) || 0) / max) * chartH;
      var clickAttr = opts.onClick ? ' data-period-idx="' + idx + '" class="chart-bar-segment"' : '';
      var tipA;
      var tipB;
      if (opts.valueFormat === 'count') {
        tipA = (legendA || 'Создано') + ': ' + Math.round(Number(r.a) || 0).toLocaleString('ru-RU');
        tipB = (legendB || '—') + ': ' + Math.round(Number(r.b) || 0).toLocaleString('ru-RU');
      } else {
        tipA = (legendA || 'Продажи') + ': ' + formatRubFull(r.a);
        tipB = (legendB || 'Прибыль') + ': ' + formatRubFull(r.b);
      }
      svg += '<rect x="' + x + '" y="' + (baseY - h1) + '" width="' + barW + '" height="' + Math.max(h1, 0) + '" fill="#00a2e8"' + clickAttr + '>' +
        '<title>' + escapeXml(tipA) + '</title></rect>';
      if (dual) {
        svg += '<rect x="' + (x + barW + 2) + '" y="' + (baseY - h2) + '" width="' + barW + '" height="' + Math.max(h2, 0) + '" fill="#9bcb56"' + clickAttr + '>' +
          '<title>' + escapeXml(tipB) + '</title></rect>';
      }
      if (idx % labelStep === 0 || idx === series.length - 1) {
        var lx = padL + idx * slotW + slotW / 2;
        svg += '<text x="' + lx + '" y="' + (baseY + 14) + '" font-size="10" fill="#535c69" text-anchor="middle">' +
          escapeXml(formatPeriodLabel(r.period)) + '</text>';
      }
    });

    svg += '<line x1="' + padL + '" y1="' + baseY + '" x2="' + (width - padR) + '" y2="' + baseY + '" stroke="#dfe3e6" stroke-width="1"/>';
    svg += '</svg>';

    if (legendA || legendB) {
      svg += '<div class="pie-legend"><span class="swatch" style="background:#00a2e8"></span> ' + escapeXml(legendA || '—');
      if (legendB) {
        svg += ' &nbsp; <span class="swatch" style="background:#9bcb56"></span> ' + escapeXml(legendB);
      }
      svg += '</div>';
    }
    el.innerHTML = svg;

    if (opts.onClick) {
      bindChartClick(el, series, opts.onClick, 'data-period-idx');
    }
  }

  function groupBarsHorizontal(el, series, opts, max) {
    var width = el.clientWidth || 560;
    var padL = 52;
    var padR = 12;
    var padTop = 20;
    var padBottom = 8;
    var rowH = 34;
    var barH = 13;
    var plotW = width - padL - padR;
    var height = padTop + series.length * rowH + padBottom;
    var legendA = opts.legendA || '';
    var legendB = opts.legendB || '';
    var svgCls = opts.onClick ? 'chart-svg-clickable' : 'chart-svg';

    var svg = '<svg width="' + width + '" height="' + height + '" class="' + svgCls + '">';

    for (var t = 0; t <= 4; t++) {
      var tickVal = max * (t / 4);
      var tx = padL + (tickVal / max) * plotW;
      svg += '<line x1="' + tx + '" y1="' + padTop + '" x2="' + tx + '" y2="' + (height - padBottom) + '" stroke="#eef2f4" stroke-width="1"/>';
      svg += '<text x="' + tx + '" y="' + (padTop - 6) + '" font-size="10" fill="#828b95" text-anchor="middle">' +
        escapeXml(formatShortNum(tickVal)) + '</text>';
    }

    series.forEach(function (r, idx) {
      var y = padTop + idx * rowH;
      var w1 = Math.max(0, ((Number(r.a) || 0) / max) * plotW);
      var w2 = Math.max(0, ((Number(r.b) || 0) / max) * plotW);
      var clickAttr = opts.onClick ? ' data-period-idx="' + idx + '" class="chart-bar-segment"' : '';
      var tipA = (legendA || 'Продажи') + ': ' + formatRubFull(r.a);
      var tipB = (legendB || 'Прибыль') + ': ' + formatRubFull(r.b);
      svg += '<text x="4" y="' + (y + barH + 3) + '" font-size="10" fill="#535c69">' +
        escapeXml(formatPeriodLabel(r.period)) + '</text>';
      svg += '<rect x="' + padL + '" y="' + y + '" width="' + Math.max(w1, w1 > 0 ? 2 : 0) + '" height="' + barH + '" fill="#00a2e8" rx="1"' + clickAttr + '>' +
        '<title>' + escapeXml(tipA) + '</title></rect>';
      svg += '<rect x="' + padL + '" y="' + (y + barH + 2) + '" width="' + Math.max(w2, w2 > 0 ? 2 : 0) + '" height="' + barH + '" fill="#9bcb56" rx="1"' + clickAttr + '>' +
        '<title>' + escapeXml(tipB) + '</title></rect>';
    });

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
