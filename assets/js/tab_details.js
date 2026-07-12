/** tab_details.js */
window.TabDetails = {
  VIEW_HEADERS: {
    sales: ['Дата', 'Источник', 'Агент', 'Команда', 'Клиент', 'Категория', 'Канал', 'Продажи', 'Прибыль', 'ID'],
    deals_bitrix: ['Дата создания', '№ сделки', 'Статус', 'Результат', 'Клиент', 'Ответственный', 'Сумма', 'Причина проигрыша'],
    operations_1c: ['Дата', 'Агент', 'Команда', 'Подразделение 1С', 'Категория', 'Клиент', 'Сумма', '№ заказа'],
  },

  rowToCells: function (view, r) {
    if (view === 'deals_bitrix') {
      return [r.date, r.deal_no, r.deal_status, r.deal_result, r.client, r.responsible_person, r.sales_amount, r.lost_deal_reason];
    }
    if (view === 'operations_1c') {
      return [r.date, r.agent, r.agent_team, r.department, r.category, r.client, r.sales_amount, r.order_no];
    }
    return [r.date, r.source, r.agent_display, r.agent_team, r.client || '', r.category || '', r.channel || '', r.sales_amount, r.profit_ex_vat, r.raw_id || ''];
  },

  async render(root, ctx) {
    var self = this;
    root.innerHTML =
      '<h2>Детализация</h2>' +
      '<div id="dt-drill" class="details-drill-bar"></div>' +
      '<div id="dt-meta" class="load-diagnostics"></div>' +
      '<div id="dt-table"></div>';

    var data = await ctx.api('api/details.php', ctx.filters);
    var view = data.view || 'sales';

    var drillHtml = '';
    if (data.drill && data.drill.length) {
      drillHtml = '<div class="details-drill-chips">' +
        data.drill.map(function (d) {
          return '<span class="details-drill-chip">' + d.label + ': ' + d.value + '</span>';
        }).join('') +
        '<button type="button" class="btn-secondary btn-sm" id="btn-clear-drill">Сбросить фильтр воронки</button></div>';
      if (view === 'operations_1c') {
        drillHtml += '<p class="tab-note tab-note-compact">Подразделение 1С — из выгрузки Excel. Команда — из настроек справочника.</p>';
      }
    }
    document.getElementById('dt-drill').innerHTML = drillHtml;
    var clearBtn = document.getElementById('btn-clear-drill');
    if (clearBtn) {
      clearBtn.onclick = function () { ctx.clearDrill(); };
    }

    var viewLabel = view === 'deals_bitrix' ? 'сделки Битрикс' : (view === 'operations_1c' ? 'операции 1С' : 'продажи');
    document.getElementById('dt-meta').innerHTML =
      '<p class="tab-note tab-note-compact">Показано ' + data.rows.length + ' из ' + data.total + ' (' + viewLabel + ')</p>';

    var headers = this.VIEW_HEADERS[view] || this.VIEW_HEADERS.sales;
    document.getElementById('dt-table').innerHTML = tableHtml(
      headers,
      (data.rows || []).slice(0, 200).map(function (r) {
        return self.rowToCells(view, r);
      })
    );
  },
};
