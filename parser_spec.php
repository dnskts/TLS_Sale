<?php
/**
 * parser_spec.php — справочная страница: как парсятся колонки 1С, Битрикс и unified.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_lib('parser_spec.php');

$oneC = parser_spec_one_c();
$bitrix = parser_spec_bitrix();
$unified = parser_spec_unified();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Логика парсера — TLS Sale</title>
    <link rel="stylesheet" href="assets/styles.css">
    <link rel="stylesheet" href="assets/php-app.css">
</head>
<body class="parser-spec-page">
<div class="parser-spec-wrap">
    <header class="parser-spec-header">
        <h1>Логика парсера</h1>
        <p class="tab-note">Соответствие колонок Excel и полей в unified-таблице. Синхронизировано с lib/parse_1c.php, lib/parse_bitrix.php, lib/build_unified.php.</p>
        <a href="index.php" class="btn-secondary">← К дашборду</a>
    </header>

    <section class="parser-spec-section">
        <h2>1С — колонки по индексу</h2>
        <p class="tab-note">Первая строка Excel пропускается. Колонки читаются по номеру, не по названию шапки.</p>
        <div class="parser-spec-table-wrap">
            <table class="settings-table parser-spec-table">
                <thead>
                <tr><th>#</th><th>Поле в коде</th><th>Описание</th><th>Примечание</th></tr>
                </thead>
                <tbody>
                <?php foreach ($oneC as $row): ?>
                    <tr>
                        <td><?= (int) $row['index'] ?></td>
                        <td><code><?= htmlspecialchars($row['field']) ?></code></td>
                        <td><?= htmlspecialchars($row['label']) ?></td>
                        <td class="parser-spec-note"><?= htmlspecialchars($row['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="parser-spec-section">
        <h2>Битрикс — колонки по названию шапки</h2>
        <p class="tab-note">Строка 0 — заголовки. Неизвестные колонки игнорируются.</p>
        <div class="parser-spec-table-wrap">
            <table class="settings-table parser-spec-table">
                <thead>
                <tr><th>Заголовок Excel</th><th>Поле в коде</th><th>Примечание</th></tr>
                </thead>
                <tbody>
                <?php foreach ($bitrix as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['header']) ?></td>
                        <td><code><?= htmlspecialchars($row['field']) ?></code></td>
                        <td class="parser-spec-note"><?= htmlspecialchars($row['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="parser-spec-section">
        <h2>Unified (sales_unified)</h2>
        <p class="tab-note">Объединённая таблица для дашборда. Строится в lib/build_unified.php после «Применить к данным».</p>
        <div class="parser-spec-table-wrap">
            <table class="settings-table parser-spec-table">
                <thead>
                <tr><th>Поле</th><th>Описание</th><th>Источники</th><th>Примечание</th></tr>
                </thead>
                <tbody>
                <?php foreach ($unified as $row): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($row['field']) ?></code></td>
                        <td><?= htmlspecialchars($row['label']) ?></td>
                        <td class="parser-spec-note"><?= htmlspecialchars($row['sources']) ?></td>
                        <td class="parser-spec-note"><?= htmlspecialchars($row['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
</body>
</html>
