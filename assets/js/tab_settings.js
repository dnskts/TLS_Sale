/**
 * tab_settings.js
 *
 * Страница настроек: вход (admin/95123) и UI-редактор справочника.
 */
window.TabSettings = {
  async render(root, ctx) {
    var token = ctx.getToken();
    if (!token) {
      root.innerHTML =
        '<div class="settings-auth-tab"><h2>Настройки</h2>' +
        '<div class="settings-form settings-auth-panel">' +
        '<p class="tab-note">Введите логин и пароль для доступа к справочнику.</p>' +
        '<label class="form-label">Логин</label><input id="auth-login" class="input-field" type="text">' +
        '<label class="form-label">Пароль</label><input id="auth-password" class="input-field" type="password">' +
        '<div id="auth-msg"></div>' +
        '<button type="button" class="btn-primary" id="btn-auth">Войти</button>' +
        '</div></div>';
      document.getElementById('btn-auth').onclick = async function () {
        try {
          var res = await fetch('api/auth.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              action: 'login',
              login: document.getElementById('auth-login').value,
              password: document.getElementById('auth-password').value,
            }),
          });
          var data = await res.json();
          if (!data.ok) throw new Error(data.error || 'Ошибка входа');
          ctx.setToken(data.token);
          await window.TabSettings.render(root, ctx);
        } catch (e) {
          document.getElementById('auth-msg').innerHTML =
            '<div class="settings-alert settings-alert-error">' + e.message + '</div>';
        }
      };
      return;
    }

    root.innerHTML = '<p class="tab-note">Загрузка настроек…</p>';

    try {
      var res = await fetch('api/settings.php', { headers: { 'X-Settings-Token': token } });
      var data = await res.json();
      if (!res.ok || data.ok === false) {
        throw new Error(data.error || ('Ошибка HTTP ' + res.status));
      }
      window.SettingsEditor.mount(root, ctx, data);
    } catch (e) {
      root.innerHTML =
        '<div class="settings-alert settings-alert-error">' + e.message + '</div>' +
        '<button type="button" class="btn-secondary" id="btn-logout">Выйти</button>';
      document.getElementById('btn-logout').onclick = function () {
        ctx.setToken('');
        window.TabSettings.render(root, ctx);
      };
    }
  },
};
