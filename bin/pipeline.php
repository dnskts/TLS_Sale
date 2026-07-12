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
require_lib('build_unified.php');

@set_time_limit(300);
@ini_set('memory_limit', '512M');

$settings = load_settings();
$inputDir = project_root() . DIRECTORY_SEPARATOR . ($settings['paths']['input_dir'] ?? 'input');
$file1c = $inputDir . DIRECTORY_SEPARATOR . ($settings['paths']['file_1c'] ?? '1C.xlsx');
$fileBx = $inputDir . DIRECTORY_SEPARATOR . ($settings['paths']['file_bitrix'] ?? 'Битрикс.xlsx');

echo "1C file: {$file1c}\n";
echo "Bitrix file: {$fileBx}\n";
echo "readable 1c: " . (is_readable($file1c) ? 'yes' : 'no') . "\n";
echo "readable bx: " . (is_readable($fileBx) ? 'yes' : 'no') . "\n";

$ops = parse_1c($file1c, $settings['sheets']['1c'] ?? 'TDSheet');
echo 'operations_1c: ' . count($ops) . "\n";
$deals = parse_bitrix($fileBx, $settings['sheets']['bitrix'] ?? 'Битрикс');
echo 'deals_bitrix: ' . count($deals) . "\n";
$unified = build_sales_unified($ops, $deals, $settings);
echo 'sales_unified: ' . count($unified) . "\n";
$agentsDim = build_agents_dim($settings, $unified);

storage_save_table('operations_1c', $ops);
storage_save_table('deals_bitrix', $deals);
storage_save_table('sales_unified', $unified);
storage_save_table('agents_dim', $agentsDim);
storage_save_meta([
    'loaded_at' => date('c'),
    'backend' => storage_backend(),
    'counts' => [
        'operations_1c' => count($ops),
        'deals_bitrix' => count($deals),
        'sales_unified' => count($unified),
        'agents_dim' => count($agentsDim),
    ],
    'warnings' => [],
]);
echo "OK backend=" . storage_backend() . "\n";
