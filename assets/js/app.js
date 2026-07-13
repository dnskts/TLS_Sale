/**
 * app.js
 *
 * Главный скрипт страницы:
 * - переключение вкладок слева
 * - сбор фильтров и запрос к api/
 * - обновление KPI
 * - drill-down из воронок в детализацию
 */
(function () {
  var state = {
    tab: 'overview',
    filters: {},
    drill: null,
    options: null,
    overviewData: null,
    settingsToken: localStorage.getItem('tls_settings_token') || '',
  };

  var multiSelects = {};
  var applyingPreset = false;

  function msg(text, isError) {
    var el = document.getElementById('app-message');
    if (!el) return;
    el.textContent = text || '';
    el.style.color = isError ? '#991b1b' : '#535c69';
  }

  function msValues(key) {
    return multiSelects[key] ? multiSelects[key].getValues() : [];
  }

  function pad2(n) {
    return n < 10 ? '0' + n : String(n);
  }

  function formatDateISO(d) {
    return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
  }

  function formatDateDisplay(iso) {
    if (!iso || iso.length < 10) return '';
    var p = iso.split('-');
    if (p.length < 3) return iso;
    return pad2(parseInt(p[2], 10)) + '/' + pad2(parseInt(p[1], 10)) + '/' + p[0];
  }

  function parseDateDisplay(str) {
    var m = String(str || '').trim().match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (!m) return null;
    var d = parseInt(m[1], 10);
    var mo = parseInt(m[2], 10);
    var y = parseInt(m[3], 10);
    if (mo < 1 || mo > 12 || d < 1 || d > 31) return null;
    var dt = new Date(y, mo - 1, d);
    if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) return null;
    return formatDateISO(dt);
  }

  function getDateInputIso(id) {
    var el = document.getElementById(id);
    if (!el) return null;
    return parseDateDisplay(el.value) || el.getAttribute('data-iso') || null;
  }

  function setDateInputDisplay(id, iso) {
    var el = document.getElementById(id);
    if (!el || !iso) return;
    el.setAttribute('data-iso', iso);
    el.value = formatDateDisplay(iso);
  }

  function initDateInputs() {
    ['filter-date-from', 'filter-date-to'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      var iso = el.getAttribute('data-iso');
      if (iso) el.value = formatDateDisplay(iso);
    });
  }

  function effectiveSourceForKpi() {
    if (state.tab === 'funnel-1c') return '1c';
    if (state.tab === 'funnel-bitrix') return 'bitrix';
    if (state.tab === 'funnel-unified') return 'all';
    var el = document.getElementById('filter-source');
    return el ? (el.value || 'all') : 'all';
  }

  function updateKpiSubtitles(source) {
    var salesSub = '1С + Битрикс';
    if (source === '1c') salesSub = 'только 1С';
    else if (source === 'bitrix') salesSub = 'только Битрикс';
    var salesEl = document.getElementById('kpi-sub-sales');
    if (salesEl) salesEl.textContent = salesSub;
  }

  function applyPeriodPreset(preset) {
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    var from = new Date(today);
    if (preset === 'day') {
      // from = today
    } else if (preset === 'week') {
      var dow = today.getDay();
      var diff = dow === 0 ? 6 : dow - 1;
      from.setDate(today.getDate() - diff);
    } else if (preset === 'month') {
      from = new Date(today.getFullYear(), today.getMonth(), 1);
    } else if (preset === 'year') {
      from = new Date(today.getFullYear(), 0, 1);
    } else {
      return;
    }
    applyingPreset = true;
    setDateInputDisplay('filter-date-from', formatDateISO(from));
    setDateInputDisplay('filter-date-to', formatDateISO(today));
    applyingPreset = false;
  }

  function collectFilters() {
    var filters = {
      date_from: getDateInputIso('filter-date-from'),
      date_to: getDateInputIso('filter-date-to'),
      source: document.getElementById('filter-source').value || 'all',
      teams: msValues('team'),
      agents: msValues('agent'),
      show_inactive_agents: document.getElementById('filter-inactive').checked,
      show_unknown_agents: document.getElementById('filter-unknown').checked,
      categories: msValues('category'),
      channels: msValues('channel'),
      card_types: msValues('card_type'),
      client_types: msValues('client_type'),
      request_types: msValues('request_type'),
      clients: msValues('client'),
      partners: msValues('partner'),
    };
    if (state.drill) {
      Object.keys(state.drill).forEach(function (k) {
        filters[k] = state.drill[k];
      });
    }
    return filters;
  }

  async function api(path, body, method) {
    method = method || (body ? 'POST' : 'GET');
    var opts = {
      method: method,
      headers: { 'Content-Type': 'application/json' },
    };
    if (state.settingsToken) {
      opts.headers['X-Settings-Token'] = state.settingsToken;
    }
    if (body && method !== 'GET') {
      opts.body = JSON.stringify(body);
    }
    var url = path;
    if (method === 'GET' && body) {
      url += (url.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(JSON.stringify(body));
    }
    if (method === 'GET') {
      opts.method = 'POST';
      opts.body = JSON.stringify(body || {});
    }
    var res = await fetch(url, opts);
    var rawText = await res.text();
    var data;
    try {
      data = rawText ? JSON.parse(rawText) : {};
    } catch (parseErr) {
      throw new Error('Сервер вернул не JSON (HTTP ' + res.status + ')');
    }
    if (!res.ok || data.ok === false) {
      throw new Error(data.error || ('Ошибка HTTP ' + res.status));
    }
    return data;
  }

  function initMultiSelects() {
    var defs = [
      { key: 'team', wrap: 'filter-team-wrap', label: 'Команда' },
      { key: 'agent', wrap: 'filter-agent-wrap', label: 'Агент' },
      { key: 'category', wrap: 'filter-category-wrap', label: 'Категория' },
      { key: 'channel', wrap: 'filter-channel-wrap', label: 'Канал' },
      { key: 'card_type', wrap: 'filter-card-type-wrap', label: 'Тип карты' },
      { key: 'client_type', wrap: 'filter-client-type-wrap', label: 'Тип клиента' },
      { key: 'request_type', wrap: 'filter-request-type-wrap', label: 'Тип запроса' },
      { key: 'client', wrap: 'filter-client-wrap', label: 'Клиент', searchable: true },
      { key: 'partner', wrap: 'filter-partner-wrap', label: 'Партнёр / поставщик', searchable: true },
    ];
    defs.forEach(function (def) {
      var wrap = document.getElementById(def.wrap);
      if (!wrap) return;
      if (!multiSelects[def.key]) {
        multiSelects[def.key] = window.MultiSelect.mount(wrap, {
          id: def.key,
          label: def.label,
          placeholder: 'Все',
          options: [],
          searchable: !!def.searchable,
        });
      }
    });
  }

  async function loadFilterOptions() {
    var data = await api('api/filters.php', {});
    state.options = data.options;
    if (data.defaults) {
      if (data.defaults.source) {
        document.getElementById('filter-source').value = data.defaults.source;
      }
      document.getElementById('filter-inactive').checked = !!data.defaults.show_inactive_agents;
    }
    initMultiSelects();
    multiSelects.team.setOptions(data.options.teams, true);
    var agents = (data.options.agents_active || []).slice();
    if (document.getElementById('filter-inactive').checked) {
      agents = agents.concat(data.options.agents_inactive || []);
    }
    multiSelects.agent.setOptions(agents, true);
    multiSelects.category.setOptions(data.options.categories, true);
    multiSelects.channel.setOptions(data.options.channels, true);
    multiSelects.card_type.setOptions(data.options.card_types, true);
    multiSelects.client_type.setOptions(data.options.client_types, true);
    multiSelects.request_type.setOptions(data.options.request_types, true);
    multiSelects.client.setOptions(data.options.clients, true);
    multiSelects.partner.setOptions(data.options.partners, true);
    return data;
  }

  function setChromeVisible(show) {
    document.getElementById('filters-panel').classList.toggle('hidden', !show);
    document.getElementById('kpi-container').classList.toggle('hidden', !show);
  }

  function setActiveTab(tab) {
    document.querySelectorAll('.nav-tab').forEach(function (b) {
      b.classList.toggle('active', b.getAttribute('data-tab') === tab);
    });
    var settingsBtn = document.querySelector('.btn-settings-icon');
    if (settingsBtn) {
      settingsBtn.classList.toggle('active', tab === 'settings');
    }
  }

  async function refreshKpi(preloaded) {
    var source = effectiveSourceForKpi();
    var kpiFilters = Object.assign({}, state.filters, { source: source });
    var data = preloaded;
    if (!data) {
      data = await api('api/kpi.php', kpiFilters);
    }
    document.getElementById('kpi-sales').textContent = data.kpi.sales_total;
    document.getElementById('kpi-profit').textContent = data.kpi.profit_total;
    document.getElementById('kpi-margin').textContent = data.kpi.margin;
    document.getElementById('kpi-deals').textContent = data.kpi.deals_count;
    document.getElementById('kpi-sub-deals').textContent = data.kpi.deals_sub || '';
    document.getElementById('kpi-extra-title').textContent = data.kpi.extra_title || '';
    document.getElementById('kpi-extra-value').textContent = data.kpi.extra_value || '—';
    document.getElementById('kpi-extra-sub').textContent = data.kpi.extra_sub || '';
    document.getElementById('kpi-avg-check').textContent = data.kpi.avg_check;
    updateKpiSubtitles(source);
    return data;
  }

  function goToDetails(drill) {
    state.drill = drill || null;
    state.tab = 'details';
    applyAll();
  }

  function clearDrill() {
    state.drill = null;
    applyAll();
  }

  function goToTab(tab) {
    state.tab = tab;
    applyAll();
  }

  function tabContext() {
    return {
      api: api,
      filters: state.filters,
      drill: state.drill,
      overviewData: state.overviewData,
      getToken: function () { return state.settingsToken; },
      setToken: function (t) {
        state.settingsToken = t || '';
        if (t) localStorage.setItem('tls_settings_token', t);
        else localStorage.removeItem('tls_settings_token');
      },
      msg: msg,
      goToDetails: goToDetails,
      goToTab: goToTab,
      clearDrill: clearDrill,
    };
  }

  async function renderTab() {
    var root = document.getElementById('tab-content');
    root.classList.toggle('tab-panel-compact', state.tab === 'details');
    setChromeVisible(state.tab !== 'settings');
    setActiveTab(state.tab);
    var map = {
      overview: window.TabOverview,
      agents: window.TabAgents,
      insights: window.TabInsights,
      structure: window.TabStructure,
      'funnel-unified': window.TabFunnelUnified,
      'funnel-1c': window.TabFunnel1c,
      'funnel-bitrix': window.TabFunnelBitrix,
      details: window.TabDetails,
      settings: window.TabSettings,
    };
    var tab = map[state.tab];
    if (!tab) {
      root.innerHTML = '<p>Вкладка не найдена</p>';
      return;
    }
    root.innerHTML = '<p class="tab-note">Загрузка вкладки…</p>';
    try {
      await tab.render(root, tabContext());
    } catch (e) {
      root.innerHTML = '<div class="settings-alert settings-alert-error">' + e.message + '</div>';
    }
  }

  async function applyAll() {
    state.filters = collectFilters();
    state.overviewData = null;
    msg('Применяю фильтры…');
    try {
      if (state.tab !== 'settings') {
        if (state.tab === 'overview') {
          var source = effectiveSourceForKpi();
          var overviewFilters = Object.assign({ granularity: 'month' }, state.filters, { source: source });
          var overviewData = await api('api/overview.php', overviewFilters);
          state.overviewData = overviewData;
          await refreshKpi(overviewData);
        } else {
          await refreshKpi();
        }
      }
      await renderTab();
      state.overviewData = null;
      msg('Готово.');
    } catch (e) {
      state.overviewData = null;
      msg(e.message, true);
    }
  }

  function onTabClick(ev) {
    var btn = ev.target.closest('[data-tab]');
    if (!btn) return;
    ev.preventDefault();
    state.tab = btn.getAttribute('data-tab');
    applyAll();
  }

  document.getElementById('main-nav').addEventListener('click', onTabClick);
  var footerLinks = document.getElementById('sidebar-footer-links');
  if (footerLinks) {
    footerLinks.addEventListener('click', onTabClick);
  }

  document.getElementById('btn-apply-filters').addEventListener('click', applyAll);

  document.getElementById('filter-inactive').addEventListener('change', function () {
    loadFilterOptions().catch(function () {});
  });

  document.getElementById('filter-period-preset').addEventListener('change', function (ev) {
    var preset = ev.target.value;
    if (preset) {
      applyPeriodPreset(preset);
      applyAll();
    }
  });

  ['filter-date-from', 'filter-date-to'].forEach(function (id) {
    var el = document.getElementById(id);
    el.addEventListener('input', function () {
      if (!applyingPreset) {
        document.getElementById('filter-period-preset').value = '';
      }
    });
    el.addEventListener('blur', function () {
      var iso = parseDateDisplay(el.value);
      if (iso) {
        el.setAttribute('data-iso', iso);
        el.value = formatDateDisplay(iso);
      }
    });
  });

  initDateInputs();

  document.getElementById('btn-refresh-data').addEventListener('click', async function () {
    msg('Идёт пересборка данных из Excel (может занять минуту)…');
    try {
      var data = await api('api/pipeline.php', {});
      msg('Данные обновлены: Unified ' + (data.meta.counts.sales_unified || 0));
      await loadFilterOptions();
      await applyAll();
      location.reload();
    } catch (e) {
      msg(e.message, true);
    }
  });

  initMultiSelects();
  state.filters = collectFilters();

  loadFilterOptions()
    .then(function () { return applyAll(); })
    .catch(function (e) { msg(e.message + ' — возможно, ещё не запускали «Обновить данные».', true); });

  window.AppState = state;
  window.AppGoToDetails = goToDetails;
  window.AppClearDrill = clearDrill;
})();
