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
require_lib('bitrix_export.php');
require_lib('build_unified.php');
require_lib('auth.php');
require_lib('input_detect.php');
require_lib('filter_options.php');

try {
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    $settings = load_settings();
    $inputDir = project_root() . DIRECTORY_SEPARATOR . ($settings['paths']['input_dir'] ?? 'input');
    $sources = resolve_input_sources($inputDir, $settings);

    if ($sources['one_c'] === null || $sources['bitrix'] === null) {
        json_response([
            'ok' => false,
            'error' => format_input_detect_error($sources, $inputDir),
            'sources' => $sources,
        ], 400);
    }

    $file1c = $sources['one_c']['path'];
    $sheet1c = $sources['one_c']['sheet'];
    $fileBx = $sources['bitrix']['path'];
    $sheetBx = $sources['bitrix']['sheet'];

    $ops = parse_1c($file1c, $sheet1c);
    $deals = parse_bitrix($fileBx, $sheetBx);
    $unified = build_sales_unified($ops, $deals, $settings);
    $agentsDim = build_agents_dim($settings, $unified);

    storage_save_table('operations_1c', $ops);
    storage_save_table('deals_bitrix', $deals);
    storage_save_table('sales_unified', $unified);
    storage_save_table('agents_dim', $agentsDim);

    $warnings = array_merge(
        $sources['warnings'],
        bitrix_export_quality_warnings($deals)
    );
    $warnings[] = 'sources_detected: 1С=' . ($sources['one_c']['file'] ?? basename($file1c))
        . ' [' . $sheet1c . ', score ' . ($sources['one_c']['score'] ?? '?') . ']';
    $warnings[] = 'sources_detected: Битрикс=' . ($sources['bitrix']['file'] ?? basename($fileBx))
        . ' [' . $sheetBx . ', score ' . ($sources['bitrix']['score'] ?? '?') . ']';

    $meta = [
        'loaded_at' => date('c'),
        'backend' => storage_backend(),
        'sources' => [
            'one_c' => $sources['one_c'],
            'bitrix' => $sources['bitrix'],
        ],
        'counts' => [
            'operations_1c' => count($ops),
            'deals_bitrix' => count($deals),
            'sales_unified' => count($unified),
            'agents_dim' => count($agentsDim),
        ],
        'warnings' => $warnings,
    ];
    storage_save_meta($meta);
    save_filter_options_cache(build_filter_options($unified, $settings), $meta['loaded_at']);
    require_lib('aggregates.php');
    build_and_save_aggregates($unified, $ops, $deals, $settings, $meta['loaded_at']);

    json_response(['ok' => true, 'meta' => $meta]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
}
