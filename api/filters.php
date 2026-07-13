<?php
/**
 * api/filters.php — варианты для выпадающих списков фильтров + статус загрузки.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('settings.php');
require_lib('storage.php');
require_lib('filter_options.php');

$settings = load_settings();
$meta = storage_load_meta();
$cachedOptions = load_filter_options_cache();

if ($cachedOptions !== null) {
    json_response([
        'ok' => true,
        'backend' => storage_backend(),
        'meta' => $meta,
        'title' => $settings['app']['title'] ?? 'Дашборд продаж РС ТЛС',
        'defaults' => $settings['defaults'] ?? [],
        'options' => $cachedOptions,
    ]);
}

$sales = storage_load_table('sales_unified');
$options = build_filter_options($sales, $settings);
$loadedAt = $meta['loaded_at'] ?? null;
if ($loadedAt && $sales !== []) {
    try {
        save_filter_options_cache($options, (string) $loadedAt);
    } catch (Throwable $e) {
        // кэш опционален
    }
}

json_response([
    'ok' => true,
    'backend' => storage_backend(),
    'meta' => $meta,
    'title' => $settings['app']['title'] ?? 'Дашборд продаж РС ТЛС',
    'defaults' => $settings['defaults'] ?? [],
    'options' => $options,
]);
