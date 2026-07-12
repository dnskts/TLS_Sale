<?php
/**
 * api/pipeline.php
 *
 * Полный цикл загрузки данных:
 * 1) читаем Excel из input/
 * 2) парсим 1С и Битрикс
 * 3) собираем sales_unified
 * 4) сохраняем в хранилище (SQLite или JSON)
 *
 * Вызывается кнопкой «Обновить данные» / «Применить к данным».
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('settings.php');
require_lib('storage.php');
require_lib('parse_1c.php');
require_lib('parse_bitrix.php');
require_lib('build_unified.php');
require_lib('auth.php');

// Мутация данных — нужна авторизация настроек (или можно ослабить для «Обновить»)
// Оставляем открытым для кнопки «Обновить данные» в sidebar, но логируем.

try {
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    $settings = load_settings();
    $inputDir = project_root() . DIRECTORY_SEPARATOR . ($settings['paths']['input_dir'] ?? 'input');
    $file1c = $inputDir . DIRECTORY_SEPARATOR . ($settings['paths']['file_1c'] ?? '1C.xlsx');
    $fileBx = $inputDir . DIRECTORY_SEPARATOR . ($settings['paths']['file_bitrix'] ?? 'Битрикс.xlsx');
    $sheet1c = $settings['sheets']['1c'] ?? 'TDSheet';
    $sheetBx = $settings['sheets']['bitrix'] ?? 'Битрикс';

    if (!is_readable($file1c)) {
        json_response(['ok' => false, 'error' => "Не найден файл 1С: {$file1c}"], 400);
    }
    if (!is_readable($fileBx)) {
        json_response(['ok' => false, 'error' => "Не найден файл Битрикс: {$fileBx}"], 400);
    }

    $ops = parse_1c($file1c, $sheet1c);
    $deals = parse_bitrix($fileBx, $sheetBx);
    $unified = build_sales_unified($ops, $deals, $settings);
    $agentsDim = build_agents_dim($settings, $unified);

    storage_save_table('operations_1c', $ops);
    storage_save_table('deals_bitrix', $deals);
    storage_save_table('sales_unified', $unified);
    storage_save_table('agents_dim', $agentsDim);

    $meta = [
        'loaded_at' => date('c'),
        'backend' => storage_backend(),
        'counts' => [
            'operations_1c' => count($ops),
            'deals_bitrix' => count($deals),
            'sales_unified' => count($unified),
            'agents_dim' => count($agentsDim),
        ],
        'warnings' => [],
    ];
    storage_save_meta($meta);

    json_response(['ok' => true, 'meta' => $meta]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
}
