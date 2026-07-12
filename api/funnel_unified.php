<?php
/**
 * api/funnel_unified.php — сводная воронка 1С + Битрикс (без дедупа).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('storage.php');
require_lib('filters.php');
require_lib('metrics.php');

$filters = read_json_body() ?: $_GET;
$granularity = $filters['granularity'] ?? 'month';

$ops = apply_operations_1c_filters(storage_load_table('operations_1c'), $filters);
$deals = apply_deals_bitrix_filters(storage_load_table('deals_bitrix'), $filters);

$opsTotal = count($ops);
$opsRefunds = 0;
$opsProfit = 0.0;
foreach ($ops as $row) {
    $opsProfit += (float) ($row['profit_ex_vat'] ?? 0);
    if ((float) ($row['sales_amount'] ?? 0) < 0) {
        $opsRefunds++;
    }
}
$dealsTotal = count($deals);
$dealsSuccess = 0;
foreach ($deals as $row) {
    if (clean_str($row['deal_result'] ?? null) === 'Успех') {
        $dealsSuccess++;
    }
}

$opsTrend = [];
foreach ($ops as $row) {
    $day = $row['date_operation'] ?? null;
    if (!$day) {
        continue;
    }
    $b = period_key($day, $granularity);
    $opsTrend[$b] = ($opsTrend[$b] ?? 0) + 1;
}
$dealsTrend = [];
foreach ($deals as $row) {
    $day = !empty($row['deal_created_at']) ? substr((string) $row['deal_created_at'], 0, 10) : null;
    if (!$day) {
        continue;
    }
    $b = period_key($day, $granularity);
    $dealsTrend[$b] = ($dealsTrend[$b] ?? 0) + 1;
}
$periods = array_unique(array_merge(array_keys($opsTrend), array_keys($dealsTrend)));
sort($periods);
$trend = [];
foreach ($periods as $p) {
    $trend[] = ['period' => $p, 'ops_count' => $opsTrend[$p] ?? 0, 'deals_created' => $dealsTrend[$p] ?? 0];
}

json_response([
    'ok' => true,
    'stats' => [
        'ops_total' => $opsTotal,
        'ops_refunds' => $opsRefunds,
        'ops_profit' => $opsProfit,
        'deals_created' => $dealsTotal,
        'deals_success' => $dealsSuccess,
        'deals_conversion_pct' => $dealsTotal ? $dealsSuccess / $dealsTotal * 100 : null,
    ],
    'comparison' => [
        '1С операции' => $opsTotal,
        'Битрикс создано' => $dealsTotal,
        'Битрикс успех' => $dealsSuccess,
    ],
    'outcomes' => [
        '1С возвраты' => $opsRefunds,
        'Битрикс успех' => $dealsSuccess,
    ],
    'trend' => $trend,
]);
