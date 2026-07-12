<?php
declare(strict_types=1);

$settingsPath = __DIR__ . DIRECTORY_SEPARATOR . 'settings.json';

if (!is_readable($settingsPath)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Ошибка</title></head><body>';
    echo '<h1>Не найден settings.json</h1>';
    echo '<p>Разместите файл настроек в корне проекта.</p></body></html>';
    exit;
}

$settings = json_decode((string) file_get_contents($settingsPath), true, 512, JSON_THROW_ON_ERROR);
$host = $settings['app']['host'] ?? '127.0.0.1';
$port = (int) ($settings['app']['port'] ?? 8050);
$dashUrl = $settings['app']['dash_url'] ?? ('http://127.0.0.1:' . $port);
$title = $settings['app']['title'] ?? 'Дашборд продаж РС ТЛС';

$checkHost = ($host === '0.0.0.0' || $host === '::') ? '127.0.0.1' : $host;
$socket = @fsockopen($checkHost, $port, $errno, $errstr, 0.3);

if ($socket !== false) {
    fclose($socket);
    header('Location: ' . $dashUrl, true, 302);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <style>
        body { font-family: Segoe UI, Arial, sans-serif; max-width: 720px; margin: 40px auto; line-height: 1.5; color: #222; }
        h1 { font-size: 1.6rem; }
        code, pre { background: #f4f4f4; padding: 2px 6px; border-radius: 4px; }
        pre { padding: 12px; overflow-x: auto; }
        ol li { margin-bottom: 8px; }
    </style>
</head>
<body>
    <h1><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
    <p>Сервис Dash сейчас не запущен на порту <code><?= (int) $port ?></code>.</p>

    <h2>Как запустить</h2>
    <ol>
        <li>Поместите выгрузки <code>1C.xlsx</code> и <code>Битрикс.xlsx</code> в папку <code>input/</code>.</li>
        <li>Активируйте виртуальное окружение Python и установите зависимости:
            <pre>python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt</pre>
        </li>
        <li>Запустите загрузку данных:
            <pre>python -m parser.pipeline</pre>
        </li>
        <li>Запустите дашборд:
            <pre>python -m app.main</pre>
        </li>
        <li>Откройте в браузере <a href="<?= htmlspecialchars($dashUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($dashUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a> или обновите эту страницу — произойдёт автоматический переход.</li>
    </ol>

    <h2>Настройки</h2>
    <p>Справочник агентов и параметры метрик задаются в <code>settings.json</code> (ключ <code>agents</code>). Excel-справочник не используется.</p>
    <p>После правки <code>agents</code> выполните <code>python -m parser.pipeline</code> или нажмите «Обновить данные» в дашборде.</p>
    <p>Спецификация полей выгрузок: <code>format_spec.txt</code>.</p>

    <p><small>Работа в закрытой сети, без авторизации. Доступ по LAN на хосте сервера.</small></p>
</body>
</html>
