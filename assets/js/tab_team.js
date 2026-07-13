/** tab_team.js — Эффективность команды */
window.TabTeam = {
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

  async render(root, ctx) {
    var self = this;
    root.innerHTML =
      '<h2>Эффективность команды</h2>' +
      '<p class="tab-note">План задаётся в Настройках → Аналитика. Период плана: ' +
      '<span id="tm-plan-period">—</span></p>' +
      '<div class="charts-grid">' +
      '<div class="chart-slot chart-slot-wide"><h4>Leaderboard: выполнение плана</h4><div id="tm-leader" class="echart-box echart-box-tall"></div></div>' +
      '<div class="chart-slot chart-slot-wide"><h4>Активность × результат</h4><p class="tab-note tab-note-compact" id="tm-act-note"></p><div id="tm-scatter" class="echart-box echart-box-tall"></div></div>' +
      '</div>' +
      '<div class="table-slot"><h4>Менеджеры</h4><div id="tm-agents"></div></div>' +
      '<div class="table-slot"><h4>Коучинг</h4><div id="tm-coaching"></div></div>';

    var data = await ctx.api('api/team.php', ctx.filters);
    document.getElementById('tm-plan-period').textContent = (data.plan && data.plan.period) || '—';
    document.getElementById('tm-act-note').textContent = data.activity_note || '';

    var lb = (data.leaderboard || []).filter(function (r) { return r.value > 0 || r.amount > 0; });
    if (lb.length) {
      EChartsHelper.hBarChart('tm-leader', lb.slice(0, 15).map(function (r) {
        return { label: r.label, value: r.value || 0, agent_key: r.agent_key };
      }), {
        color: '#00a2e8',
        onClick: function (params) {
          var row = lb.find(function (r) { return r.label === params.name; });
          if (row && row.agent_key) ctx.goToDetails({ view: 'sales', agents: [row.agent_key] });
        },
      });
    } else {
      document.getElementById('tm-leader').innerHTML = '<p class="chart-empty">Задайте планы в настройках или выберите период с продажами</p>';
    }

    EChartsHelper.scatterChart('tm-scatter', data.scatter || [], {
      onClick: function (params) {
        var name = params.data[2];
        var agent = (data.agents || []).find(function (a) { return a.agent_display === name; });
        if (agent) ctx.goToDetails({ view: 'sales', agents: [agent.agent_key] });
      },
    });

    document.getElementById('tm-agents').innerHTML = self.tableHtml(
      ['Агент', 'Команда', 'Закрыто', 'Сделок', 'Win rate', 'План %', 'Активность %'],
      (data.agents || []).slice(0, 50).map(function (a) {
        return [
          a.agent_display, a.agent_team, self.fmtRub(a.closed_amount), a.closed_count,
          self.fmtPct(a.win_rate_pct), self.fmtPct(a.plan_pct), a.activity_score + '%',
        ];
      })
    );

    var cards = data.coaching || [];
    document.getElementById('tm-coaching').innerHTML = cards.length
      ? '<div class="insights-cards">' + cards.map(function (c) {
        return '<div class="insight-card ins-' + c.level + '"><strong>' + escapeHtml(c.agent_display) + '</strong>' +
          '<ul class="insights-list">' + (c.notes || []).map(function (n) { return '<li>' + escapeHtml(n) + '</li>'; }).join('') + '</ul></div>';
      }).join('') + '</div>'
      : '<p class="tab-note">Нет карточек коучинга по текущим правилам.</p>';
  },
};

function escapeHtml(s) {
  return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
