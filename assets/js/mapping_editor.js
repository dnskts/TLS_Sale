/**
 * mapping_editor.js — единая таблица маппинга: поле парсера · описание · 1С · Б24 УО · Б24 New
 */
(function (global) {
  var TOKEN_KEY = 'tls_sale_settings_token';

  /** Русские описания полей парсера (битрыкс / общие / служебные). */
  var FIELD_DESC = {
    deal_no: 'Номер сделки в CRM',
    deal_title: 'Название сделки',
    deal_created_at: 'Дата создания сделки',
    deal_status: 'Стадия / статус сделки',
    responsible_person: 'Ответственный (агент Битрикс)',
    agent_sale_participation: '% участия агента в продаже',
    client_type: 'Тип клиента',
    client: 'Клиент (ФИО / название)',
    id_client: 'ID клиента КС / CRM',
    card_type: 'Тип карты',
    category: 'Категория / тип сделки',
    channel: 'Канал связи',
    lead_id: 'ID лида',
    request_type: 'Тип запроса (Travel / Lifestyle)',
    service_date: 'Дата оказания услуги',
    client_paid_at: 'Дата оплаты клиентом',
    planned_close_date: 'Плановая дата закрытия / дата окончания',
    last_activity_at: 'Дата последней активности',
    lead_source: 'Источник / маркетинговый канал',
    calls_count: 'Количество звонков',
    meetings_count: 'Количество встреч',
    deal_result: 'Результат сделки (Успех / Проиграна)',
    lost_deal_reason: 'Причина проигрыша',
    partner: 'Партнёр / полное наименование организации',
    service_fee: 'Сервисный сбор',
    _commission: 'Комиссия (служебное → profit)',
    _total_client_pay: 'Всего к оплате клиентом (служебное → sales_amount)',
    sales_amount: 'Сумма продажи',
    profit: 'Прибыль',
    profit_ex_vat: 'Прибыль без НДС',
    date_for_sales: 'Дата для продаж (вычисляется)',
    date_fallback_used: 'Флаг: дата взята из создания сделки',
    source: 'Источник строки (1c / bitrix)',
    bitrix_format: 'Профиль выгрузки Битрикс',
    date_operation: 'Дата операции (1С)',
    datetime_operation: 'Дата и время операции (1С)',
    agent: 'Агент 1С',
    issuing_agent: 'Выписывающий агент',
    supplier: 'Поставщик',
    case_raw: 'Кейс / обращение',
    id_crm: 'ID CRM из 1С',
    case_status_change_date: 'Дата смены статуса кейса',
    client_from_case: 'Клиент из кейса',
    id_client_from_case: 'ID клиента из кейса',
    related_company: 'Связанная компания',
    case_cost_codes: 'Кост-коды кейса',
    service_scheme: 'Схема реализации услуг',
    order_raw: 'Заказ',
    case_department: 'Департамент (из кейса)',
    department: 'Подразделение',
    related_service_type: 'Связанный вид услуги',
    product: 'Продукт',
    payment_date: 'Дата оплаты',
    realization_date: 'Дата реализации',
    supplier_commission: 'Комиссия поставщика',
    vat_commission: 'НДС комиссии',
    markup: 'Наценка',
    vat_markup: 'НДС наценки',
    vat_fee: 'НДС сбора',
    sr: 'SR',
    lr: 'LR',
    solid_bank_privilege: 'Привилегия SOLID BANK',
    rs_cashback_points: 'Баллы RS Cashback',
    points_ax: 'Баллы AX',
    points_imp: 'Баллы IMP',
    cashless: 'Безнал',
    against_salary: 'В счёт ЗП',
    certificate: 'Сертификат',
    loss_company: 'Убыток на компанию',
    loss_employee: 'Убыток на сотрудника',
    travelers: 'Путешественники',
  };

  function getToken() {
    try { return localStorage.getItem(TOKEN_KEY) || ''; } catch (e) { return ''; }
  }
  function setToken(t) {
    try {
      if (t) localStorage.setItem(TOKEN_KEY, t);
      else localStorage.removeItem(TOKEN_KEY);
    } catch (e) { /* ignore */ }
  }
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /** Reverse headers map: field → "header1 | header2" */
  function reverseHeaders(headers) {
    var byField = {};
    Object.keys(headers || {}).forEach(function (h) {
      var f = headers[h];
      if (!f) return;
      if (!byField[f]) byField[f] = [];
      if (byField[f].indexOf(h) < 0) byField[f].push(h);
    });
    var out = {};
    Object.keys(byField).forEach(function (f) {
      out[f] = byField[f].join(' | ');
    });
    return out;
  }

  var MappingEditor = {
    state: { mapping: null, dirty: false, msg: '' },

    mount: function (root) {
      this.root = root;
      if (!getToken()) {
        this.renderAuth();
        return;
      }
      this.load();
    },

    renderAuth: function () {
      var self = this;
      this.root.innerHTML =
        '<header class="parser-spec-header">' +
        '<h1>Маппинг полей</h1>' +
        '<p class="tab-note">Нужна авторизация настроек.</p>' +
        '<a href="index.php" class="btn-secondary">← К дашборду</a></header>' +
        '<div class="settings-form settings-auth-panel" style="max-width:420px;margin:24px auto">' +
        '<label class="form-label">Логин</label><input id="map-auth-login" class="input-field" type="text">' +
        '<label class="form-label">Пароль</label><input id="map-auth-password" class="input-field" type="password">' +
        '<div id="map-auth-msg"></div>' +
        '<button type="button" class="btn-primary" id="map-btn-auth">Войти</button></div>';
      document.getElementById('map-btn-auth').onclick = async function () {
        try {
          var res = await fetch('api/auth.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              action: 'login',
              login: document.getElementById('map-auth-login').value,
              password: document.getElementById('map-auth-password').value,
            }),
          });
          var data = await res.json();
          if (!data.ok) throw new Error(data.error || 'Ошибка входа');
          setToken(data.token);
          self.load();
        } catch (e) {
          document.getElementById('map-auth-msg').innerHTML =
            '<div class="settings-alert settings-alert-error">' + esc(e.message) + '</div>';
        }
      };
    },

    api: async function (method, body) {
      var opts = { method: method, headers: { 'X-Settings-Token': getToken() } };
      if (body) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
      }
      var res = await fetch('api/mapping.php', opts);
      var text = await res.text();
      var data = null;
      try {
        data = text ? JSON.parse(text) : null;
      } catch (parseErr) {
        throw new Error('Ответ сервера не JSON (HTTP ' + res.status + '): ' + String(text).slice(0, 180));
      }
      if (!res.ok || !data || data.ok === false) {
        if (res.status === 401) { setToken(''); this.renderAuth(); }
        throw new Error((data && data.error) || ('HTTP ' + res.status));
      }
      return data;
    },

    load: async function () {
      var self = this;
      this.root.innerHTML = '<p class="tab-note">Загрузка mapping.json…</p>';
      try {
        var data = await this.api('GET');
        this.state.mapping = data.mapping;
        this.state.dirty = false;
        this.state.msg = data.exists ? '' : 'Файл mapping.json отсутствовал — загружен дефолт. Сохраните, чтобы создать файл.';
        this.render();
      } catch (e) {
        this.root.innerHTML =
          '<div class="settings-alert settings-alert-error">' + esc(e.message) + '</div>' +
          '<button type="button" class="btn-secondary" id="map-retry">Повторить</button>';
        document.getElementById('map-retry').onclick = function () { self.load(); };
      }
    },

    markDirty: function () {
      this.state.dirty = true;
      var el = document.getElementById('map-dirty');
      if (el) el.classList.remove('hidden');
    },

    /** Строки единой таблицы из mapping.json */
    buildUnifiedRows: function (m) {
      var oneByField = {};
      (m.one_c.columns || []).forEach(function (c) {
        if (!c || !c.field) return;
        oneByField[c.field] = c;
      });
      var uo = reverseHeaders((m.bitrix_profiles.universal || {}).headers || {});
      var neu = reverseHeaders((m.bitrix_profiles.deals_export || {}).headers || {});
      var fields = {};
      Object.keys(oneByField).forEach(function (f) { fields[f] = true; });
      Object.keys(uo).forEach(function (f) { fields[f] = true; });
      Object.keys(neu).forEach(function (f) { fields[f] = true; });
      // Не подмешивать canonical_fields — иначе удалённые строки «воскресают» после save/render.

      var rows = Object.keys(fields).map(function (field) {
        var one = oneByField[field];
        var label = (one && (one.label || one.header)) || FIELD_DESC[field] || field;
        var note = FIELD_DESC[field] || label;
        return {
          field: field,
          label: label,
          note: note,
          one_c_index: one ? String(one.index) : '',
          one_c_header: one ? (one.header || '') : '',
          b24_uo: uo[field] || '',
          b24_new: neu[field] || '',
          system: field.charAt(0) === '_' || field === 'date_for_sales' || field === 'date_fallback_used' || field === 'source' || field === 'bitrix_format',
        };
      });
      rows.sort(function (a, b) {
        if (a.system !== b.system) return a.system ? 1 : -1;
        var ai = a.one_c_index === '' ? 9999 : parseInt(a.one_c_index, 10);
        var bi = b.one_c_index === '' ? 9999 : parseInt(b.one_c_index, 10);
        if (ai !== bi) return ai - bi;
        return a.field.localeCompare(b.field);
      });
      return rows;
    },

    /** Собрать mapping.json из единой таблицы (без сброса чужих вкладок). */
    collectFromDom: function () {
      var m = this.state.mapping;
      if (!m) return null;

      var oneCols = [];
      var headersUo = {};
      var headersNew = {};
      var fieldSeen = {};

      document.querySelectorAll('#map-unified-body tr').forEach(function (tr) {
        var field = (tr.querySelector('[data-k=field]') || {}).value;
        if (!field) return;
        field = String(field).trim();
        if (!field) return;
        fieldSeen[field] = true;

        var label = (tr.querySelector('[data-k=label]') || {}).value || field;
        var oneIdx = (tr.querySelector('[data-k=one_c_index]') || {}).value;
        var oneHeader = (tr.querySelector('[data-k=one_c_header]') || {}).value || '';
        var uo = (tr.querySelector('[data-k=b24_uo]') || {}).value || '';
        var neu = (tr.querySelector('[data-k=b24_new]') || {}).value || '';

        if (oneIdx !== '' && oneIdx != null && !Number.isNaN(parseInt(oneIdx, 10))) {
          oneCols.push({
            index: parseInt(oneIdx, 10),
            field: field,
            header: oneHeader || label,
            label: label,
          });
        }
        uo.split('|').map(function (x) { return x.trim(); }).filter(Boolean).forEach(function (h) {
          headersUo[h] = field;
        });
        neu.split('|').map(function (x) { return x.trim(); }).filter(Boolean).forEach(function (h) {
          headersNew[h] = field;
        });
      });

      oneCols.sort(function (a, b) { return a.index - b.index; });
      if (!m.one_c) m.one_c = {};
      if (!m.bitrix_profiles) m.bitrix_profiles = {};
      if (!m.bitrix_profiles.universal) m.bitrix_profiles.universal = {};
      if (!m.bitrix_profiles.deals_export) m.bitrix_profiles.deals_export = {};
      m.one_c.columns = oneCols;
      m.bitrix_profiles.universal.headers = headersUo;
      m.bitrix_profiles.deals_export.headers = headersNew;

      // Актуализировать каталог полей по строкам таблицы (без воскрешения старых)
      var fieldList = Object.keys(fieldSeen).sort();
      m.canonical_fields = m.canonical_fields || {};
      m.canonical_fields.one_c = fieldList.filter(function (f) {
        return oneCols.some(function (c) { return c.field === f; });
      });
      m.canonical_fields.bitrix = fieldList.filter(function (f) {
        return Object.keys(headersUo).some(function (h) { return headersUo[h] === f; })
          || Object.keys(headersNew).some(function (h) { return headersNew[h] === f; })
          || f.charAt(0) === '_' || f === 'date_for_sales' || f === 'date_fallback_used'
          || f === 'source' || f === 'bitrix_format';
      });
      if (m.canonical_fields.bitrix.length === 0) {
        m.canonical_fields.bitrix = fieldList.slice();
      }

      var salesUo = document.getElementById('map-enrich-sales-universal');
      var profitUo = document.getElementById('map-enrich-profit-universal');
      var salesNew = document.getElementById('map-enrich-sales-deals_export');
      var profitNew = document.getElementById('map-enrich-profit-deals_export');
      if (salesUo && profitUo) {
        m.bitrix_profiles.universal.enrich = {
          sales_amount_from: salesUo.value || null,
          profit_ex_vat_from: profitUo.value.split(',').map(function (x) { return x.trim(); }).filter(Boolean),
        };
      }
      if (salesNew && profitNew) {
        m.bitrix_profiles.deals_export.enrich = {
          sales_amount_from: salesNew.value || null,
          profit_ex_vat_from: profitNew.value.split(',').map(function (x) { return x.trim(); }).filter(Boolean),
        };
      }

      return m;
    },

    save: async function () {
      try {
        var mapping = this.collectFromDom();
        var data = await this.api('POST', { action: 'save', mapping: mapping });
        this.state.mapping = data.mapping;
        this.state.dirty = false;
        this.state.msg = 'Сохранено в mapping.json' +
          (data.warnings && data.warnings.length ? ' · ' + data.warnings.join('; ') : '');
        this.render();
      } catch (e) {
        this.state.msg = 'Ошибка: ' + e.message;
        var msg = document.getElementById('map-msg');
        if (msg) msg.innerHTML = '<div class="settings-alert settings-alert-error">' + esc(this.state.msg) + '</div>';
      }
    },

    render: function () {
      var self = this;
      var m = this.state.mapping;
      var rows = this.buildUnifiedRows(m);
      var enrichUo = (m.bitrix_profiles.universal || {}).enrich || {};
      var enrichNew = (m.bitrix_profiles.deals_export || {}).enrich || {};

      var body =
        '<p class="tab-note"><strong>Модель:</strong> одна строка = одно <em>поле парсера</em> (то, что попадёт в данные дашборда). ' +
        'В колонках справа вы вручную указываете, откуда брать значение в каждом Excel.</p>' +
        '<p class="tab-note">Пример: поле <code>deal_no</code>, описание «номер сделки»; ' +
        '1С # = индекс столбца и заголовок «Кейс»; Б24 УО = «Номер сделки»; Б24 New = «ID». ' +
        'После «Сохранить» парсеры читают только <code>mapping.json</code> и заполняют ключ <code>deal_no</code>.</p>' +
        '<p class="tab-note">Пустая ячейка источника = поле из этого файла не берётся. ' +
        'Несколько заголовков Б24 через <code>|</code>. Удалённые строки не восстанавливаются.</p>' +
        '<div class="mapping-enrich-block">' +
        '<p class="tab-note"><strong>Enrich (только Битрикс).</strong> После маппинга колонок итоги ' +
        '<code>sales_amount</code> / <code>profit_ex_vat</code> иногда собирают из других полей парсера ' +
        '(New: оплата клиентом / комиссия+сбор). У 1С сумма и прибыль — обычные строки таблицы. ' +
        'Ниже — имена полей парсера; для прибыли несколько через запятую сложатся.</p>' +
        '<div class="settings-general-compact">' +
        '<div class="form-row"><label class="form-label">Б24 New → сумма продажи<small>sales_amount_from</small></label>' +
        '<input class="input-field" id="map-enrich-sales-deals_export" value="' + esc(enrichNew.sales_amount_from || '') + '"></div>' +
        '<div class="form-row"><label class="form-label">Б24 New → прибыль без НДС<small>profit_ex_vat_from</small></label>' +
        '<input class="input-field" id="map-enrich-profit-deals_export" value="' + esc((enrichNew.profit_ex_vat_from || []).join(', ')) + '"></div>' +
        '<div class="form-row"><label class="form-label">Б24 УО → сумма продажи<small>sales_amount_from</small></label>' +
        '<input class="input-field" id="map-enrich-sales-universal" value="' + esc(enrichUo.sales_amount_from || '') + '"></div>' +
        '<div class="form-row"><label class="form-label">Б24 УО → прибыль без НДС<small>profit_ex_vat_from</small></label>' +
        '<input class="input-field" id="map-enrich-profit-universal" value="' + esc((enrichUo.profit_ex_vat_from || []).join(', ')) + '"></div>' +
        '</div></div>' +
        '<div class="parser-spec-table-wrap mapping-unified-wrap">' +
        '<table class="settings-table mapping-unified-table" id="map-unified-table">' +
        '<thead><tr>' +
        '<th title="Ключ в данных парсера / дашборда">Поле парсера</th>' +
        '<th>Описание</th>' +
        '<th title="Номер столбца Excel 1С слева направо, с нуля">1С #</th>' +
        '<th title="Подпись шапки 1С (для вас; парсер читает по #)">1С заголовок</th>' +
        '<th title="Точный заголовок колонки в Универсальном отчёте">Б24 УО</th>' +
        '<th title="Точный заголовок колонки в deals_export">Б24 New</th>' +
        '<th></th>' +
        '</tr></thead><tbody id="map-unified-body">';

      rows.forEach(function (r, i) {
        body +=
          '<tr class="' + (r.system ? 'mapping-row-system' : '') + '">' +
          '<td><input class="input-field" data-k="field" value="' + esc(r.field) + '" style="min-width:140px"></td>' +
          '<td><input class="input-field" data-k="label" value="' + esc(r.note || r.label) + '" title="' + esc(r.note) + '" style="min-width:180px"></td>' +
          '<td><input class="input-field" data-k="one_c_index" type="number" min="0" value="' + esc(r.one_c_index) + '" style="width:64px"></td>' +
          '<td><input class="input-field" data-k="one_c_header" value="' + esc(r.one_c_header) + '" style="min-width:120px"></td>' +
          '<td><input class="input-field" data-k="b24_uo" value="' + esc(r.b24_uo) + '" style="min-width:140px"></td>' +
          '<td><input class="input-field" data-k="b24_new" value="' + esc(r.b24_new) + '" style="min-width:140px"></td>' +
          '<td><button type="button" class="btn-secondary btn-sm map-del-row">×</button></td>' +
          '</tr>';
      });
      body += '</tbody></table></div>' +
        '<button type="button" class="btn-secondary" id="map-add-row">+ Строка</button>';

      this.root.innerHTML =
        '<header class="parser-spec-header">' +
        '<h1>Маппинг полей</h1>' +
        '<p class="tab-note">Соответствия колонок Excel ↔ поля парсера (<code>mapping.json</code>). После правок — «Применить к данным» в настройках.</p>' +
        '<div class="settings-actions">' +
        '<a href="index.php" class="btn-secondary">← Дашборд</a>' +
        '<a href="parser_spec.php" class="btn-secondary">Логика парсера</a>' +
        '<button type="button" class="btn-secondary" id="map-reload">Перечитать</button>' +
        '<button type="button" class="btn-primary" id="map-save">Сохранить</button>' +
        '</div>' +
        '<div id="map-dirty" class="settings-dirty' + (this.state.dirty ? '' : ' hidden') + '">Есть несохранённые изменения</div>' +
        '<div id="map-msg">' +
        (this.state.msg
          ? '<div class="settings-alert ' +
            (String(this.state.msg).indexOf('Ошибка') === 0 ? 'settings-alert-error' : 'settings-alert-success') +
            '">' + esc(this.state.msg) + '</div>'
          : '') +
        '</div></header>' +
        '<section class="parser-spec-section">' + body + '</section>';

      document.getElementById('map-save').onclick = function () { self.save(); };
      document.getElementById('map-reload').onclick = function () { self.load(); };
      document.getElementById('map-add-row').onclick = function () {
        var tb = document.getElementById('map-unified-body');
        var tr = document.createElement('tr');
        tr.innerHTML =
          '<td><input class="input-field" data-k="field" value="" style="min-width:140px"></td>' +
          '<td><input class="input-field" data-k="label" value="" style="min-width:180px"></td>' +
          '<td><input class="input-field" data-k="one_c_index" type="number" min="0" value="" style="width:64px"></td>' +
          '<td><input class="input-field" data-k="one_c_header" value="" style="min-width:120px"></td>' +
          '<td><input class="input-field" data-k="b24_uo" value="" style="min-width:140px"></td>' +
          '<td><input class="input-field" data-k="b24_new" value="" style="min-width:140px"></td>' +
          '<td><button type="button" class="btn-secondary btn-sm map-del-row">×</button></td>';
        tb.appendChild(tr);
        self.bindEvents();
        self.markDirty();
      };
      this.bindEvents();
    },

    bindEvents: function () {
      var self = this;
      this.root.querySelectorAll('.map-del-row').forEach(function (btn) {
        btn.onclick = function () {
          btn.closest('tr').remove();
          self.markDirty();
        };
      });
      this.root.querySelectorAll('input').forEach(function (el) {
        el.addEventListener('change', function () { self.markDirty(); });
        el.addEventListener('input', function () { self.markDirty(); });
      });
    },
  };

  global.MappingEditor = MappingEditor;
})(window);
