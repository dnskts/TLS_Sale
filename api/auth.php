<?php
/**
 * api/auth.php — вход / проверка токена страницы «Настройки».
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('auth.php');

$body = read_json_body();
$action = $body['action'] ?? $_GET['action'] ?? 'login';

if ($action === 'check') {
    json_response(['ok' => true, 'authenticated' => settings_auth_ok()]);
}

if ($action === 'login') {
    $creds = settings_credentials();
    $login = trim((string) ($body['login'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    if ($login === $creds['login'] && $password === $creds['password']) {
        json_response(['ok' => true, 'token' => settings_auth_token()]);
    }
    json_response(['ok' => false, 'error' => 'Неверный логин или пароль'], 401);
}

json_response(['ok' => false, 'error' => 'Неизвестное действие'], 400);
