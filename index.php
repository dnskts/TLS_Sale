<?php
/**
 * index.php
 *
 * Главная страница дашборда (чистый PHP, без Python).
 * Слева — меню вкладок, сверху — фильтры и KPI, справа — содержимое вкладки.
 * Данные подгружаются через api/*.php скриптом assets/js/app.js
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_lib('settings.php');
require_lib('storage.php');

$title = 'Дашборд продаж РС ТЛС';
try {
    $settings = load_settings();
    $title = $settings['app']['title'] ?? $title;
} catch (Throwable $e) {
    // settings может отсутствовать при первом заходе
}
$meta = [];
try {
    $meta = storage_load_meta();
} catch (Throwable $e) {
}
$loadedAt = $meta['loaded_at'] ?? null;
$counts = $meta['counts'] ?? [];
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="stylesheet" href="assets/styles.css">
    <link rel="stylesheet" href="assets/php-app.css">
</head>
<body>
<div class="app-container">
    <aside class="sidebar">
        <h1 class="sidebar-title"><?= htmlspecialchars($title) ?></h1>
        <nav class="sidebar-nav" id="main-nav">
            <button type="button" class="nav-tab active" data-tab="overview">Обзор</button>
            <button type="button" class="nav-tab" data-tab="agents">Агенты и команды</button>
            <button type="button" class="nav-tab" data-tab="insights">Советы руководителю</button>
            <button type="button" class="nav-tab" data-tab="structure">Структура продаж</button>
            <button type="button" class="nav-tab" data-tab="funnel-unified">Воронка Общая</button>
            <button type="button" class="nav-tab" data-tab="funnel-1c">Воронка 1С</button>
            <button type="button" class="nav-tab" data-tab="funnel-bitrix">Воронка Битрикс</button>
            <button type="button" class="nav-tab" data-tab="details">Детализация</button>
        </nav>
        <div class="sidebar-footer">
            <div id="status-banner" class="status-banner <?= $loadedAt ? 'status-ok' : 'status-empty' ?>">
                <?php if ($loadedAt): ?>
                    <div>Загрузка: <?= htmlspecialchars(date('d.m.Y H:i', strtotime($loadedAt) ?: time())) ?></div>
                    <div>
                        1С: <?= (int) ($counts['operations_1c'] ?? 0) ?> ·
                        Битрикс: <?= (int) ($counts['deals_bitrix'] ?? 0) ?> ·
                        Unified: <?= (int) ($counts['sales_unified'] ?? 0) ?>
                    </div>
                <?php else: ?>
                    <strong>Данные не загружены</strong><br>
                    <span>Положите выгрузки 1С и Битрикс (.xlsx) в input/ и нажмите «Обновить данные»</span>
                <?php endif; ?>
            </div>
            <div class="sidebar-actions">
                <button type="button" id="btn-refresh-data" class="btn-secondary">Обновить данные</button>
            </div>
            <div class="sidebar-footer-links" id="sidebar-footer-links">
                <a href="install/check.php">Проверка сервера</a>
                <a href="install/bench.php" title="Диагностика скорости (нужен token)">Анализ скорости</a>
                <button type="button" class="btn-settings-icon" data-tab="settings" title="Настройки">⚙</button>
            </div>
        </div>
    </aside>

    <div class="main-content">
        <div id="filters-panel" class="filters-panel">
            <h3 class="filters-title">Фильтры</h3>
            <div class="filters-grid filters-grid-main">
                <div class="filter-field filter-period">
                    <label class="filter-label">Период</label>
                    <div class="filter-period-row">
                        <select id="filter-period-preset" class="filter-control filter-period-preset" title="Быстрый период">
                            <option value="">Произвольный</option>
                            <option value="day">День</option>
                            <option value="week">Неделя</option>
                            <option value="month" selected>Месяц</option>
                            <option value="year">Год</option>
                        </select>
                        <input type="text" id="filter-date-from" class="filter-control filter-date-text" placeholder="ДД/ММ/ГГГГ" inputmode="numeric" autocomplete="off" data-iso="<?= $monthStart ?>">
                        <span class="filter-period-sep">—</span>
                        <input type="text" id="filter-date-to" class="filter-control filter-date-text" placeholder="ДД/ММ/ГГГГ" inputmode="numeric" autocomplete="off" data-iso="<?= $today ?>">
                    </div>
                </div>
                <div class="filter-field">
                    <label class="filter-label">Источник</label>
                    <select id="filter-source" class="filter-control">
                        <option value="all">Все</option>
                        <option value="1c">1С</option>
                        <option value="bitrix">Битрикс</option>
                    </select>
                </div>
                <div class="filter-field" id="filter-team-wrap"></div>
                <div class="filter-field" id="filter-agent-wrap"></div>
            </div>
            <details class="filters-advanced">
                <summary class="filters-advanced-title">Дополнительно</summary>
                <div class="filters-grid filters-grid-advanced">
                    <div class="filter-field filter-flags">
                        <label class="filter-label">Агенты</label>
                        <div class="filter-pills">
                            <label class="filter-pill"><input type="checkbox" id="filter-inactive"><span>Неактивные</span></label>
                            <label class="filter-pill"><input type="checkbox" id="filter-unknown"><span>Не в справочнике</span></label>
                        </div>
                    </div>
                    <div class="filter-field" id="filter-category-wrap"></div>
                    <div class="filter-field" id="filter-channel-wrap"></div>
                    <div class="filter-field" id="filter-card-type-wrap"></div>
                    <div class="filter-field" id="filter-client-type-wrap"></div>
                    <div class="filter-field" id="filter-request-type-wrap"></div>
                    <div class="filter-field" id="filter-client-wrap"></div>
                    <div class="filter-field" id="filter-partner-wrap"></div>
                </div>
            </details>
            <div class="filters-actions">
                <button type="button" id="btn-apply-filters" class="btn-primary">Применить фильтры</button>
            </div>
        </div>

        <div id="kpi-container" class="kpi-container">
            <div class="kpi-card"><div class="kpi-title">Продажи</div><div class="kpi-value" id="kpi-sales">—</div><div class="kpi-sub" id="kpi-sub-sales">1С + Битрикс</div></div>
            <div class="kpi-card"><div class="kpi-title">Прибыль без НДС</div><div class="kpi-value" id="kpi-profit">—</div><div class="kpi-sub" id="kpi-sub-profit">profit_ex_vat</div></div>
            <div class="kpi-card"><div class="kpi-title">Маржа</div><div class="kpi-value" id="kpi-margin">—</div><div class="kpi-sub" id="kpi-sub-margin">прибыль / продажи</div></div>
            <div class="kpi-card"><div class="kpi-title">Сделки</div><div class="kpi-value" id="kpi-deals">—</div><div class="kpi-sub" id="kpi-sub-deals">1С и Битрикс</div></div>
            <div class="kpi-card" id="kpi-extra-card"><div class="kpi-title" id="kpi-extra-title">Доля 1С / Битрикс</div><div class="kpi-value" id="kpi-extra-value">—</div><div class="kpi-sub" id="kpi-extra-sub">по сумме продаж</div></div>
            <div class="kpi-card"><div class="kpi-title">Средний чек</div><div class="kpi-value" id="kpi-avg-check">—</div><div class="kpi-sub" id="kpi-sub-avg-check">продажи / кол-во</div></div>
        </div>

        <div id="tab-content" class="tab-panel">
            <p class="tab-note">Загрузка…</p>
        </div>
        <div id="app-message" class="debug-panel"></div>
    </div>
</div>

<script src="assets/js/charts_simple.js"></script>
<script src="assets/js/multi_select.js"></script>
<script src="assets/js/settings_editor.js"></script>
<script src="assets/js/app.js"></script>
<script src="assets/js/tab_overview.js"></script>
<script src="assets/js/tab_agents.js"></script>
<script src="assets/js/tab_insights.js"></script>
<script src="assets/js/tab_structure.js"></script>
<script src="assets/js/tab_funnel_unified.js"></script>
<script src="assets/js/tab_funnel_1c.js"></script>
<script src="assets/js/tab_funnel_bitrix.js"></script>
<script src="assets/js/tab_details.js"></script>
<script src="assets/js/tab_settings.js"></script>
</body>
</html>
