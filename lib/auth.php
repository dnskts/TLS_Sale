<?php
/**
 * auth.php
 *
 * Простая проверка доступа к настройкам.
 * Логин/пароль: admin / 95123 (см. settings_credentials).
 *
 * Браузер хранит флаг в localStorage, а сервер дополнительно
 * проверяет заголовок X-Settings-Token при сохранении.
 */

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

/** Токен, который клиент присылает после успешного входа. */
function settings_auth_token(): string
{
    $c = settings_credentials();
    // Не «секрет банка», а простой барьер для локального дашборда
    return hash('sha256', $c['login'] . ':' . $c['password'] . ':tls_sale_settings');
}

function settings_auth_ok(): bool
{
    $token = $_SERVER['HTTP_X_SETTINGS_TOKEN'] ?? '';
    return hash_equals(settings_auth_token(), (string) $token);
}

function require_settings_auth(): void
{
    if (!settings_auth_ok()) {
        json_response(['ok' => false, 'error' => 'Нужна авторизация настроек'], 401);
    }
}
