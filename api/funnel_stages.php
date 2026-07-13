<?php
/**
 * api/funnel_stages.php — воронка по стадиям, конверсия, зависшие сделки.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('settings.php');
require_lib('aggregates.php');
require_lib('metrics.php');
require_lib('funnel_analytics.php');

$filters = read_json_body() ?: $_GET;
$source = $filters['funnel_source'] ?? 'bitrix';
$settings = load_settings();
$funnelCfg = funnel_config($settings);
$asOf = date('Y-m-d');

if ($source === '1c') {
    $ops = load_filtered_operations_1c($filters);
    $positive = 0;
    $refunds = 0;
    $departments = [];
    foreach ($ops as $row) {
        $w = row_weight($row);
        $sales = (float) ($row['sales_amount'] ?? 0);
        if ($sales < 0) {
            $refunds += $w;
        } else {
            $positive += $w;
        }
        $dept = clean_str($row['department'] ?? null) ?? 'Не указано';
        $departments[$dept] = ($departments[$dept] ?? 0) + $w;
    }
    arsort($departments);
    json_response([
        'ok' => true,
        'source' => '1c',
        'stats' => [
            'operations' => rows_total_weight($ops),
            'sales_ops' => $positive,
            'refund_ops' => $refunds,
            'refund_pct' => ($positive + $refunds) ? $refunds / ($positive + $refunds) * 100 : null,
        ],
        'funnel' => [
            ['stage' => 'Продажи', 'count' => $positive, 'amount' => 0],
            ['stage' => 'Возвраты', 'count' => $refunds, 'amount' => 0],
        ],
        'departments' => (static function (array $departments): array {
            $out = [];
            foreach (array_slice($departments, 0, 12, true) as $k => $v) {
                $out[] = ['stage' => $k, 'count' => $v];
            }
            return $out;
        })($departments),
        'stage_bars' => [],
        'conversion' => [],
        'stuck' => [],
        'note' => '1С — операционная воронка (продажи/возвраты), не CRM-стадии.',
    ]);
}

if ($source === 'unified') {
    $deals = load_filtered_deals_bitrix($filters);
    $ops = load_filtered_operations_1c($filters);
    json_response([
        'ok' => true,
        'source' => 'unified',
        'stats' => [
            'deals_created' => rows_total_weight($deals),
            'operations_1c' => rows_total_weight($ops),
        ],
        'funnel' => build_funnel_stages($deals, $funnelCfg, $asOf),
        'bitrix_stages' => build_funnel_stages($deals, $funnelCfg, $asOf),
        'stage_bars' => build_stage_bars_sla($deals, $funnelCfg, $asOf),
        'conversion' => build_stage_conversion_approx($deals, $funnelCfg),
        'stuck' => build_stuck_deals($deals, $funnelCfg, 10, $asOf),
        'note' => 'Общая: CRM-воронка Битрикс + счётчики 1С без дедупликации.',
    ]);
}

$deals = load_filtered_deals_bitrix($filters);
json_response([
    'ok' => true,
    'source' => 'bitrix',
    'stats' => [
        'total' => rows_total_weight($deals),
        'in_progress' => array_sum(array_map(fn($d) => is_open_deal($d) ? row_weight($d) : 0, $deals)),
    ],
    'funnel' => build_funnel_stages($deals, $funnelCfg, $asOf),
    'stage_bars' => build_stage_bars_sla($deals, $funnelCfg, $asOf),
    'conversion' => build_stage_conversion_approx($deals, $funnelCfg),
    'stuck' => build_stuck_deals($deals, $funnelCfg, 10, $asOf),
    'stage_order' => discover_stage_order($deals, $funnelCfg),
]);
