/**
 * settings_editor.js
 *
 * UI редактора settings.json: агенты (таблица, массовые действия, модалки),
 * каталог команд (multi-team), объединение профилей, общие настройки.
 */
window.SettingsEditor = (function () {
  function esc(text) {
    return String(text == null ? '' : text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function slugify(name) {
    var map = {
      а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z', и: 'i',
      й: 'y', к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't',
      у: 'u', ф: 'f', х: 'h', ц: 'ts', ч: 'ch', ш: 'sh', щ: 'sch', ъ: '', ы: 'y', ь: '',
      э: 'e', ю: 'yu', я: 'ya',
    };
    var s = String(name || '').toLowerCase().trim();
    var out = '';
    for (var i = 0; i < s.length; i++) {
      var ch = s[i];
      if (map[ch]) out += map[ch];
      else if (/[a-z0-9]/.test(ch)) out += ch;
      else if (/\s/.test(ch) || ch === '-' || ch === '_') out += '_';
    }
    return out.replace(/_+/g, '_').replace(/^_|_$/g, '') || 'agent_new';
  }

  function aliasesPreview(list, max) {
    max = max || 2;
    var arr = list || [];
    if (!arr.length) return '—';
    var head = arr.slice(0, max).join(', ');
    if (arr.length > max) head += '… (+' + (arr.length - max) + ')';
    return head;
  }

  function parseAliases(text) {
    return String(text || '')
      .split(/\r?\n/)
      .map(function (s) { return s.trim(); })
      .filter(Boolean);
  }

  function uniqTeams(list) {
    var out = [];
    (list || []).forEach(function (t) {
      t = String(t || '').trim();
      if (t && out.indexOf(t) < 0) out.push(t);
    });
    return out.length ? out : ['Без команды'];
  }

  function normalizeAgent(agent) {
    var teams = uniqTeams(agent.teams && agent.teams.length ? agent.teams : [agent.team]);
    agent.teams = teams;
    agent.team = teams[0];
    return agent;
  }

  function agentTeams(agent) {
    return uniqTeams(agent.teams || [agent.team]);
  }

  function agentHasTeam(agent, team) {
    return agentTeams(agent).indexOf(team) >= 0;
  }

  function teamsLabel(teams, max) {
    max = max || 2;
    var arr = teams || [];
    if (!arr.length) return '—';
    if (arr.length <= max) return arr.join(', ');
    return arr.slice(0, max).join(', ') + '… (+' + (arr.length - max) + ')';
  }

  function unionAliases(arrays) {
    var seen = {};
    var out = [];
    arrays.forEach(function (list) {
      (list || []).forEach(function (name) {
        name = String(name || '').trim();
        if (name && !seen[name]) {
          seen[name] = true;
          out.push(name);
        }
      });
    });
    return out;
  }

  function Editor(root, ctx, data) {
    this.root = root;
    this.ctx = ctx;
    this.token = ctx.getToken();
    this.agents = JSON.parse(JSON.stringify(data.agents || [])).map(normalizeAgent);
    this.teams = (data.teams || []).slice();
    this.agents.forEach(function (a) {
      agentTeams(a).forEach(function (t) {
        if (this.teams.indexOf(t) < 0) this.teams.push(t);
      }, this);
    }, this);
    this.app = Object.assign({}, data.app || {});
    this.defaults = Object.assign({ source: 'all', show_inactive_agents: false }, data.defaults || {});
    this.ui = Object.assign({ agents_page_size: 25 }, data.ui || {});
    this.paths = data.paths || {};
    this.funnel = Object.assign({}, data.funnel || {});
    this.salesPlans = Object.assign({}, data.sales_plans || {});
    this.dismissed = (data.dismissed_agent_warnings || []).slice();
    this.selected = {};
    this.search = '';
    this.activeFilter = 'all';
    this.page = 0;
    this.modalMode = null;
    this.modalKey = null;
    this.teamsPickerKey = null;
    this.dirty = false;
    this._bindShell();
    this.render();
  }

  Editor.prototype.markDirty = function () {
    this.dirty = true;
    var el = document.getElementById('settings-dirty');
    if (el) el.classList.remove('hidden');
  };

  Editor.prototype.teamOptionsHtml = function (selected) {
    var html = '';
    this.teams.slice().sort().forEach(function (t) {
      html += '<option value="' + esc(t) + '"' + (t === selected ? ' selected' : '') + '>' + esc(t) + '</option>';
    });
    return html;
  };

  Editor.prototype.teamCheckboxesHtml = function (selectedTeams, prefix) {
    prefix = prefix || 'team-cb';
    var sel = selectedTeams || [];
    var html = '<div class="settings-team-checkboxes">';
    this.teams.slice().sort().forEach(function (t) {
      var id = prefix + '-' + slugify(t);
      html += '<label class="filter-pill settings-team-pill">' +
        '<input type="checkbox" class="settings-team-cb" data-team="' + esc(t) + '" id="' + esc(id) + '"' +
        (sel.indexOf(t) >= 0 ? ' checked' : '') + '>' +
        '<span>' + esc(t) + '</span></label>';
    });
    html += '</div>';
    return html;
  };

  Editor.prototype.readTeamCheckboxes = function (container) {
    var out = [];
    container.querySelectorAll('.settings-team-cb:checked').forEach(function (cb) {
      out.push(cb.getAttribute('data-team'));
    });
    return uniqTeams(out);
  };

  Editor.prototype.agentCountByTeam = function (team) {
    return this.agents.filter(function (a) { return agentHasTeam(a, team); }).length;
  };

  Editor.prototype.filteredAgents = function () {
    var q = this.search.toLowerCase().trim();
    var self = this;
    return this.agents.filter(function (a) {
      if (self.activeFilter === 'active' && !a.is_active) return false;
      if (self.activeFilter === 'inactive' && a.is_active) return false;
      if (!q) return true;
      var blob = [
        a.agent_key,
        a.name_display,
        (a.names_1c || []).join(' '),
        (a.names_bitrix || []).join(' '),
        agentTeams(a).join(' '),
      ].join(' ').toLowerCase();
      return blob.indexOf(q) >= 0;
    });
  };

  Editor.prototype.selectedKeys = function () {
    var out = [];
    Object.keys(this.selected).forEach(function (k) {
      if (this.selected[k]) out.push(k);
    }, this);
    return out;
  };

  Editor.prototype._bindShell = function () {
    var self = this;
    this.root.innerHTML =
      '<div class="settings-tab tab-panel-settings">' +
      '<div class="settings-header-row"><h2>Настройки</h2>' +
      '<button type="button" class="btn-secondary" id="btn-logout">Выйти</button></div>' +
      '<div class="settings-alert settings-alert-warning settings-save-hint">' +
      'Сохранение перезаписывает settings.json (резервная копия в data/backups/). ' +
      'Чтобы привязать алиасы к продажам — нажмите «Применить к данным».' +
      '</div>' +
      '<div id="settings-dirty" class="settings-dirty hidden">Есть несохранённые изменения</div>' +
      '<div id="set-msg"></div>' +
      '<div class="settings-actions">' +
      '<button type="button" class="btn-primary settings-btn-save" id="btn-save-settings">Сохранить изменения</button>' +
      '<button type="button" class="btn-secondary" id="btn-reload-settings">Перечитать с диска</button>' +
      '<button type="button" class="btn-secondary" id="btn-apply-pipeline">Применить к данным</button>' +
      '</div>' +
      '<div class="settings-actions settings-links-row">' +
      '<a href="mapping.php" class="btn-secondary">Маппинг полей</a>' +
      '<a href="parser_spec.php" class="btn-secondary">Логика парсера</a>' +
      '</div>' +

      '<section class="settings-section settings-section-general">' +
      '<h3>Общие</h3>' +
      '<div class="settings-general-compact">' +
      '<div class="form-row settings-general-field"><label class="form-label">Название</label>' +
      '<input type="text" id="set-app-title" class="input-field settings-search-input"></div>' +
      '<div class="form-row settings-general-field"><label class="form-label">Источник</label>' +
      '<select id="set-default-source" class="filter-control">' +
      '<option value="all">Все</option><option value="1c">1С</option><option value="bitrix">Битрикс</option></select></div>' +
      '<div class="form-row settings-general-field"><label class="form-label">Строк/стр.</label>' +
      '<select id="set-page-size" class="filter-control settings-page-size-dropdown">' +
      '<option value="25">25</option><option value="50">50</option><option value="100">100</option></select></div>' +
      '<div class="form-row settings-general-field settings-general-inactive">' +
      '<label class="filter-pill settings-inactive-pill"><input type="checkbox" id="set-default-inactive">' +
      '<span>Неактивные в фильтрах</span></label></div>' +
      '</div>' +
      '<div class="settings-general-info tab-note" id="settings-paths-info"></div>' +
      '<p class="tab-note">Парсер: <a href="mapping.php">Маппинг полей</a> · <a href="parser_spec.php">Логика парсера</a></p>' +
      '</section>' +

      '<details class="settings-collapsible settings-section-teams">' +
      '<summary class="settings-collapsible-summary">Команды <span id="teams-count-badge" class="settings-badge"></span></summary>' +
      '<div class="settings-collapsible-body">' +
      '<p class="tab-note">Каталог команд. Переименование обновит команду у всех агентов.</p>' +
      '<div class="settings-teams-actions">' +
      '<input type="text" id="new-team-name" class="input-field settings-search-input" placeholder="Новая команда">' +
      '<button type="button" class="btn-secondary" id="btn-add-team">Добавить</button>' +
      '</div>' +
      '<div class="settings-datatable settings-teams-datatable"><table class="settings-table" id="teams-table">' +
      '<thead><tr><th>Команда</th><th>Агентов</th><th></th></tr></thead><tbody></tbody></table></div>' +
      '</div></details>' +

      '<details class="settings-collapsible settings-section-analytics">' +
      '<summary class="settings-collapsible-summary">Аналитика: планы и SLA</summary>' +
      '<div class="settings-collapsible-body">' +
      '<p class="tab-note">План продаж на текущий месяц (YYYY-MM берётся из периода фильтра). Вероятности стадий — JSON.</p>' +
      '<div class="settings-general-compact">' +
      '<div class="form-row settings-general-field"><label class="form-label">План total (₽)</label>' +
      '<input type="number" id="set-plan-total" class="input-field" min="0" step="1000"></div>' +
      '<div class="form-row settings-general-field"><label class="form-label">Зависшие (дней)</label>' +
      '<input type="number" id="set-stuck-days" class="input-field" min="1"></div>' +
      '<div class="form-row settings-general-field"><label class="form-label">Без активности (дней)</label>' +
      '<input type="number" id="set-inactive-days" class="input-field" min="1"></div>' +
      '<div class="form-row settings-general-field"><label class="form-label">Порог суммы риска</label>' +
      '<input type="number" id="set-high-amount" class="input-field" min="0"></div>' +
      '</div>' +
      '<div class="form-row"><label class="form-label">Порядок стадий (по одной на строку)</label>' +
      '<textarea id="set-stage-order" class="input-field settings-textarea" rows="4"></textarea></div>' +
      '<div class="form-row"><label class="form-label">Вероятности стадий (JSON: {"Стадия": 0.5})</label>' +
      '<textarea id="set-stage-probs" class="input-field settings-textarea" rows="4"></textarea></div>' +
      '<div class="form-row"><label class="form-label">SLA по стадиям (JSON: {"Стадия": 14})</label>' +
      '<textarea id="set-sla-days" class="input-field settings-textarea" rows="3"></textarea></div>' +
      '</div></details>' +

      '<section class="settings-section">' +
      '<h3>Агенты</h3>' +
      '<p class="tab-note">Справочник соответствия имён 1С и Битрикс. Агент может быть в нескольких командах.</p>' +
      '<div class="settings-toolbar">' +
      '<input type="search" id="settings-search" class="settings-search-input" placeholder="Поиск: имя, agent_key, алиасы…">' +
      '<div class="settings-filter-radio">' +
      '<label><input type="radio" name="active-filter" value="all" checked> Все</label>' +
      '<label><input type="radio" name="active-filter" value="active"> Активные</label>' +
      '<label><input type="radio" name="active-filter" value="inactive"> Неактивные</label>' +
      '</div></div>' +
      '<div class="settings-actions">' +
      '<button type="button" class="btn-secondary" id="btn-add-agent">Добавить агента</button>' +
      '<button type="button" class="btn-secondary" id="btn-edit-agent">Редактировать</button>' +
      '<button type="button" class="btn-secondary" id="btn-merge-agents">Объединить</button>' +
      '</div>' +
      '<div class="settings-bulk-panel">' +
      '<div class="settings-bulk-header"><strong>Массовые действия</strong> <span id="settings-selected-count" class="settings-selected-count">выбрано: 0</span></div>' +
      '<div class="settings-bulk-grid">' +
      '<div class="settings-bulk-field"><label class="filter-label">Активен</label>' +
      '<div class="settings-bulk-radio">' +
      '<label><input type="radio" name="bulk-active" value="keep" checked> Не менять</label>' +
      '<label><input type="radio" name="bulk-active" value="activate"> Активировать</label>' +
      '<label><input type="radio" name="bulk-active" value="deactivate"> Деактивировать</label>' +
      '</div></div>' +
      '<div class="settings-bulk-field settings-bulk-teams-field">' +
      '<label class="filter-label">Команды</label>' +
      '<select id="bulk-team-mode" class="filter-control">' +
      '<option value="">Не менять</option>' +
      '<option value="replace">Заменить на одну</option>' +
      '<option value="add">Добавить</option>' +
      '<option value="remove">Убрать</option></select>' +
      '<select id="bulk-team" class="filter-control"><option value="">—</option></select></div>' +
      '<div class="settings-bulk-actions">' +
      '<button type="button" class="btn-primary" id="btn-bulk-apply">Применить</button>' +
      '<button type="button" class="btn-danger" id="btn-bulk-delete">Удалить</button>' +
      '</div></div></div>' +
      '<div class="settings-datatable settings-agents-table">' +
      '<table class="settings-table" id="agents-table">' +
      '<thead><tr>' +
      '<th class="col-check"><input type="checkbox" id="agents-select-all" title="Выбрать все на странице"></th>' +
      '<th>Акт.</th><th>Имя</th><th>Команды</th><th>1С</th><th>Битрикс</th><th>agent_key</th><th></th>' +
      '</tr></thead><tbody></tbody></table></div>' +
      '<div class="settings-pagination" id="agents-pagination"></div>' +
      '<p class="tab-note" id="settings-agents-count"></p>' +
      '</section></div>' +

      '<div id="agent-modal" class="modal-overlay hidden">' +
      '<div class="modal-dialog"><div class="modal-header">' +
      '<h3 id="modal-title">Агент</h3><button type="button" class="modal-close-btn" id="modal-close">×</button></div>' +
      '<div class="modal-body">' +
      '<div class="form-row"><label class="form-label">agent_key</label>' +
      '<input type="text" id="modal-agent-key" class="input-field">' +
      '<small class="form-hint">Уникальный ключ (snake_case). У существующего агента не меняется.</small></div>' +
      '<div class="form-row"><label class="form-label">Отображаемое имя</label>' +
      '<input type="text" id="modal-name-display" class="input-field"></div>' +
      '<div class="form-row"><label class="form-label">Команды (первая — основная)</label>' +
      '<div id="modal-teams"></div></div>' +
      '<div class="form-row"><label class="filter-pill"><input type="checkbox" id="modal-is-active" checked>' +
      '<span>Активен</span></label></div>' +
      '<div class="form-row"><label class="form-label">Алиасы 1С (по одному на строку)</label>' +
      '<textarea id="modal-names-1c" class="input-field" rows="4"></textarea></div>' +
      '<div class="form-row"><label class="form-label">Алиасы Битрикс (по одному на строку)</label>' +
      '<textarea id="modal-names-bitrix" class="input-field" rows="4"></textarea></div>' +
      '<div id="modal-validation"></div>' +
      '</div><div class="modal-footer">' +
      '<button type="button" class="btn-secondary" id="modal-cancel">Отмена</button>' +
      '<button type="button" class="btn-primary" id="modal-save">Сохранить</button>' +
      '</div></div></div>' +

      '<div id="teams-picker-modal" class="modal-overlay hidden">' +
      '<div class="modal-dialog modal-dialog-sm"><div class="modal-header">' +
      '<h3>Команды агента</h3><button type="button" class="modal-close-btn" id="teams-picker-close">×</button></div>' +
      '<div class="modal-body"><div id="teams-picker-list"></div><div id="teams-picker-validation"></div></div>' +
      '<div class="modal-footer">' +
      '<button type="button" class="btn-secondary" id="teams-picker-cancel">Отмена</button>' +
      '<button type="button" class="btn-primary" id="teams-picker-save">Сохранить</button>' +
      '</div></div></div>' +

      '<div id="merge-modal" class="modal-overlay hidden">' +
      '<div class="modal-dialog"><div class="modal-header">' +
      '<h3>Объединение профилей</h3><button type="button" class="modal-close-btn" id="merge-close">×</button></div>' +
      '<div class="modal-body">' +
      '<p class="tab-note">Выберите целевой профиль. Его имя, команды и статус сохранятся. Алиасы 1С и Битрикс объединятся. Остальные профили будут удалены.</p>' +
      '<div id="merge-target-list" class="merge-target-list"></div>' +
      '<div id="merge-validation"></div>' +
      '</div><div class="modal-footer">' +
      '<button type="button" class="btn-secondary" id="merge-cancel">Отмена</button>' +
      '<button type="button" class="btn-primary" id="merge-confirm">Объединить</button>' +
      '</div></div></div>';

    document.getElementById('btn-logout').onclick = function () {
      if (self.dirty && !window.confirm('Есть несохранённые изменения. Выйти?')) return;
      self.ctx.setToken('');
      window.TabSettings.render(self.root, self.ctx);
    };
    document.getElementById('btn-save-settings').onclick = function () { self.save(); };
    document.getElementById('btn-reload-settings').onclick = function () { self.reload(); };
    document.getElementById('btn-apply-pipeline').onclick = function () { self.applyPipeline(); };
    document.getElementById('btn-add-team').onclick = function () { self.addTeam(); };
    document.getElementById('btn-add-agent').onclick = function () { self.openModal('add'); };
    document.getElementById('btn-edit-agent').onclick = function () { self.openModal('edit'); };
    document.getElementById('btn-merge-agents').onclick = function () { self.openMergeModal(); };
    document.getElementById('btn-bulk-apply').onclick = function () { self.bulkApply(); };
    document.getElementById('btn-bulk-delete').onclick = function () { self.bulkDelete(); };
    document.getElementById('settings-search').oninput = function (ev) {
      self.search = ev.target.value;
      self.page = 0;
      self.renderAgentsTable();
    };
    Array.prototype.forEach.call(document.querySelectorAll('input[name="active-filter"]'), function (el) {
      el.onchange = function () {
        self.activeFilter = el.value;
        self.page = 0;
        self.renderAgentsTable();
      };
    });
    document.getElementById('set-app-title').oninput = function () { self.markDirty(); };
    document.getElementById('set-default-source').onchange = function () { self.markDirty(); };
    document.getElementById('set-page-size').onchange = function () {
      self.ui.agents_page_size = parseInt(document.getElementById('set-page-size').value, 10) || 25;
      self.page = 0;
      self.markDirty();
      self.renderAgentsTable();
    };
    document.getElementById('set-default-inactive').onchange = function () { self.markDirty(); };
    ['set-plan-total', 'set-stuck-days', 'set-inactive-days', 'set-high-amount', 'set-stage-order', 'set-stage-probs', 'set-sla-days'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.oninput = function () { self.markDirty(); };
    });
    document.getElementById('agents-select-all').onchange = function (ev) {
      self.toggleSelectAllPage(ev.target.checked);
    };
    document.getElementById('modal-close').onclick = function () { self.closeModal(); };
    document.getElementById('modal-cancel').onclick = function () { self.closeModal(); };
    document.getElementById('modal-save').onclick = function () { self.saveModal(); };
    document.getElementById('agent-modal').onclick = function (ev) {
      if (ev.target.id === 'agent-modal') self.closeModal();
    };
    document.getElementById('teams-picker-close').onclick = function () { self.closeTeamsPicker(); };
    document.getElementById('teams-picker-cancel').onclick = function () { self.closeTeamsPicker(); };
    document.getElementById('teams-picker-save').onclick = function () { self.saveTeamsPicker(); };
    document.getElementById('teams-picker-modal').onclick = function (ev) {
      if (ev.target.id === 'teams-picker-modal') self.closeTeamsPicker();
    };
    document.getElementById('merge-close').onclick = function () { self.closeMergeModal(); };
    document.getElementById('merge-cancel').onclick = function () { self.closeMergeModal(); };
    document.getElementById('merge-confirm').onclick = function () { self.confirmMerge(); };
    document.getElementById('merge-modal').onclick = function (ev) {
      if (ev.target.id === 'merge-modal') self.closeMergeModal();
    };
    document.getElementById('new-team-name').addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); self.addTeam(); }
    });
  };

  Editor.prototype.planPeriodKey = function () {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
  };

  Editor.prototype.render = function () {
    document.getElementById('set-app-title').value = this.app.title || '';
    document.getElementById('set-default-source').value = this.defaults.source || 'all';
    document.getElementById('set-default-inactive').checked = !!this.defaults.show_inactive_agents;
    document.getElementById('set-page-size').value = String(this.ui.agents_page_size || 25);
    var period = this.planPeriodKey();
    var plan = this.salesPlans[period] || {};
    document.getElementById('set-plan-total').value = plan.total != null ? plan.total : '';
    document.getElementById('set-stuck-days').value = this.funnel.stuck_days_default != null ? this.funnel.stuck_days_default : 14;
    document.getElementById('set-inactive-days').value = this.funnel.inactive_days_default != null ? this.funnel.inactive_days_default : 7;
    document.getElementById('set-high-amount').value = this.funnel.high_amount_threshold != null ? this.funnel.high_amount_threshold : 500000;
    document.getElementById('set-stage-order').value = (this.funnel.stage_order || []).join('\n');
    document.getElementById('set-stage-probs').value = JSON.stringify(this.funnel.stage_probabilities || {}, null, 2);
    document.getElementById('set-sla-days').value = JSON.stringify(this.funnel.sla_days_by_stage || {}, null, 2);
    document.getElementById('settings-paths-info').innerHTML =
      '<strong>Файлы:</strong> ' + esc(this.paths.input_dir || 'input') + '/' +
      esc(this.paths.file_1c || '1C.xlsx') + ', ' + esc(this.paths.file_bitrix || 'Битрикс.xlsx');
    var badge = document.getElementById('teams-count-badge');
    if (badge) badge.textContent = String(this.teams.length);
    this.syncBulkTeamSelect();
    this.renderTeamsTable();
    this.renderAgentsTable();
  };

  Editor.prototype.syncBulkTeamSelect = function () {
    var sel = document.getElementById('bulk-team');
    var cur = sel.value;
    sel.innerHTML = '<option value="">—</option>' + this.teamOptionsHtml('');
    if (cur) sel.value = cur;
  };

  Editor.prototype.renderTeamsTable = function () {
    var self = this;
    var tbody = document.querySelector('#teams-table tbody');
    var teams = this.teams.slice().sort();
    var badge = document.getElementById('teams-count-badge');
    if (badge) badge.textContent = String(teams.length);
    if (!teams.length) {
      tbody.innerHTML = '<tr><td colspan="3" class="settings-empty">Команд пока нет.</td></tr>';
      return;
    }
    tbody.innerHTML = teams.map(function (team) {
      return '<tr data-team="' + esc(team) + '">' +
        '<td><input type="text" class="input-field team-rename" value="' + esc(team) + '"></td>' +
        '<td>' + self.agentCountByTeam(team) + '</td>' +
        '<td><button type="button" class="btn-danger btn-sm btn-delete-team">×</button></td></tr>';
    }).join('');
    tbody.querySelectorAll('.team-rename').forEach(function (input) {
      input.addEventListener('change', function () { self.renameTeam(input); });
    });
    tbody.querySelectorAll('.btn-delete-team').forEach(function (btn) {
      btn.onclick = function () {
        var tr = btn.closest('tr');
        self.deleteTeam(tr.getAttribute('data-team'));
      };
    });
  };

  Editor.prototype.addTeam = function () {
    var input = document.getElementById('new-team-name');
    var name = input.value.trim();
    if (!name) return;
    if (this.teams.indexOf(name) >= 0) {
      this.showMsg('Команда «' + name + '» уже есть.', true);
      return;
    }
    this.teams.push(name);
    input.value = '';
    this.markDirty();
    this.syncBulkTeamSelect();
    this.renderTeamsTable();
    this.renderAgentsTable();
  };

  Editor.prototype.renameTeam = function (input) {
    var tr = input.closest('tr');
    var oldName = tr.getAttribute('data-team');
    var newName = input.value.trim();
    if (!newName || newName === oldName) {
      input.value = oldName;
      return;
    }
    if (this.teams.indexOf(newName) >= 0) {
      this.showMsg('Команда «' + newName + '» уже существует.', true);
      input.value = oldName;
      return;
    }
    var idx = this.teams.indexOf(oldName);
    if (idx >= 0) this.teams[idx] = newName;
    this.agents.forEach(function (a) {
      var teams = agentTeams(a).map(function (t) { return t === oldName ? newName : t; });
      a.teams = uniqTeams(teams);
      a.team = a.teams[0];
    });
    tr.setAttribute('data-team', newName);
    this.markDirty();
    this.syncBulkTeamSelect();
    this.renderTeamsTable();
    this.renderAgentsTable();
  };

  Editor.prototype.deleteTeam = function (team) {
    var count = this.agentCountByTeam(team);
    if (count > 0) {
      this.showMsg('Нельзя удалить «' + team + '»: привязано агентов — ' + count + '.', true);
      return;
    }
    if (!window.confirm('Удалить команду «' + team + '»?')) return;
    this.teams = this.teams.filter(function (t) { return t !== team; });
    this.markDirty();
    this.syncBulkTeamSelect();
    this.renderTeamsTable();
  };

  Editor.prototype.renderAgentsTable = function () {
    var self = this;
    var filtered = this.filteredAgents();
    var pageSize = parseInt(String(this.ui.agents_page_size || 25), 10);
    var totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
    if (this.page >= totalPages) this.page = totalPages - 1;
    var start = this.page * pageSize;
    var pageRows = filtered.slice(start, start + pageSize);
    var tbody = document.querySelector('#agents-table tbody');

    if (!pageRows.length) {
      tbody.innerHTML = '<tr><td colspan="8" class="settings-empty">Агенты не найдены.</td></tr>';
    } else {
      tbody.innerHTML = pageRows.map(function (a) {
        var inactive = a.is_active ? '' : ' class="agent-inactive"';
        var teams = agentTeams(a);
        return '<tr data-key="' + esc(a.agent_key) + '"' + inactive + '>' +
          '<td class="col-check"><input type="checkbox" class="agent-select"' +
          (self.selected[a.agent_key] ? ' checked' : '') + '></td>' +
          '<td><input type="checkbox" class="agent-active"' + (a.is_active ? ' checked' : '') + '></td>' +
          '<td>' + esc(a.name_display) + '</td>' +
          '<td><button type="button" class="btn-secondary btn-sm btn-teams-edit" title="' + esc(teams.join(', ')) + '">' +
          esc(teamsLabel(teams)) + '</button></td>' +
          '<td class="aliases-cell">' + esc(aliasesPreview(a.names_1c)) + '</td>' +
          '<td class="aliases-cell">' + esc(aliasesPreview(a.names_bitrix)) + '</td>' +
          '<td><code>' + esc(a.agent_key) + '</code></td>' +
          '<td><button type="button" class="btn-secondary btn-sm btn-row-edit">…</button></td></tr>';
      }).join('');
    }

    tbody.querySelectorAll('.agent-select').forEach(function (cb) {
      cb.onchange = function () {
        var key = cb.closest('tr').getAttribute('data-key');
        self.selected[key] = cb.checked;
        self.updateSelectedCount();
        self.updateSelectAllCheckbox(pageRows);
      };
    });
    tbody.querySelectorAll('.agent-active').forEach(function (cb) {
      cb.onchange = function () {
        var key = cb.closest('tr').getAttribute('data-key');
        var agent = self.agents.find(function (x) { return x.agent_key === key; });
        if (agent) {
          agent.is_active = cb.checked;
          self.markDirty();
          self.renderAgentsTable();
        }
      };
    });
    tbody.querySelectorAll('.btn-teams-edit').forEach(function (btn) {
      btn.onclick = function () {
        var key = btn.closest('tr').getAttribute('data-key');
        self.openTeamsPicker(key);
      };
    });
    tbody.querySelectorAll('.btn-row-edit').forEach(function (btn) {
      btn.onclick = function () {
        var key = btn.closest('tr').getAttribute('data-key');
        self.selected = {};
        self.selected[key] = true;
        self.openModal('edit');
      };
    });

    document.getElementById('settings-agents-count').textContent =
      'Показано ' + pageRows.length + ' из ' + filtered.length + ' (всего: ' + this.agents.length + ')';
    this.updateSelectedCount();
    this.updateSelectAllCheckbox(pageRows);
    this.renderPagination(totalPages);
  };

  Editor.prototype.openTeamsPicker = function (agentKey) {
    var agent = this.agents.find(function (a) { return a.agent_key === agentKey; });
    if (!agent) return;
    this.teamsPickerKey = agentKey;
    document.getElementById('teams-picker-list').innerHTML =
      this.teamCheckboxesHtml(agentTeams(agent), 'picker');
    document.getElementById('teams-picker-validation').innerHTML = '';
    document.getElementById('teams-picker-modal').classList.remove('hidden');
  };

  Editor.prototype.closeTeamsPicker = function () {
    document.getElementById('teams-picker-modal').classList.add('hidden');
    this.teamsPickerKey = null;
  };

  Editor.prototype.saveTeamsPicker = function () {
    var agent = this.agents.find(function (a) { return a.agent_key === this.teamsPickerKey; }, this);
    if (!agent) return;
    var teams = this.readTeamCheckboxes(document.getElementById('teams-picker-list'));
    if (!teams.length) {
      document.getElementById('teams-picker-validation').innerHTML =
        '<div class="settings-alert settings-alert-error">Выберите хотя бы одну команду.</div>';
      return;
    }
    agent.teams = teams;
    agent.team = teams[0];
    this.markDirty();
    this.closeTeamsPicker();
    this.renderTeamsTable();
    this.renderAgentsTable();
  };

  Editor.prototype.renderPagination = function (totalPages) {
    var el = document.getElementById('agents-pagination');
    if (totalPages <= 1) {
      el.innerHTML = '';
      return;
    }
    var self = this;
    var html = '<button type="button" class="btn-secondary btn-sm"' + (this.page <= 0 ? ' disabled' : '') + ' data-page="prev">←</button>';
    html += '<span class="settings-page-label">Стр. ' + (this.page + 1) + ' / ' + totalPages + '</span>';
    html += '<button type="button" class="btn-secondary btn-sm"' + (this.page >= totalPages - 1 ? ' disabled' : '') + ' data-page="next">→</button>';
    el.innerHTML = html;
    el.querySelector('[data-page="prev"]').onclick = function () {
      if (self.page > 0) { self.page--; self.renderAgentsTable(); }
    };
    el.querySelector('[data-page="next"]').onclick = function () {
      if (self.page < totalPages - 1) { self.page++; self.renderAgentsTable(); }
    };
  };

  Editor.prototype.updateSelectedCount = function () {
    var n = this.selectedKeys().length;
    document.getElementById('settings-selected-count').textContent = 'выбрано: ' + n;
  };

  Editor.prototype.updateSelectAllCheckbox = function (pageRows) {
    var all = document.getElementById('agents-select-all');
    if (!pageRows.length) {
      all.checked = false;
      all.indeterminate = false;
      return;
    }
    var selectedOnPage = pageRows.filter(function (a) { return this.selected[a.agent_key]; }, this).length;
    all.checked = selectedOnPage === pageRows.length;
    all.indeterminate = selectedOnPage > 0 && selectedOnPage < pageRows.length;
  };

  Editor.prototype.toggleSelectAllPage = function (checked) {
    var filtered = this.filteredAgents();
    var pageSize = parseInt(String(this.ui.agents_page_size || 25), 10);
    var start = this.page * pageSize;
    var pageRows = filtered.slice(start, start + pageSize);
    var self = this;
    pageRows.forEach(function (a) {
      self.selected[a.agent_key] = checked;
    });
    this.renderAgentsTable();
  };

  Editor.prototype.openModal = function (mode) {
    var self = this;
    this.modalMode = mode;
    var modal = document.getElementById('agent-modal');
    var keyInput = document.getElementById('modal-agent-key');
    document.getElementById('modal-validation').innerHTML = '';

    if (mode === 'add') {
      this.modalKey = null;
      document.getElementById('modal-title').textContent = 'Новый агент';
      keyInput.value = '';
      keyInput.disabled = false;
      document.getElementById('modal-name-display').value = '';
      document.getElementById('modal-is-active').checked = true;
      document.getElementById('modal-names-1c').value = '';
      document.getElementById('modal-names-bitrix').value = '';
      document.getElementById('modal-teams').innerHTML =
        this.teamCheckboxesHtml(this.teams.length ? [this.teams[0]] : [], 'modal');
      document.getElementById('modal-name-display').onblur = function () {
        if (!keyInput.value.trim()) {
          var base = slugify(document.getElementById('modal-name-display').value);
          var candidate = base;
          var n = 2;
          while (self.agents.some(function (a) { return a.agent_key === candidate; })) {
            candidate = base + '_' + n++;
          }
          keyInput.value = candidate;
        }
      };
    } else {
      var keys = this.selectedKeys();
      if (keys.length !== 1) {
        this.showMsg('Для редактирования выберите ровно одного агента.', true);
        return;
      }
      var agent = this.agents.find(function (a) { return a.agent_key === keys[0]; });
      if (!agent) return;
      this.modalKey = agent.agent_key;
      document.getElementById('modal-title').textContent = 'Редактирование: ' + agent.name_display;
      keyInput.value = agent.agent_key;
      keyInput.disabled = true;
      document.getElementById('modal-name-display').value = agent.name_display || '';
      document.getElementById('modal-teams').innerHTML = this.teamCheckboxesHtml(agentTeams(agent), 'modal');
      document.getElementById('modal-is-active').checked = !!agent.is_active;
      document.getElementById('modal-names-1c').value = (agent.names_1c || []).join('\n');
      document.getElementById('modal-names-bitrix').value = (agent.names_bitrix || []).join('\n');
    }
    modal.classList.remove('hidden');
  };

  Editor.prototype.closeModal = function () {
    document.getElementById('agent-modal').classList.add('hidden');
  };

  Editor.prototype.saveModal = function () {
    var key = document.getElementById('modal-agent-key').value.trim();
    var name = document.getElementById('modal-name-display').value.trim();
    var teams = this.readTeamCheckboxes(document.getElementById('modal-teams'));
    var isActive = document.getElementById('modal-is-active').checked;
    var names1c = parseAliases(document.getElementById('modal-names-1c').value);
    var namesBitrix = parseAliases(document.getElementById('modal-names-bitrix').value);
    var errors = [];
    if (!key) errors.push('Укажите agent_key');
    if (!name) errors.push('Укажите отображаемое имя');
    if (!teams.length) errors.push('Выберите хотя бы одну команду');
    if (this.modalMode === 'add' && this.agents.some(function (a) { return a.agent_key === key; })) {
      errors.push('agent_key уже занят');
    }
    if (errors.length) {
      document.getElementById('modal-validation').innerHTML =
        '<div class="settings-alert settings-alert-error">' + errors.join('<br>') + '</div>';
      return;
    }
    var payload = normalizeAgent({
      agent_key: key,
      name_display: name,
      teams: teams,
      team: teams[0],
      is_active: isActive,
      names_1c: names1c,
      names_bitrix: namesBitrix,
    });
    if (this.modalMode === 'add') {
      this.agents.push(payload);
      this.selected = {};
      this.selected[key] = true;
    } else {
      var idx = this.agents.findIndex(function (a) { return a.agent_key === this.modalKey; }, this);
      if (idx >= 0) this.agents[idx] = payload;
    }
    this.markDirty();
    this.closeModal();
    this.renderTeamsTable();
    this.renderAgentsTable();
  };

  Editor.prototype.openMergeModal = function () {
    var keys = this.selectedKeys();
    if (keys.length < 2) {
      this.showMsg('Для объединения выберите минимум двух агентов.', true);
      return;
    }
    var self = this;
    var list = document.getElementById('merge-target-list');
    list.innerHTML = keys.map(function (key) {
      var agent = self.agents.find(function (a) { return a.agent_key === key; });
      if (!agent) return '';
      return '<label class="merge-target-item filter-pill">' +
        '<input type="radio" name="merge-target" value="' + esc(key) + '"' +
        (keys[0] === key ? ' checked' : '') + '>' +
        '<span><strong>' + esc(agent.name_display) + '</strong> ' +
        '<code>' + esc(key) + '</code> · ' + esc(teamsLabel(agentTeams(agent))) + '</span></label>';
    }).join('');
    document.getElementById('merge-validation').innerHTML = '';
    document.getElementById('merge-modal').classList.remove('hidden');
  };

  Editor.prototype.closeMergeModal = function () {
    document.getElementById('merge-modal').classList.add('hidden');
  };

  Editor.prototype.confirmMerge = function () {
    var keys = this.selectedKeys();
    var targetInput = document.querySelector('input[name="merge-target"]:checked');
    if (!targetInput) {
      document.getElementById('merge-validation').innerHTML =
        '<div class="settings-alert settings-alert-error">Выберите целевой профиль.</div>';
      return;
    }
    var targetKey = targetInput.value;
    var target = this.agents.find(function (a) { return a.agent_key === targetKey; });
    if (!target) return;
    var others = keys.filter(function (k) { return k !== targetKey; });
    var names1c = unionAliases([target.names_1c].concat(
      others.map(function (k) {
        var a = this.agents.find(function (x) { return x.agent_key === k; });
        return a ? a.names_1c : [];
      }, this)
    ));
    var namesBitrix = unionAliases([target.names_bitrix].concat(
      others.map(function (k) {
        var a = this.agents.find(function (x) { return x.agent_key === k; });
        return a ? a.names_bitrix : [];
      }, this)
    ));
    target.names_1c = names1c;
    target.names_bitrix = namesBitrix;
    normalizeAgent(target);
    var removeSet = {};
    others.forEach(function (k) { removeSet[k] = true; });
    this.agents = this.agents.filter(function (a) { return !removeSet[a.agent_key]; });
    this.selected = {};
    this.selected[targetKey] = true;
    this.markDirty();
    this.closeMergeModal();
    this.renderTeamsTable();
    this.renderAgentsTable();
    this.showMsg('Объединено в «' + target.name_display + '». Не забудьте сохранить.', false);
  };

  Editor.prototype.bulkApply = function () {
    var keys = this.selectedKeys();
    if (!keys.length) {
      this.showMsg('Выберите хотя бы одного агента.', true);
      return;
    }
    var activeAction = document.querySelector('input[name="bulk-active"]:checked').value;
    var teamMode = document.getElementById('bulk-team-mode').value;
    var team = document.getElementById('bulk-team').value;
    if (teamMode && !team) {
      this.showMsg('Выберите команду для массовой операции.', true);
      return;
    }
    var self = this;
    keys.forEach(function (key) {
      var agent = self.agents.find(function (a) { return a.agent_key === key; });
      if (!agent) return;
      if (activeAction === 'activate') agent.is_active = true;
      if (activeAction === 'deactivate') agent.is_active = false;
      if (teamMode === 'replace') {
        agent.teams = [team];
        agent.team = team;
      } else if (teamMode === 'add') {
        var teams = agentTeams(agent);
        if (teams.indexOf(team) < 0) teams.push(team);
        agent.teams = uniqTeams(teams);
        agent.team = agent.teams[0];
      } else if (teamMode === 'remove') {
        var filtered = agentTeams(agent).filter(function (t) { return t !== team; });
        agent.teams = filtered.length ? filtered : ['Без команды'];
        agent.team = agent.teams[0];
      }
    });
    this.markDirty();
    this.renderTeamsTable();
    this.renderAgentsTable();
    this.showMsg('Изменения применены к ' + keys.length + ' агентам. Не забудьте сохранить.', false);
  };

  Editor.prototype.bulkDelete = function () {
    var keys = this.selectedKeys();
    if (!keys.length) {
      this.showMsg('Выберите агентов для удаления.', true);
      return;
    }
    if (!window.confirm('Удалить выбранных агентов (' + keys.length + ')?')) return;
    var set = {};
    keys.forEach(function (k) { set[k] = true; });
    this.agents = this.agents.filter(function (a) { return !set[a.agent_key]; });
    this.selected = {};
    this.markDirty();
    this.renderTeamsTable();
    this.renderAgentsTable();
  };

  Editor.prototype.collectPayload = function () {
    var agents = this.agents.map(function (a) {
      var teams = agentTeams(a);
      return {
        agent_key: a.agent_key,
        name_display: a.name_display,
        teams: teams,
        team: teams[0],
        is_active: !!a.is_active,
        names_1c: a.names_1c || [],
        names_bitrix: a.names_bitrix || [],
      };
    });
    var period = this.planPeriodKey();
    var planTotal = parseFloat(document.getElementById('set-plan-total').value) || 0;
    var salesPlans = Object.assign({}, this.salesPlans);
    salesPlans[period] = Object.assign({}, salesPlans[period] || {}, { total: planTotal, by_team: (salesPlans[period] || {}).by_team || {}, by_agent: (salesPlans[period] || {}).by_agent || {} });
    var stageOrder = String(document.getElementById('set-stage-order').value || '').split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean);
    var stageProbs = {};
    var slaDays = {};
    try { stageProbs = JSON.parse(document.getElementById('set-stage-probs').value || '{}'); } catch (e) { stageProbs = this.funnel.stage_probabilities || {}; }
    try { slaDays = JSON.parse(document.getElementById('set-sla-days').value || '{}'); } catch (e) { slaDays = this.funnel.sla_days_by_stage || {}; }
    var funnel = Object.assign({}, this.funnel, {
      stage_order: stageOrder,
      stage_probabilities: stageProbs,
      sla_days_by_stage: slaDays,
      stuck_days_default: parseInt(document.getElementById('set-stuck-days').value, 10) || 14,
      inactive_days_default: parseInt(document.getElementById('set-inactive-days').value, 10) || 7,
      high_amount_threshold: parseFloat(document.getElementById('set-high-amount').value) || 500000,
    });
    return {
      agents: agents,
      teams: this.teams.slice().sort(),
      dismissed_agent_warnings: this.dismissed,
      app: { title: document.getElementById('set-app-title').value.trim() },
      defaults: {
        source: document.getElementById('set-default-source').value,
        show_inactive_agents: document.getElementById('set-default-inactive').checked,
      },
      ui: { agents_page_size: parseInt(document.getElementById('set-page-size').value, 10) || 25 },
      funnel: funnel,
      sales_plans: salesPlans,
    };
  };

  Editor.prototype.showMsg = function (text, isError) {
    document.getElementById('set-msg').innerHTML =
      '<div class="settings-alert settings-alert-' + (isError ? 'error' : 'success') + '">' + esc(text) + '</div>';
  };

  Editor.prototype.save = function () {
    var self = this;
    var payload = this.collectPayload();
    fetch('api/settings.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Settings-Token': this.token,
      },
      body: JSON.stringify(payload),
    })
      .then(function (res) { return res.json().then(function (data) { return { res: res, data: data }; }); })
      .then(function (pack) {
        if (pack.res.status === 401) {
          self.ctx.setToken('');
          window.TabSettings.render(self.root, self.ctx);
          return;
        }
        if (!pack.data.ok) {
          var err = pack.data.error || (pack.data.errors || []).join('; ');
          throw new Error(err);
        }
        self.dirty = false;
        document.getElementById('settings-dirty').classList.add('hidden');
        self.showMsg(pack.data.message || 'Сохранено.', false);
        if (payload.app.title) {
          var titleEl = document.querySelector('.sidebar-title');
          if (titleEl) titleEl.textContent = payload.app.title;
        }
      })
      .catch(function (e) { self.showMsg(e.message, true); });
  };

  Editor.prototype.reload = function () {
    if (this.dirty && !window.confirm('Несохранённые изменения будут потеряны. Продолжить?')) return;
    window.TabSettings.render(this.root, this.ctx);
  };

  Editor.prototype.applyPipeline = function () {
    var self = this;
    if (window.AppSetPipelineLoading) {
      window.AppSetPipelineLoading(true);
    }
    this.ctx.api('api/pipeline.php', {})
      .then(function (p) {
        self.showMsg('Данные пересобраны. Unified: ' + (p.meta.counts.sales_unified || 0), false);
      })
      .catch(function (e) { self.showMsg(e.message, true); })
      .finally(function () {
        if (window.AppSetPipelineLoading) {
          window.AppSetPipelineLoading(false);
        }
      });
  };

  return {
    mount: function (root, ctx, data) {
      return new Editor(root, ctx, data);
    },
  };
})();
