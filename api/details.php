<?php
/**
 * api/details.php — детализация: sales_unified, deals_bitrix или operations_1c.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('storage.php');
require_lib('filters.php');

$filters = read_json_body() ?: $_GET;
$filters = normalize_drill_filters($filters);
$view = $filters['view'] ?? 'sales';
$meta = storage_load_meta();
$display = [];
$rows = [];

if ($view === 'deals_bitrix') {
    $rows = apply_deals_bitrix_filters(storage_load_table('deals_bitrix'), $filters);
    foreach (array_slice($rows, 0, 2000) as $row) {
        $created = $row['deal_created_at'] ?? '';
        $display[] = [
            'date' => $created ? substr((string) $created, 0, 10) : '',
            'deal_no' => $row['deal_no'] ?? '',
            'deal_status' => clean_str($row['deal_status'] ?? null) ?? '',
            'deal_result' => clean_str($row['deal_result'] ?? null) ?? '',
            'client' => clean_str($row['client'] ?? null) ?? '',
            'responsible_person' => clean_str($row['responsible_person'] ?? null) ?? '',
            'sales_amount' => $row['sales_amount'] ?? 0,
            'lost_deal_reason' => clean_str($row['lost_deal_reason'] ?? null) ?? '',
        ];
    }
} elseif ($view === 'operations_1c') {
    $rows = apply_operations_1c_filters(storage_load_table('operations_1c'), $filters);
    foreach (array_slice($rows, 0, 2000) as $row) {
        $category = clean_str($row['related_service_type'] ?? null) ?? clean_str($row['category'] ?? null) ?? '';
        $teams = $row['agent_teams'] ?? [($row['agent_team'] ?? '')];
        $teamLabel = implode(', ', array_filter(array_map('strval', $teams)));
        $display[] = [
            'date' => $row['date_operation'] ?? '',
            'agent' => clean_str($row['agent'] ?? null) ?? '',
            'agent_team' => $teamLabel,
            'department' => clean_str($row['department'] ?? null) ?? '',
            'category' => $category,
            'client' => clean_str($row['client'] ?? null) ?? '',
            'sales_amount' => $row['sales_amount'] ?? 0,
            'order_no' => clean_str($row['order_raw'] ?? null) ?? '',
        ];
    }
} else {
    $rows = apply_sales_filters(storage_load_table('sales_unified'), $filters);
    foreach (array_slice($rows, 0, 2000) as $row) {
        $display[] = [
            'date' => $row['date'] ?? '',
            'source' => ($row['source'] ?? '') === '1c' ? '1С' : 'Битрикс',
            'agent_display' => $row['agent_display'] ?? '',
            'agent_team' => $row['agent_team'] ?? '',
            'client' => $row['client'] ?? '',
            'category' => $row['category'] ?? '',
            'channel' => $row['channel'] ?? '',
            'partner_or_supplier' => $row['partner_or_supplier'] ?? '',
            'sales_amount' => $row['sales_amount'] ?? 0,
            'profit_ex_vat' => $row['profit_ex_vat'] ?? 0,
            'raw_id' => $row['raw_id'] ?? '',
        ];
    }
}

json_response([
    'ok' => true,
    'view' => $view,
    'drill' => drill_filter_labels($filters),
    'rows' => $display,
    'meta' => $meta,
    'total' => count($rows),
]);
