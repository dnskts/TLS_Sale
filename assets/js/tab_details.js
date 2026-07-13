/** tab_details.js */
window.TabDetails = {
  BITRIX_DEAL_URL: 'https://crm.rstls.ru/crm/deal/details/',

  VIEW_HEADERS: {
    sales: ['Дата', 'Источник', 'Агент', 'Команда', 'Клиент', 'Категория', 'Канал', 'Продажи', 'Прибыль', '№ сделки / ID'],
    deals_bitrix: ['Дата создания', '№ сделки', 'Статус', 'Результат', 'Клиент', 'Ответственный', 'Сумма', 'Причина проигрыша'],
    operations_1c: ['Дата', 'Агент', 'Команда', 'Подразделение 1С', 'Категория', 'Клиент', 'Сумма', '№ заказа'],
  },

  /** Chips only for keys from active drill (ctx.drill), not panel date/source. */
  activeDrillLabels: function (drill, serverDrill) {
    if (!drill || typeof drill !== 'object') return [];
    var keys = {};
    Object.keys(drill).forEach(function (k) {
      if (k === 'view') return;
      keys[k] = true;
    });
    var pluralToSingular = {
      categories: 'category',
      channels: 'channel',
      client_types: 'client_type',
      card_types: 'card_type',
      clients: 'client',
      partners: 'partner',
      request_types: 'request_type',
      teams: 'team',
    };
    Object.keys(pluralToSingular).forEach(function (plural) {
      if (keys[plural]) keys[pluralToSingular[plural]] = true;
    });
    Object.keys(pluralToSingular).forEach(function (plural) {
      var singular = pluralToSingular[plural];
      if (keys[singular]) keys[plural] = true;
    });
    return (serverDrill || []).filter(function (d) {
      return !!keys[d.key];
    });
  },

  dealNoLink: function (dealNo) {
    var id = String(dealNo == null ? '' : dealNo).trim();
    if (!/^\d+$/.test(id)) {
      return id || '—';
    }
    var url = this.BITRIX_DEAL_URL + id + '/?showFinCard=y';
    return '<a href="' + url + '" target="_blank" rel="noopener noreferrer" class="crm-deal-link">' + id + '</a>';
  },

  rowToCells: function (view, r) {
    if (view === 'deals_bitrix') {
      return [
        r.date,
        this.dealNoLink(r.deal_no),
        r.deal_status,
        r.deal_result,
        r.client,
        r.responsible_person,
        r.sales_amount,
        r.lost_deal_reason,
      ];
    }
    if (view === 'operations_1c') {
      return [r.date, r.agent, r.agent_team, r.department, r.category, r.client, r.sales_amount, r.order_no];
    }
    var idCell = r.raw_id || '';
    // Битрикс raw_id = № сделки → ссылка в CRM; 1С — номер заказа без ссылки.
    if (r.source === 'Битрикс') {
      idCell = this.dealNoLink(r.raw_id);
    }
    return [r.date, r.source, r.agent_display, r.agent_team, r.client || '', r.category || '', r.channel || '', r.sales_amount, r.profit_ex_vat, idCell];
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
    var tableRows = data.rows || [];
    var activeDrill = self.activeDrillLabels(ctx.drill, data.drill);

    var drillHtml = '';
    if (activeDrill.length) {
      drillHtml = '<div class="details-drill-chips">' +
        activeDrill.map(function (d) {
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
    var metaNote = tableRows.length < data.total
      ? ('Показано ' + tableRows.length + ' из ' + data.total + ' (' + viewLabel + '; лимит API 2000)')
      : ('Строк: ' + data.total + ' (' + viewLabel + ')');
    if (view === 'sales' && data.sales_breakdown) {
      metaNote +=
        ' · 1С: ' + (data.sales_breakdown['1c'] || 0) +
        ' + Битрикс (успех/возврат): ' + (data.sales_breakdown.bitrix || 0);
    }
    document.getElementById('dt-meta').innerHTML =
      '<p class="tab-note tab-note-compact">' + metaNote + '</p>';

    var headers = this.VIEW_HEADERS[view] || this.VIEW_HEADERS.sales;
    document.getElementById('dt-table').innerHTML = detailsTableHtml(
      headers,
      tableRows.map(function (r) {
        return self.rowToCells(view, r);
      })
    );
  },
};

function detailsTableHtml(headers, rows) {
  var h = '<table class="data-table"><thead><tr>' +
    headers.map(function (x) { return '<th>' + x + '</th>'; }).join('') + '</tr></thead><tbody>';
  rows.forEach(function (row) {
    h += '<tr>' + row.map(function (c) { return '<td>' + c + '</td>'; }).join('') + '</tr>';
  });
  return h + '</tbody></table>';
}
