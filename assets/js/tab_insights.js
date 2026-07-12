/** tab_insights.js — советы для руководителя */
window.TabInsights = {
  levelLabel: { danger: 'Внимание', warning: 'Контроль', info: 'Инфо', ok: 'Норма' },

  fmtRub: function (n) {
    return Math.round(Number(n) || 0).toLocaleString('ru-RU') + ' ₽';
  },

  fmtPct: function (n) {
    if (n == null || n === '') return '—';
    return Number(n).toFixed(1).replace('.', ',') + ' %';
  },

  async render(root, ctx) {
    var self = this;
    root.innerHTML =
      '<h2>Советы руководителю</h2>' +
      '<p class="tab-note">Сигналы по выбранным фильтрам и периоду. Клик «Детализация» — переход с фильтром.</p>' +
      '<div id="ins-summary" class="insights-summary"></div>' +
      '<div id="ins-priorities" class="insights-block"></div>' +
      '<div id="ins-signals" class="insights-block"></div>' +
      '<div id="ins-agents" class="insights-block"></div>' +
      '<div id="ins-teams" class="insights-block"></div>' +
      '<div id="ins-checklist" class="insights-block"></div>';

    var data = await ctx.api('api/insights.php', ctx.filters);
    var s = data.summary || {};
    var counts = data.counts || {};

    document.getElementById('ins-summary').innerHTML =
      '<div class="insights-counters">' +
      (counts.danger ? '<span class="ins-badge ins-danger">' + counts.danger + ' внимание</span>' : '') +
      (counts.warning ? '<span class="ins-badge ins-warning">' + counts.warning + ' контроль</span>' : '') +
      (counts.info ? '<span class="ins-badge ins-info">' + counts.info + ' инфо</span>' : '') +
      '</div>' +
      '<div class="insights-kpi-row">' +
      '<span>Прибыль: <strong>' + self.fmtRub(s.profit) + '</strong></span>' +
      '<span>Маржа: <strong>' + self.fmtPct(s.margin_pct) + '</strong></span>' +
      '<span>Конверсия Б24: <strong>' + self.fmtPct(s.conversion_pct) + '</strong></span>' +
      '<span>Возвраты 1С: <strong>' + self.fmtPct(s.refund_pct) + '</strong></span>' +
      (s.prev_period ? '<span class="ins-muted">Пред. период: ' + s.prev_period + '</span>' : '') +
      '</div>';

    var pri = data.priorities || [];
    document.getElementById('ins-priorities').innerHTML =
      '<h3 class="chart-section-title">Главное на период</h3>' +
      (pri.length
        ? '<ul class="insights-list">' + pri.map(function (p) { return '<li>' + escapeHtml(p) + '</li>'; }).join('') + '</ul>'
        : '<p class="tab-note">Явных приоритетов нет — используйте чек-лист ниже.</p>');

    document.getElementById('ins-signals').innerHTML =
      '<h3 class="chart-section-title">Сигналы</h3>' +
      '<div class="insights-cards">' +
      (data.signals || []).map(function (sig) {
        return self.signalCard(sig, ctx);
      }).join('') +
      '</div>';

    var cards = data.agent_cards || [];
    document.getElementById('ins-agents').innerHTML =
      '<h3 class="chart-section-title">Агенты: с кем поговорить</h3>' +
      (cards.length
        ? '<div class="insights-cards">' + cards.map(function (c) { return self.agentCard(c, ctx); }).join('') + '</div>'
        : '<p class="tab-note">Нет агентов с отклонениями по правилам (или мало данных).</p>');

    document.getElementById('ins-teams').innerHTML =
      '<h3 class="chart-section-title">Команды: сравнение</h3>' +
      tableHtml(
        ['Команда', 'Продажи', 'Прибыль', 'Маржа', 'Возвраты', ''],
        (data.teams || []).map(function (t) {
          return [
            t.label,
            self.fmtRub(t.sales),
            self.fmtRub(t.profit),
            self.fmtPct(t.margin_pct),
            self.fmtPct(t.refund_pct),
            t.drill ? '<button type="button" class="btn-sm btn-ins-drill" data-drill=\'' + escapeAttr(JSON.stringify(t.drill)) + '\'>Детализация</button>' : '',
          ];
        })
      );

    document.getElementById('ins-checklist').innerHTML =
      '<h3 class="chart-section-title">Чек-лист руководителя</h3>' +
      '<ul class="insights-checklist">' +
      (data.checklist || []).map(function (item) {
        return '<li><strong>' + escapeHtml(item.title) + '</strong> — ' + escapeHtml(item.text) + '</li>';
      }).join('') +
      '</ul>';

    root.querySelectorAll('.btn-ins-drill').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var drill = JSON.parse(btn.getAttribute('data-drill') || '{}');
        ctx.goToDetails(drill);
      });
    });
    root.querySelectorAll('.btn-ins-tab').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var tab = btn.getAttribute('data-tab');
        if (tab && ctx.goToTab) ctx.goToTab(tab);
      });
    });
  },

  signalCard: function (sig, ctx) {
    var btns = '';
    if (sig.drill) {
      btns += '<button type="button" class="btn-sm btn-ins-drill" data-drill=\'' + escapeAttr(JSON.stringify(sig.drill)) + '\'>Детализация</button>';
    }
    if (sig.tab) {
      btns += ' <button type="button" class="btn-sm btn-ins-tab" data-tab="' + escapeHtml(sig.tab) + '">Открыть вкладку</button>';
    }
    return '<div class="ins-card ins-' + sig.level + '">' +
      '<div class="ins-card-head"><span class="ins-badge ins-' + sig.level + '">' + (this.levelLabel[sig.level] || sig.level) + '</span>' +
      '<strong>' + escapeHtml(sig.title) + '</strong></div>' +
      '<p class="ins-card-detail">' + escapeHtml(sig.detail) + '</p>' +
      (btns ? '<div class="ins-card-actions">' + btns + '</div>' : '') +
      '</div>';
  },

  agentCard: function (c, ctx) {
    var notes = (c.notes || []).map(function (n) { return '<li>' + escapeHtml(n) + '</li>'; }).join('');
    return '<div class="ins-card ins-' + c.level + '">' +
      '<div class="ins-card-head"><strong>' + escapeHtml(c.agent_display) + '</strong>' +
      '<span class="ins-muted">' + escapeHtml(c.agent_team) + '</span></div>' +
      '<p class="ins-card-metrics">Прибыль ' + this.fmtRub(c.profit) + ' · Маржа ' + this.fmtPct(c.margin_pct) + '</p>' +
      '<ul class="insights-list ins-notes">' + notes + '</ul>' +
      '<div class="ins-card-actions"><button type="button" class="btn-sm btn-ins-drill" data-drill=\'' +
      escapeAttr(JSON.stringify(c.drill)) + '\'>Детализация</button></div></div>';
  },
};

function tableHtml(headers, rows) {
  var h = '<table class="data-table"><thead><tr>' + headers.map(function (x) { return '<th>' + x + '</th>'; }).join('') + '</tr></thead><tbody>';
  rows.forEach(function (row) {
    h += '<tr>' + row.map(function (c) { return '<td>' + c + '</td>'; }).join('') + '</tr>';
  });
  return h + '</tbody></table>';
}

function escapeHtml(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function escapeAttr(s) {
  return String(s).replace(/'/g, '&#39;');
}
