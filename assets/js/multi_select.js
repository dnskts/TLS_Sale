/**
 * multi_select.js
 *
 * Простой выпадающий список с множественным выбором (чекбоксы).
 * Опция searchable — поле поиска внутри панели.
 */
window.MultiSelect = (function () {
  var openInstance = null;

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function MultiSelect(container, config) {
    this.container = container;
    this.id = config.id || '';
    this.label = config.label || '';
    this.placeholder = config.placeholder || 'Все';
    this.options = config.options || [];
    this.selected = (config.values || []).slice();
    this.searchable = !!config.searchable;
    this.searchQuery = '';
    this._build();
  }

  MultiSelect.prototype._build = function () {
    var self = this;
    var searchHtml = this.searchable
      ? '<input type="search" class="ms-search" placeholder="Поиск…" autocomplete="off">'
      : '';
    this.container.innerHTML =
      '<div class="filter-item ms-root" data-ms-id="' + escapeHtml(this.id) + '">' +
      (this.label ? '<label class="filter-label">' + escapeHtml(this.label) + '</label>' : '') +
      '<button type="button" class="ms-trigger" aria-haspopup="listbox">' +
      '<span class="ms-trigger-text">' + escapeHtml(this.placeholder) + '</span>' +
      '<span class="ms-chevron">▾</span></button>' +
      '<div class="ms-panel hidden" role="listbox">' +
      '<div class="ms-toolbar">' +
      searchHtml +
      '<button type="button" class="ms-link" data-action="all">Выбрать все</button>' +
      '<button type="button" class="ms-link" data-action="none">Сбросить</button>' +
      '</div>' +
      '<div class="ms-options"></div>' +
      '</div></div>';

    this.root = this.container.querySelector('.ms-root');
    this.trigger = this.container.querySelector('.ms-trigger');
    this.triggerText = this.container.querySelector('.ms-trigger-text');
    this.panel = this.container.querySelector('.ms-panel');
    this.optionsEl = this.container.querySelector('.ms-options');
    this.searchInput = this.container.querySelector('.ms-search');

    this.trigger.addEventListener('click', function (ev) {
      ev.stopPropagation();
      self.toggle();
    });

    this.panel.querySelector('[data-action="all"]').addEventListener('click', function (ev) {
      ev.stopPropagation();
      self.selectAll();
    });
    this.panel.querySelector('[data-action="none"]').addEventListener('click', function (ev) {
      ev.stopPropagation();
      self.clear();
    });

    if (this.searchInput) {
      this.searchInput.addEventListener('input', function (ev) {
        self.searchQuery = ev.target.value.toLowerCase().trim();
        self._renderOptions();
      });
      this.searchInput.addEventListener('click', function (ev) {
        ev.stopPropagation();
      });
    }

    this._renderOptions();
    this._updateTrigger();
  };

  MultiSelect.prototype._renderOptions = function () {
    var self = this;
    this.optionsEl.innerHTML = '';
    var q = this.searchQuery;
    var visible = this.options.filter(function (opt) {
      if (!q) return true;
      return String(opt.label || opt.value || '').toLowerCase().indexOf(q) >= 0;
    });
    if (!visible.length) {
      this.optionsEl.innerHTML = '<div class="ms-empty">Ничего не найдено</div>';
      return;
    }
    visible.forEach(function (opt) {
      var row = document.createElement('label');
      row.className = 'ms-option';
      var checked = self.selected.indexOf(opt.value) >= 0;
      row.innerHTML =
        '<input type="checkbox" value="' + escapeHtml(opt.value) + '"' + (checked ? ' checked' : '') + '>' +
        '<span>' + escapeHtml(opt.label) + '</span>';
      row.querySelector('input').addEventListener('change', function () {
        self._syncFromDom();
        self._updateTrigger();
      });
      self.optionsEl.appendChild(row);
    });
  };

  MultiSelect.prototype._syncFromDom = function () {
    var boxes = this.optionsEl.querySelectorAll('input[type="checkbox"]');
    var visibleValues = [];
    Array.prototype.forEach.call(boxes, function (cb) {
      visibleValues.push(cb.value);
    });
    var kept = this.selected.filter(function (v) {
      return visibleValues.indexOf(v) < 0;
    });
    this.selected = kept;
    Array.prototype.forEach.call(boxes, function (cb) {
      if (cb.checked) this.selected.push(cb.value);
    }, this);
  };

  MultiSelect.prototype._updateTrigger = function () {
    var n = this.selected.length;
    if (n === 0) {
      this.triggerText.textContent = this.placeholder;
    } else if (n === 1) {
      var found = this.options.find(function (o) { return o.value === this.selected[0]; }, this);
      this.triggerText.textContent = found ? found.label : 'Выбрано: 1';
    } else {
      this.triggerText.textContent = 'Выбрано: ' + n;
    }
  };

  MultiSelect.prototype.open = function () {
    var self = this;
    if (openInstance && openInstance !== this) {
      openInstance.close();
    }
    openInstance = this;
    this.panel.classList.remove('hidden');
    this.trigger.classList.add('ms-open');
    if (this.searchInput) {
      this.searchInput.value = '';
      this.searchQuery = '';
      this._renderOptions();
      setTimeout(function () { self.searchInput.focus(); }, 0);
    }
  };

  MultiSelect.prototype.close = function () {
    this.panel.classList.add('hidden');
    this.trigger.classList.remove('ms-open');
    if (openInstance === this) {
      openInstance = null;
    }
  };

  MultiSelect.prototype.toggle = function () {
    if (this.panel.classList.contains('hidden')) {
      this.open();
    } else {
      this.close();
    }
  };

  MultiSelect.prototype.getValues = function () {
    return this.selected.slice();
  };

  MultiSelect.prototype.setValues = function (values) {
    this.selected = (values || []).slice();
    this._renderOptions();
    this._updateTrigger();
  };

  MultiSelect.prototype.setOptions = function (options, keepSelection) {
    var prev = keepSelection ? this.selected.slice() : [];
    this.options = options || [];
    if (keepSelection) {
      var allowed = {};
      this.options.forEach(function (o) { allowed[o.value] = true; });
      this.selected = prev.filter(function (v) { return allowed[v]; });
    } else {
      this.selected = [];
    }
    this._renderOptions();
    this._updateTrigger();
  };

  MultiSelect.prototype.selectAll = function () {
    var q = this.searchQuery;
    var self = this;
    this.options.forEach(function (opt) {
      if (!q || String(opt.label || '').toLowerCase().indexOf(q) >= 0) {
        if (self.selected.indexOf(opt.value) < 0) {
          self.selected.push(opt.value);
        }
      }
    });
    this._renderOptions();
    this._updateTrigger();
  };

  MultiSelect.prototype.clear = function () {
    this.selected = [];
    this._renderOptions();
    this._updateTrigger();
  };

  document.addEventListener('click', function () {
    if (openInstance) {
      openInstance.close();
    }
  });

  return {
    mount: function (containerEl, config) {
      return new MultiSelect(containerEl, config);
    },
  };
})();
