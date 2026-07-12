/** tab_agents.js */
window.TabAgents = {
  async render(root, ctx) {
    root.innerHTML = '<h2>Агенты и команды</h2><div id="ag-rank" class="chart-slot"></div><div class="tables-grid"><div class="table-slot"><h4>Команды</h4><div id="ag-teams"></div></div><div class="table-slot"><h4>Агенты</h4><div id="ag-agents"></div></div></div>';
    var data = await ctx.api('api/agents.php', ctx.filters);
    SimpleCharts.barChart('ag-rank', (data.agents || []).slice(0, 15).map(function (r) {
      return { label: r.agent_display, value: Math.round(r.profit_ex_vat) };
    }));
    document.getElementById('ag-teams').innerHTML = tableHtml(
      ['Команда', 'Продажи', 'Прибыль', 'Строк'],
      (data.teams || []).map(function (r) {
        return [r.agent_team, Math.round(r.sales_amount), Math.round(r.profit_ex_vat), r.count];
      })
    );
    document.getElementById('ag-agents').innerHTML = tableHtml(
      ['Агент', 'Команда', '1С', 'Битрикс', 'Итого', 'Прибыль'],
      (data.agents || []).slice(0, 50).map(function (r) {
        return [r.agent_display, r.agent_team, Math.round(r.sales_1c), Math.round(r.sales_bitrix), Math.round(r.sales_total), Math.round(r.profit_ex_vat)];
      })
    );
  },
};

function tableHtml(headers, rows) {
  var h = '<table class="data-table"><thead><tr>' + headers.map(function (x) { return '<th>' + x + '</th>'; }).join('') + '</tr></thead><tbody>';
  rows.forEach(function (row) {
    h += '<tr>' + row.map(function (c) { return '<td>' + c + '</td>'; }).join('') + '</tr>';
  });
  return h + '</tbody></table>';
}
