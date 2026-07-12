/** tab_structure.js */
window.TabStructure = {
  STRUCTURE_LABELS: {
    category: 'Категория',
    channel: 'Канал',
    client_type: 'Тип клиента',
    card_type: 'Тип карты',
    request_type: 'Тип запроса',
    partner: 'Партнёр / поставщик',
  },

  async render(root, ctx) {
    var labels = this.STRUCTURE_LABELS;
    var keys = Object.keys(labels);
    root.innerHTML =
      '<h2>Структура продаж</h2><div class="charts-grid">' +
      keys.map(function (id) {
        return '<div class="chart-slot"><h4 class="chart-slot-title">' + labels[id] + '</h4><div id="st-' + id + '"></div></div>';
      }).join('') + '</div>';
    var data = await ctx.api('api/structure.php', ctx.filters);
    keys.forEach(function (key) {
      SimpleCharts.barChart('st-' + key, (data[key] || []).map(function (r) {
        return { label: r.label, value: Math.round(r.sales) };
      }));
    });
  },
};
