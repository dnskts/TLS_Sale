<?php
/**
 * bin/pipeline.php — запуск пересборки из командной строки (для проверки на ПК).
 * Использование: php bin/pipeline.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('settings.php');
require_lib('storage.php');
require_lib('parse_1c.php');
require_lib('parse_bitrix.php');
require_lib('bitrix_export.php');
require_lib('build_unified.php');
require_lib('input_detect.php');
require_lib('filter_options.php');
require_lib('aggregates.php');

@set_time_limit(300);
@ini_set('memory_limit', '512M');

$settings = load_settings();
$inputDir = project_root() . DIRECTORY_SEPARATOR . ($settings['paths']['input_dir'] ?? 'input');
$sources = resolve_input_sources($inputDir, $settings);

if ($sources['one_c'] === null || $sources['bitrix'] === null) {
    fwrite(STDERR, format_input_detect_error($sources, $inputDir) . "\n");
    exit(1);
}

$file1c = $sources['one_c']['path'];
$sheet1c = $sources['one_c']['sheet'];
$fileBx = $sources['bitrix']['path'];
$sheetBx = $sources['bitrix']['sheet'];

echo "1C: {$sources['one_c']['file']} [{$sheet1c}]\n";
echo "Bitrix: {$sources['bitrix']['file']} [{$sheetBx}]\n";

$ops = parse_1c($file1c, $sheet1c);
echo 'operations_1c: ' . count($ops) . "\n";
$deals = parse_bitrix($fileBx, $sheetBx);
echo 'deals_bitrix: ' . count($deals) . "\n";
$unified = build_sales_unified($ops, $deals, $settings);
echo 'sales_unified: ' . count($unified) . "\n";
$agentsDim = build_agents_dim($settings, $unified);

storage_save_table('operations_1c', $ops);
storage_save_table('deals_bitrix', $deals);
storage_save_table('sales_unified', $unified);
storage_save_table('agents_dim', $agentsDim);
$loadedAt = date('c');
$warnings = array_merge($sources['warnings'], bitrix_export_quality_warnings($deals));
storage_save_meta([
    'loaded_at' => $loadedAt,
    'backend' => storage_backend(),
    'sources' => ['one_c' => $sources['one_c'], 'bitrix' => $sources['bitrix']],
    'counts' => [
        'operations_1c' => count($ops),
        'deals_bitrix' => count($deals),
        'sales_unified' => count($unified),
        'agents_dim' => count($agentsDim),
    ],
    'warnings' => $warnings,
]);
save_filter_options_cache(build_filter_options($unified, $settings), $loadedAt);
build_and_save_aggregates($unified, $ops, $deals, $settings, $loadedAt);
echo "OK backend=" . storage_backend() . "\n";
