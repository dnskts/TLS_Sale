/**
 * mapping_editor.js — единая таблица маппинга: поле парсера · описание · 1С · Б24 NEW
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
    client_id: 'ID клиента (1С: I d CRM, fallback ID из кейса)',
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
    date_operation: 'Дата финансовой операции',
    datetime_operation: 'Дата и время операции (1С)',
    agent: 'Агент продажи (1С / Б24)',
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
    payment_date: 'Дата оплаты (Б24: копия даты операции)',
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
    case_id: 'Номер кейса (равен номеру лида)',
    order_no: 'Номер заказа (равен номеру сделки)',
    additional_benefit: 'Дополнительная выгода',
    commission: 'Комиссия',
    total_client_pay: 'Всего к оплате клиентом',
    total_paid_by_client: 'Сумма оплаты клиентом',
    client_refund_amount: 'Сумма возврата клиенту',
    commission_refund: 'Возврат комиссии',
    service_fee_refund: 'Возврат сервисного сбора РС ТЛС',
    additional_benefit_refund: 'Возврат дополнительной выгоды',
    rstls_return_fee: 'Сбор РС ТЛС за возврат',
    rstls_return_penalty: 'Штраф РС ТЛС за возврат',
    supplier_return_fee: 'Сбор поставщика за возврат',
    supplier_return_penalty: 'Штраф поставщика за возврат',
    sales_amount_after_refund: 'Сумма продажи после возврата',
    profit_after_refund: 'Прибыль после возврата',
    profit_after_refund_ex_vat: 'Прибыль после возврата без НДС',
    supplier_retained: 'Удержал поставщик',
    vat_factor: 'Коэффициент НДС по дате',
    partner_full_name: 'Полное наименование организации',
    fin_card_scheme: 'Схема финансовой карты',
    payment_type: 'Тип оплаты',
    country: 'Страна',
    city: 'Город',
    hotel: 'Отель',
    restaurant: 'Ресторан',
    chain: 'Цепочка',
    start_date: 'Дата начала услуги',
    end_date: 'Дата окончания услуги',
    nights_count: 'Количество ночей',
    rooms_count: 'Количество номеров',
    lead_source: 'Маркетинговый канал',
    marketing_channel_reason: 'Причина маркетингового канала',
    cross_sell: 'Кросс-продажа',
    cross_sell_reason: 'Причина кросс-продажи',
  };

  var FIELD_GROUPS = {
    dashboard: {
      label: 'Поля дашборда',
      collapsed: false,
      fields: [
        'date_operation', 'deal_created_at', 'agent', 'client_type', 'client', 'client_id',
        'deal_no', 'case_id', 'supplier', 'category', 'channel', 'lead_source', 'card_type',
        'request_type', 'service_date', 'payment_date', 'deal_result', 'deal_status',
        'lost_deal_reason', 'sales_amount', 'profit_ex_vat', 'service_fee',
        'planned_close_date', 'last_activity_at', 'calls_count', 'meetings_count',
      ],
    },
    tourism: {
      label: 'Туристическая аналитика',
      collapsed: false,
      fields: ['country', 'city', 'hotel', 'start_date', 'end_date', 'nights_count', 'rooms_count'],
    },
    finance: {
      label: 'Финансовые операнды Б24',
      collapsed: true,
      fields: [
        'total_client_pay', 'additional_benefit', 'commission', 'client_refund_amount',
        'additional_benefit_refund', 'service_fee_refund', 'commission_refund',
        'rstls_return_fee', 'rstls_return_penalty', 'supplier_return_fee',
        'supplier_return_penalty',
      ],
    },
    system: {
      label: 'Расчётные и служебные поля',
      collapsed: true,
      fields: [
        'sales_amount_after_refund', 'profit', 'profit_after_refund',
        'profit_after_refund_ex_vat', 'supplier_retained', 'vat_factor',
        'date_for_sales', 'date_fallback_used', 'is_refund', 'raw_id', 'source', 'bitrix_format',
      ],
    },
  };

  function fieldGroup(field) {
    var names = Object.keys(FIELD_GROUPS);
    for (var i = 0; i < names.length; i++) {
      if (FIELD_GROUPS[names[i]].fields.indexOf(field) >= 0) return names[i];
    }
    return 'dashboard';
  }

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

  /** Ячейка Б24 — формула (+ - * / или =), а не заголовок Excel. */
  function looksLikeFormula(s) {
    s = String(s == null ? '' : s).trim();
    return !!s && s.charAt(0) === '=';
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
      var oneExtra = reverseHeaders((m.one_c || {}).extra_by_header || {});
      var neu = reverseHeaders((m.bitrix_profiles.deals_export || {}).headers || {});
      var formulasNew = (m.bitrix_profiles.deals_export || {}).formulas || {};
      var fields = {};
      Object.keys(oneByField).forEach(function (f) { fields[f] = true; });
      Object.keys(oneExtra).forEach(function (f) { fields[f] = true; });
      Object.keys(neu).forEach(function (f) { fields[f] = true; });
      Object.keys(formulasNew).forEach(function (f) { fields[f] = true; });
      [
        'vat_factor', 'date_for_sales', 'date_fallback_used',
        'is_refund', 'raw_id', 'source', 'bitrix_format',
      ].forEach(function (f) { fields[f] = true; });

      var rows = Object.keys(fields).map(function (field) {
        var one = oneByField[field];
        var label = (one && (one.label || one.header)) || FIELD_DESC[field] || field;
        var note = FIELD_DESC[field] || label;
        return {
          field: field,
          label: label,
          note: note,
          one_c_index: one ? String(one.index) : '',
          one_c_header: one ? (one.header || '') : (oneExtra[field] || ''),
          b24_new: neu[field] || '',
          formula: formulasNew[field] || '',
          group: fieldGroup(field),
          system: field.charAt(0) === '_' || [
            'vat_factor', 'date_for_sales', 'date_fallback_used',
            'is_refund', 'raw_id', 'source', 'bitrix_format',
          ].indexOf(field) >= 0,
        };
      });
      var groupOrder = ['dashboard', 'tourism', 'finance', 'system'];
      rows.sort(function (a, b) {
        var ag = groupOrder.indexOf(a.group);
        var bg = groupOrder.indexOf(b.group);
        if (ag !== bg) return ag - bg;
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
      var oneExtra = {};
      var headersNew = {};
      var formulasNew = {};
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
        var neu = String((tr.querySelector('[data-k=b24_new]') || {}).value || '').trim();
        var formula = String((tr.querySelector('[data-k=formula]') || {}).value || '').trim();

        if (oneIdx !== '' && oneIdx != null && !Number.isNaN(parseInt(oneIdx, 10))) {
          oneCols.push({
            index: parseInt(oneIdx, 10),
            field: field,
            header: oneHeader || label,
            label: label,
          });
        } else if (oneHeader) {
          oneHeader.split('|').map(function (x) { return x.trim(); }).filter(Boolean).forEach(function (h) {
            oneExtra[h] = field;
          });
        }
        if (neu) {
          neu.split('|').map(function (x) { return x.trim(); }).filter(Boolean).forEach(function (h) {
            headersNew[h] = field;
          });
        }
        if (formula) {
          formulasNew[field] = formula.replace(/^\s*=\s*/, '');
        }
      });

      oneCols.sort(function (a, b) { return a.index - b.index; });
      if (!m.one_c) m.one_c = {};
      if (!m.bitrix_profiles) m.bitrix_profiles = {};
      if (!m.bitrix_profiles.deals_export) m.bitrix_profiles.deals_export = {};
      m.one_c.columns = oneCols;
      m.one_c.extra_by_header = oneExtra;
      m.bitrix_profiles.deals_export.headers = headersNew;
      m.bitrix_profiles.deals_export.formulas = formulasNew;
      delete m.bitrix_profiles.universal;

      // Актуализировать каталог полей по строкам таблицы (без воскрешения старых)
      var fieldList = Object.keys(fieldSeen).sort();
      m.canonical_fields = m.canonical_fields || {};
      m.canonical_fields.one_c = fieldList.filter(function (f) {
        return oneCols.some(function (c) { return c.field === f; })
          || Object.keys(oneExtra).some(function (h) { return oneExtra[h] === f; });
      });
      m.canonical_fields.bitrix = fieldList.filter(function (f) {
        return Object.keys(headersNew).some(function (h) { return headersNew[h] === f; })
          || !!formulasNew[f]
          || f.charAt(0) === '_' || f === 'date_for_sales' || f === 'date_fallback_used'
          || f === 'source' || f === 'bitrix_format' || f === 'is_refund'
          || f === 'raw_id' || f === 'vat_factor';
      });
      if (m.canonical_fields.bitrix.length === 0) {
        m.canonical_fields.bitrix = fieldList.slice();
      }

      var salesNew = document.getElementById('map-enrich-sales-deals_export');
      var profitNew = document.getElementById('map-enrich-profit-deals_export');
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
      var enrichNew = (m.bitrix_profiles.deals_export || {}).enrich || {};

      var body =
        '<p class="tab-note"><strong>Модель:</strong> одна строка = одно <em>поле парсера</em> (то, что попадёт в данные дашборда). ' +
        'В колонках справа вы вручную указываете, откуда брать значение в каждом Excel.</p>' +
        '<p class="tab-note">Пример: поле <code>deal_no</code>, описание «номер сделки»; ' +
        '1С # = индекс столбца; Б24 NEW = заголовок поля или формула. ' +
        'После «Сохранить» парсеры читают только <code>mapping.json</code> и заполняют ключ <code>deal_no</code>.</p>' +
        '<p class="tab-note">Пустая ячейка источника = поле из этого файла не берётся. ' +
        'Если «1С #» пуст, поле ищется по заголовку 1С — так подключаются новые колонки «Тип запроса» и туристические поля. ' +
        'Несколько заголовков через <code>|</code>. Формула задаётся в отдельной колонке.</p>' +
        '<p class="tab-note"><strong>Формулы в Б24 NEW:</strong> ' +
        '<code>NZ(service_fee) / vat_factor</code> или <code>NZ("Сервисный сбор") / vat_factor</code> ' +
        '(поля парсера или заголовки Excel в кавычках; операции <code>+ - * /</code>, скобки). ' +
        '<code>NZ()</code> превращает пустой операнд в 0 только внутри расчёта. Без операторов — обычный заголовок колонки.</p>' +
        '<div class="mapping-enrich-block">' +
        '<p class="tab-note"><strong>Резервный enrich.</strong> Основные финансовые показатели задаются формулами; поля ниже используются только как fallback.</p>' +
        '<div class="settings-general-compact">' +
        '<div class="form-row"><label class="form-label">Б24 New → сумма продажи<small>sales_amount_from</small></label>' +
        '<input class="input-field" id="map-enrich-sales-deals_export" value="' + esc(enrichNew.sales_amount_from || '') + '"></div>' +
        '<div class="form-row"><label class="form-label">Б24 New → прибыль без НДС<small>profit_ex_vat_from</small></label>' +
        '<input class="input-field" id="map-enrich-profit-deals_export" value="' + esc((enrichNew.profit_ex_vat_from || []).join(', ')) + '"></div>' +
        '</div></div>' +
        '<div class="parser-spec-table-wrap mapping-unified-wrap">' +
        '<table class="settings-table mapping-unified-table" id="map-unified-table">' +
        '<thead><tr>' +
        '<th title="Ключ в данных парсера / дашборда">Поле парсера</th>' +
        '<th>Описание</th>' +
        '<th title="Номер столбца Excel 1С слева направо, с нуля">1С #</th>' +
        '<th title="Подпись шапки 1С (для вас; парсер читает по #)">1С заголовок</th>' +
        '<th title="Один или несколько заголовков через |">Б24 NEW</th>' +
        '<th title="Формула расчётного поля">Формула</th>' +
        '<th></th>' +
        '</tr></thead><tbody id="map-unified-body">';

      var lastGroup = '';
      rows.forEach(function (r, i) {
        if (r.group !== lastGroup) {
          var group = FIELD_GROUPS[r.group] || FIELD_GROUPS.dashboard;
          var groupCount = rows.filter(function (x) { return x.group === r.group; }).length;
          body += '<tr class="mapping-group-header" data-group-header="' + esc(r.group) + '">' +
            '<td colspan="7"><button type="button" class="btn-secondary btn-sm map-toggle-group" data-group="' +
            esc(r.group) + '">' + (group.collapsed ? '▸' : '▾') + ' ' + esc(group.label) +
            ' (' + groupCount + ')</button></td></tr>';
          lastGroup = r.group;
        }
        var collapsed = (FIELD_GROUPS[r.group] || {}).collapsed;
        body +=
          '<tr data-field-group="' + esc(r.group) + '" class="' + (r.system ? 'mapping-row-system' : '') +
          '"' + (collapsed ? ' style="display:none"' : '') + '>' +
          '<td><input class="input-field" data-k="field" value="' + esc(r.field) + '" style="min-width:140px"></td>' +
          '<td><input class="input-field" data-k="label" value="' + esc(r.note || r.label) + '" title="' + esc(r.note) + '" style="min-width:180px"></td>' +
          '<td><input class="input-field" data-k="one_c_index" type="number" min="0" value="' + esc(r.one_c_index) + '" style="width:64px"></td>' +
          '<td><input class="input-field" data-k="one_c_header" value="' + esc(r.one_c_header) + '" style="min-width:120px"></td>' +
          '<td><input class="input-field" data-k="b24_new" value="' + esc(r.b24_new) + '" style="min-width:140px"></td>' +
          '<td><input class="input-field" data-k="formula" value="' + esc(r.formula) + '" style="min-width:260px"></td>' +
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
          '<td><input class="input-field" data-k="b24_new" value="" style="min-width:140px"></td>' +
          '<td><input class="input-field" data-k="formula" value="" style="min-width:260px"></td>' +
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
      this.root.querySelectorAll('.map-toggle-group').forEach(function (btn) {
        btn.onclick = function () {
          var group = btn.getAttribute('data-group');
          var rows = self.root.querySelectorAll('[data-field-group="' + group + '"]');
          var opening = false;
          rows.forEach(function (row) {
            if (row.style.display === 'none') opening = true;
          });
          rows.forEach(function (row) { row.style.display = opening ? '' : 'none'; });
          var meta = FIELD_GROUPS[group] || {};
          btn.textContent = (opening ? '▾ ' : '▸ ') + (meta.label || group) + ' (' + rows.length + ')';
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
