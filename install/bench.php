<?php
/**
 * install/bench.php
 *
 * Анализ производительности API на проде.
 * Доступ: ?token=XXXXXXXXXXXXXXXX (первые 16 символов settings_auth_token).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('bench.php');

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';

if (!bench_token_ok($token)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    $hint = htmlspecialchars(bench_access_token(), ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Доступ запрещён</title></head><body>';
    echo '<h1>403 — нужен token</h1>';
    echo '<p>Откройте: <code>install/bench.php?token=' . $hint . '</code></p>';
    echo '<p>Token = первые 16 символов <code>settings_auth_token()</code> (тот же доступ, что у настроек).</p>';
    echo '<p><a href="../index.php">← К дашборду</a></p></body></html>';
    exit;
}

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(bench_run_full_report(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$report = bench_run_full_report();
$env = $report['environment'];
$meta = $env['meta'] ?? [];
$counts = $meta['counts'] ?? [];
$tokenEsc = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Анализ производительности — TLS Sale</title>
    <style>
        body { font-family: Helvetica Neue, Arial, sans-serif; max-width: 960px; margin: 40px auto; color: #535c69; padding: 0 16px; }
        h1 { color: #2067b0; }
        h2 { color: #2067b0; font-size: 1.1rem; margin-top: 28px; }
        .ok { color: #4a6b1f; }
        .warn { color: #7a6a2a; }
        .bad { color: #991b1b; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        td, th { border-bottom: 1px solid #e6e9ec; padding: 8px; text-align: left; vertical-align: top; }
        th { font-size: 0.85rem; color: #828b95; }
        .box { background: #eef2f4; padding: 12px 16px; border-radius: 6px; margin-top: 16px; }
        .box-warn { background: #fff8e6; border: 1px solid #e6d9a8; }
        .meta { font-size: 0.9rem; color: #828b95; }
        ul.tips { margin: 8px 0 0 1.2rem; }
        code { background: #f4f6f7; padding: 1px 4px; border-radius: 3px; font-size: 0.9em; }
    </style>
</head>
<body>
<h1>Анализ производительности</h1>
<p class="meta">Сгенерировано: <?= htmlspecialchars($report['generated_at']) ?> · Фильтры: <?= htmlspecialchars(($report['filters']['date_from'] ?? '') . ' — ' . ($report['filters']['date_to'] ?? '')) ?></p>

<div class="box box-warn">
    <strong>Внимание:</strong> диагностическая страница. Не оставляйте ссылку с token публично после проверки.
    <a href="?token=<?= $tokenEsc ?>&amp;refresh=1">Повторить замеры</a>
    · <a href="?token=<?= $tokenEsc ?>&amp;format=json">JSON</a>
</div>

<h2>Окружение</h2>
<table>
    <tr><th>Параметр</th><th>Значение</th></tr>
    <tr><td>PHP</td><td><?= htmlspecialchars($env['php_version']) ?></td></tr>
    <tr><td>Хранилище</td><td><strong><?= htmlspecialchars($env['storage_backend']) ?></strong></td></tr>
    <tr><td>memory_limit</td><td><?= htmlspecialchars((string) $env['memory_limit']) ?></td></tr>
    <tr><td>OPcache</td><td><?= htmlspecialchars((string) $env['opcache']) ?></td></tr>
    <tr><td>Данные загружены</td><td><?= !empty($meta['loaded_at']) ? htmlspecialchars(date('d.m.Y H:i', strtotime((string) $meta['loaded_at']) ?: time())) : '<span class="bad">нет</span>' ?></td></tr>
    <tr><td>Строки</td><td>
        1С: <?= (int) ($counts['operations_1c'] ?? 0) ?> ·
        Битрикс: <?= (int) ($counts['deals_bitrix'] ?? 0) ?> ·
        Unified: <?= (int) ($counts['sales_unified'] ?? 0) ?>
    </td></tr>
</table>

<h2>Размеры файлов</h2>
<table>
    <tr><th>Файл</th><th>Размер</th><th>Путь</th></tr>
    <?php foreach ($env['files'] as $name => $info): ?>
        <tr>
            <td><?= htmlspecialchars($name) ?></td>
            <td><?= $info['exists'] ? htmlspecialchars($info['size_human']) : '<span class="bad">нет</span>' ?></td>
            <td class="meta"><?= htmlspecialchars((string) $info['path']) ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<h2>Загрузка таблиц (по одной)</h2>
<table>
    <tr><th>Таблица</th><th>Время</th><th>Строк</th><th>Память Δ</th><th>Примечание</th></tr>
    <?php foreach ($report['table_loads'] as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['table']) ?></td>
            <td class="<?= htmlspecialchars($row['ms_class']) ?>"><?= htmlspecialchars((string) $row['ms']) ?> ms</td>
            <td><?= (int) $row['rows'] ?></td>
            <td><?= htmlspecialchars((string) $row['memory_delta']) ?></td>
            <td class="meta"><?= htmlspecialchars((string) ($row['note'] ?? '')) ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<h2>Сценарии API (как при работе дашборда)</h2>
<table>
    <tr><th>Сценарий</th><th>Описание</th><th>Время</th><th>Пик RAM</th><th>Загрузок</th><th>Таблицы</th></tr>
    <?php foreach ($report['scenarios'] as $s): ?>
        <tr>
            <td><code><?= htmlspecialchars($s['label']) ?></code></td>
            <td class="meta"><?= htmlspecialchars($s['desc']) ?></td>
            <td class="<?= htmlspecialchars($s['ms_class']) ?>"><strong><?= htmlspecialchars((string) $s['ms']) ?> ms</strong></td>
            <td><?= htmlspecialchars((string) $s['peak_mb']) ?> MB</td>
            <td><?= (int) $s['load_total'] ?></td>
            <td class="meta"><?= htmlspecialchars($s['load_detail']) ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<h2>Рекомендации</h2>
<div class="box">
    <ul class="tips">
        <?php foreach ($report['recommendations'] as $tip): ?>
            <li><?= htmlspecialchars($tip) ?></li>
        <?php endforeach; ?>
    </ul>
</div>

<p><a href="../index.php">← К дашборду</a> · <a href="check.php">Проверка окружения</a></p>
</body>
</html>
