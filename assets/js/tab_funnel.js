/** tab_funnel.js — Воронка и этапы (Общая / 1С / Битрикс) */
window.TabFunnel = {
  subTab: 'bitrix',

  tableHtml: function (headers, rows) {
    var h = '<table class="data-table"><thead><tr>' +
      headers.map(function (x) { return '<th>' + x + '</th>'; }).join('') + '</tr></thead><tbody>';
    rows.forEach(function (row) {
      h += '<tr>' + row.map(function (c) { return '<td>' + c + '</td>'; }).join('') + '</tr>';
    });
    return h + '</tbody></table>';
  },

  fmtPct: function (n) {
    return n == null ? '—' : Number(n).toFixed(1).replace('.', ',') + ' %';
  },

  setStatCards: function (containerId, cards) {
    var el = document.getElementById(containerId);
    if (!el) return;
    el.innerHTML = cards.map(function (c, i) {
      var clickable = typeof c.onClick === 'function';
      var tag = clickable ? 'button' : 'div';
      var cls = 'funnel-stat-card' + (clickable ? ' funnel-stat-card--link' : '');
      var attrs = clickable
        ? ' type="button" class="' + cls + '" data-stat-idx="' + i + '" title="Открыть детализацию"'
        : ' class="' + cls + '"';
      return '<' + tag + attrs + '>' +
        '<div class="funnel-stat-value">' + c.value + '</div>' +
        '<div class="funnel-stat-label">' + c.label + '</div>' +
        '</' + tag + '>';
    }).join('');
    el.querySelectorAll('[data-stat-idx]').forEach(function (btn) {
      var idx = parseInt(btn.getAttribute('data-stat-idx'), 10);
      btn.onclick = function () {
        var card = cards[idx];
        if (!card || typeof card.onClick !== 'function') return;
        card.onClick();
      };
    });
  },

  drillDeals: function (ctx, extra) {
    ctx.goToDetails(Object.assign({ view: 'deals_bitrix', source: 'bitrix' }, extra || {}));
  },

  drillOps: function (ctx, extra) {
    ctx.goToDetails(Object.assign({ view: 'operations_1c', source: '1c' }, extra || {}));
  },

  onStageClick: function (ctx, params) {
    if (!params || !params.name) return;
    this.drillDeals(ctx, { deal_status: params.name });
  },

  renderSubNav: function (root) {
    var self = this;
    var nav = root.querySelector('.funnel-subnav');
    if (!nav) return;
    nav.querySelectorAll('[data-funnel-sub]').forEach(function (btn) {
      btn.classList.toggle('active', btn.getAttribute('data-funnel-sub') === self.subTab);
      btn.onclick = function () {
        self.subTab = btn.getAttribute('data-funnel-sub');
        self.render(root, self._ctx);
      };
    });
  },

  async renderBitrix(root, ctx) {
    var self = this;
    var data = await ctx.api('api/funnel_stages.php', Object.assign({ funnel_source: 'bitrix' }, ctx.filters));
    var panel = root.querySelector('#funnel-panel');
    panel.innerHTML =
      '<div id="fn-stats" class="funnel-stats-row"></div>' +
      '<div class="charts-grid">' +
      '<div class="chart-slot chart-slot-wide"><h4>Классическая воронка</h4><p class="tab-note tab-note-compact">Клик по этапу — детализация сделок</p><div id="fn-funnel" class="echart-box"></div></div>' +
      '<div class="chart-slot chart-slot-wide"><h4>Сделки по стадиям (SLA)</h4><p class="tab-note tab-note-compact">Красный — средний возраст выше SLA</p><div id="fn-bars" class="echart-box"></div></div>' +
      '</div>' +
      '<div class="table-slot"><h4>Конверсия между этапами (оценка)</h4><div id="fn-conv"></div></div>' +
      '<div class="table-slot"><h4>Зависшие сделки (топ-10)</h4><div id="fn-stuck"></div></div>';

    self.setStatCards('fn-stats', [
      { label: 'Всего сделок', value: data.stats.total, onClick: function () { self.drillDeals(ctx); } },
      { label: 'В процессе', value: data.stats.in_progress, onClick: function () { self.drillDeals(ctx, { deal_result: 'В процессе' }); } },
    ]);

    EChartsHelper.funnelChart('fn-funnel', data.funnel || [], {
      onClick: function (params) { self.onStageClick(ctx, params); },
    });
    EChartsHelper.barChart('fn-bars', data.stage_bars || [], {
      onClick: function (params) { self.onStageClick(ctx, params); },
    });

    document.getElementById('fn-conv').innerHTML = self.tableHtml(
      ['Из', 'В', 'Конверсия', 'Дошло'],
      (data.conversion || []).map(function (r) {
        return [r.from, r.to, self.fmtPct(r.rate_pct), r.to_count + ' / ' + r.from_count];
      })
    );

    document.getElementById('fn-stuck').innerHTML = (data.stuck || []).length
      ? self.tableHtml(
        ['№', 'Клиент', 'Ответственный', 'Стадия', 'Сумма', 'Дней', 'SLA'],
        (data.stuck || []).map(function (r) {
          return [
            r.deal_no, r.client, r.agent_display, r.deal_status,
            Math.round(r.sales_amount).toLocaleString('ru-RU'),
            r.age_days, r.sla_days + (r.over_sla ? ' ⚠' : ''),
          ];
        })
      )
      : '<p class="tab-note">Нет зависших сделок по порогу из настроек.</p>';
  },

  async renderUnified(root, ctx) {
    var self = this;
    var data = await ctx.api('api/funnel_stages.php', Object.assign({ funnel_source: 'unified' }, ctx.filters));
    var unified = await ctx.api('api/funnel_unified.php', Object.assign({ granularity: 'month' }, ctx.filters));
    var panel = root.querySelector('#funnel-panel');
    panel.innerHTML =
      '<div id="fn-stats" class="funnel-stats-row"></div>' +
      '<p class="tab-note">' + (data.note || '') + '</p>' +
      '<div class="charts-grid">' +
      '<div class="chart-slot chart-slot-wide"><h4>CRM-воронка (Битрикс)</h4><div id="fn-funnel" class="echart-box"></div></div>' +
      '<div class="chart-slot"><h4>1С vs Битрикс</h4><div id="fn-unified-bar"></div></div>' +
      '</div>' +
      '<div class="table-slot"><h4>Зависшие сделки CRM</h4><div id="fn-stuck"></div></div>';

    self.setStatCards('fn-stats', [
      { label: 'Сделок Б24', value: data.stats.deals_created, onClick: function () { self.drillDeals(ctx); } },
      { label: 'Операций 1С', value: data.stats.operations_1c, onClick: function () { self.drillOps(ctx); } },
    ]);

    EChartsHelper.funnelChart('fn-funnel', data.funnel || [], {
      onClick: function (params) { self.onStageClick(ctx, params); },
    });
    SimpleCharts.barChart('fn-unified-bar', [
      { label: '1С операции', value: (unified.stats && unified.stats.ops_total) || 0 },
      { label: 'Б24 сделки', value: (unified.stats && unified.stats.deals_created) || 0 },
    ], {
      onClick: function (item) {
        if (item.label.indexOf('1С') >= 0) self.drillOps(ctx);
        else self.drillDeals(ctx);
      },
    });

    document.getElementById('fn-stuck').innerHTML = (data.stuck || []).length
      ? self.tableHtml(
        ['№', 'Клиент', 'Ответственный', 'Сумма', 'Дней'],
        (data.stuck || []).map(function (r) {
          return [r.deal_no, r.client, r.agent_display, Math.round(r.sales_amount).toLocaleString('ru-RU'), r.age_days];
        })
      )
      : '<p class="tab-note">Нет зависших сделок.</p>';
  },

  async render1c(root, ctx) {
    var self = this;
    var data = await ctx.api('api/funnel_stages.php', Object.assign({ funnel_source: '1c' }, ctx.filters));
    var f1c = await ctx.api('api/funnel_1c.php', ctx.filters);
    var panel = root.querySelector('#funnel-panel');
    panel.innerHTML =
      '<p class="tab-note">' + (data.note || '') + '</p>' +
      '<div id="fn-stats" class="funnel-stats-row"></div>' +
      '<div class="charts-grid">' +
      '<div class="chart-slot"><h4>Продажи vs возвраты</h4><div id="fn-1c-pie"></div></div>' +
      '<div class="chart-slot chart-slot-wide"><h4>Подразделения</h4><div id="fn-1c-dept" class="echart-box"></div></div>' +
      '</div>';

    var s = data.stats;
    self.setStatCards('fn-stats', [
      { label: 'Операций', value: s.operations, onClick: function () { self.drillOps(ctx); } },
      { label: 'Продажи', value: s.sales_ops, onClick: function () { self.drillOps(ctx, { operation_type: 'sales' }); } },
      { label: 'Возвраты', value: s.refund_ops, onClick: function () { self.drillOps(ctx, { operation_type: 'refund' }); } },
      { label: '% возвратов', value: self.fmtPct(s.refund_pct), onClick: function () { self.drillOps(ctx, { operation_type: 'refund' }); } },
    ]);

    SimpleCharts.pieChart('fn-1c-pie', Object.keys(f1c.operation_types || {}).map(function (k) {
      return { label: k, value: f1c.operation_types[k] };
    }), {
      onClick: function (item) {
        self.drillOps(ctx, { operation_type: item.label === 'Возвраты' ? 'refund' : 'sales' });
      },
    });
    EChartsHelper.barChart('fn-1c-dept', (data.departments || []).map(function (d) {
      return { stage: d.stage, count: d.count, color: '#00a2e8' };
    }), {
      onClick: function (params) {
        if (params && params.name) self.drillOps(ctx, { department: params.name });
      },
    });
  },

  async render(root, ctx) {
    var self = this;
    self._ctx = ctx;
    root.innerHTML =
      '<h2>Воронка и этапы</h2>' +
      '<div class="funnel-subnav subnav-tabs">' +
      '<button type="button" class="subnav-tab" data-funnel-sub="unified">Общая</button>' +
      '<button type="button" class="subnav-tab" data-funnel-sub="1c">1С</button>' +
      '<button type="button" class="subnav-tab active" data-funnel-sub="bitrix">Битрикс24</button>' +
      '</div>' +
      '<div id="funnel-panel"></div>';
    self.renderSubNav(root);
    if (self.subTab === 'unified') await self.renderUnified(root, ctx);
    else if (self.subTab === '1c') await self.render1c(root, ctx);
    else await self.renderBitrix(root, ctx);
  },
};
