<?php

/**
 * api/overview.php — данные вкладки «Обзор»: KPI + графики.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('storage.php');
require_lib('filters.php');
require_lib('metrics.php');

$filters = read_json_body();
if ($filters === []) {
    $filters = $_GET;
}
$granularity = $filters['granularity'] ?? 'month';
$source = $filters['source'] ?? 'all';

$rows = apply_sales_filters(storage_load_table('sales_unified'), $filters);
$summary = summarize_sales($rows);

$ops1c = apply_operations_1c_filters(storage_load_table('operations_1c'), $filters);
$dealsBx = apply_deals_bitrix_filters(storage_load_table('deals_bitrix'), $filters);
$opsTotal = count($ops1c);
$dealsTotal = count($dealsBx);
$opsRefunds = 0;
foreach ($ops1c as $row) {
    if ((float) ($row['sales_amount'] ?? 0) < 0) {
        $opsRefunds++;
    }
}
$dealsSuccess = 0;
foreach ($dealsBx as $row) {
    if (clean_str($row['deal_result'] ?? null) === 'Успех') {
        $dealsSuccess++;
    }
}

$dealsCount = '—';
$dealsSub = '1С и Битрикс';
$extraTitle = 'Доля 1С / Битрикс';
$extraValue = '—';
$extraSub = 'по сумме продаж';

if ($source === '1c') {
    $dealsCount = format_count($opsTotal);
    $dealsSub = 'операции 1С';
    $extraTitle = 'Доля возвратов';
    $extraValue = format_pct($opsTotal ? $opsRefunds / $opsTotal * 100 : null);
    $extraSub = 'от всех операций';
} elseif ($source === 'bitrix') {
    $dealsCount = format_count($dealsTotal);
    $dealsSub = 'создано сделок';
    $extraTitle = 'Конверсия';
    $extraValue = format_pct($dealsTotal ? $dealsSuccess / $dealsTotal * 100 : null);
    $extraSub = 'успех / создано';
} else {
    $dealsCount = '1С: ' . format_count($opsTotal) . ' · Битрикс: ' . format_count($dealsTotal);
    $dealsSub = 'операции 1С · сделки Битрикс';
    $extraValue = ($summary['share_1c_pct'] !== null)
        ? format_pct($summary['share_1c_pct']) . ' / ' . format_pct($summary['share_bitrix_pct'])
        : '—';
}

json_response([
    'ok' => true,
    'kpi' => [
        'sales_total' => format_rub($summary['sales_total']),
        'profit_total' => format_rub($summary['profit_total']),
        'margin' => format_pct($summary['margin_pct']),
        'deals_count' => $dealsCount,
        'deals_sub' => $dealsSub,
        'extra_title' => $extraTitle,
        'extra_value' => $extraValue,
        'extra_sub' => $extraSub,
        'avg_check' => format_rub($summary['avg_check']),
    ],
    'trend' => trend_series($rows, $granularity),
    'by_source' => [
        ['label' => '1С', 'sales' => $summary['sales_1c'], 'count' => $summary['count_1c']],
        ['label' => 'Битрикс', 'sales' => $summary['sales_bitrix'], 'count' => $summary['count_bitrix']],
    ],
    'by_team' => group_by_dimension_metric($rows, 'agent_team', 'sales', 8),
    'by_client_type' => group_by_dimension_metric($rows, 'client_type', 'sales', 8),
    'by_category' => group_by_dimension_metric($rows, 'category', 'sales', 8),
    'by_channel' => group_by_dimension_metric($rows, 'channel', 'sales', 8),
    'profit_by_team' => group_by_dimension_metric($rows, 'agent_team', 'profit', 8),
    'profit_by_client_type' => group_by_dimension_metric($rows, 'client_type', 'profit', 8),
    'profit_by_category' => group_by_dimension_metric($rows, 'category', 'profit', 8),
    'profit_by_channel' => group_by_dimension_metric($rows, 'channel', 'profit', 8),
    'appeals_by_result' => group_deals_by_field($dealsBx, 'deal_result', 10),
    'appeals_by_funnel' => group_deals_by_funnel($dealsBx),
    'appeals_trend' => deals_count_trend($dealsBx, $granularity),
    'top_clients' => top_clients_by_sales($rows, 10),
    'clients_by_type' => group_by_dimension_metric($rows, 'client_type', 'sales', 8),
    'clients_by_card' => group_by_dimension_metric($rows, 'card_type', 'sales', 8),
]);
