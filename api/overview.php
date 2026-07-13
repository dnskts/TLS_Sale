<?php

/**
 * api/overview.php — данные вкладки «Обзор»: KPI + графики.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('aggregates.php');
require_lib('filters.php');
require_lib('metrics.php');
require_lib('kpi.php');

$filters = read_json_body();
if ($filters === []) {
    $filters = $_GET;
}
$granularity = $filters['granularity'] ?? 'month';

$rows = load_filtered_sales($filters);
$ops1c = load_filtered_operations_1c($filters);
$dealsBx = load_filtered_deals_bitrix($filters);
$summary = summarize_sales($rows);

$appealsTrendFilters = filters_for_appeals_trend($filters);
$dealsBxTrend = load_filtered_deals_bitrix($appealsTrendFilters);
$appealsTrend = deals_count_trend(
    $dealsBxTrend,
    $granularity,
    $appealsTrendFilters['date_from'] ?? null,
    $appealsTrendFilters['date_to'] ?? null
);

json_response([
    'ok' => true,
    'kpi' => build_kpi_payload($rows, $ops1c, $dealsBx, $filters),
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
    'appeals_trend' => $appealsTrend,
    'top_clients' => top_clients_by_sales($rows, 10),
    'clients_by_type' => group_by_dimension_metric($rows, 'client_type', 'sales', 8),
    'clients_by_card' => group_by_dimension_metric($rows, 'card_type', 'sales', 8),
]);
