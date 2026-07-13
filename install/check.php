<?php
/**
 * install/check.php
 *
 * Страница проверки окружения сервера.
 * Откройте её первой на хостинге Битрикс24 — увидите,
 * чего не хватает и какое хранилище будет использовано.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('storage.php');

$checks = [];
$checks[] = ['name' => 'Версия PHP (≥ 7.4)', 'ok' => version_compare(PHP_VERSION, '7.4.0', '>='), 'detail' => PHP_VERSION, 'optional' => false];
$checks[] = ['name' => 'json', 'ok' => extension_loaded('json'), 'detail' => '', 'optional' => false];
$checks[] = ['name' => 'mbstring', 'ok' => extension_loaded('mbstring'), 'detail' => '', 'optional' => false];
$checks[] = ['name' => 'zip (для xlsx)', 'ok' => extension_loaded('zip'), 'detail' => '', 'optional' => false];
$checks[] = ['name' => 'pdo_sqlite (опционально)', 'ok' => extension_loaded('pdo_sqlite'), 'detail' => 'для SQLite-хранилища', 'optional' => true];
$checks[] = ['name' => 'pdo_mysql (опционально)', 'ok' => extension_loaded('pdo_mysql'), 'detail' => 'резерв на будущее', 'optional' => true];

$dataDir = project_root() . DIRECTORY_SEPARATOR . 'data';
$inputDir = project_root() . DIRECTORY_SEPARATOR . 'input';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0775, true);
}
if (!is_dir($inputDir)) {
    @mkdir($inputDir, 0775, true);
}
$checks[] = ['name' => 'Папка data/ writable', 'ok' => is_writable($dataDir), 'detail' => $dataDir, 'optional' => false];
$checks[] = ['name' => 'Папка input/ writable', 'ok' => is_writable($inputDir), 'detail' => $inputDir, 'optional' => false];

$backend = storage_backend();
?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Проверка окружения — TLS Sale</title>
    <style>
        body { font-family: Helvetica Neue, Arial, sans-serif; max-width: 720px; margin: 40px auto; color: #535c69; }
        h1 { color: #2067b0; }
        .ok { color: #4a6b1f; }
        .bad { color: #991b1b; }
        .opt { color: #7a6a2a; }
        table { width: 100%; border-collapse: collapse; }
        td, th { border-bottom: 1px solid #e6e9ec; padding: 8px; text-align: left; }
        .box { background: #eef2f4; padding: 12px 16px; border-radius: 6px; margin-top: 20px; }
    </style>
</head>
<body>
<h1>Проверка окружения</h1>
<p>Эта страница помогает понять, запустится ли дашборд на сервере Битрикс24.</p>
<table>
    <tr><th>Проверка</th><th>Статус</th><th>Детали</th></tr>
    <?php foreach ($checks as $c):
        $optional = !empty($c['optional']);
        if ($c['ok']) {
            $statusClass = 'ok';
            $statusText = 'OK';
        } elseif ($optional) {
            $statusClass = 'opt';
            $statusText = '—';
        } else {
            $statusClass = 'bad';
            $statusText = 'НЕТ';
        }
    ?>
        <tr>
            <td><?= htmlspecialchars($c['name']) ?></td>
            <td class="<?= $statusClass ?>"><?= $statusText ?></td>
            <td><?= htmlspecialchars((string) $c['detail']) ?></td>
        </tr>
    <?php endforeach; ?>
</table>
<div class="box">
    <strong>Выбранное хранилище:</strong> <?= htmlspecialchars($backend) ?><br>
    <small>sqlite — предпочтительно; json — запасной вариант без PDO SQLite.</small>
</div>
<p><a href="../index.php">← К дашборду</a></p>
</body>
</html>
