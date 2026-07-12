<?php
/**
 * api/funnel_1c.php — воронка по операциям 1С.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('storage.php');
require_lib('filters.php');
require_lib('metrics.php');

$filters = read_json_body() ?: $_GET;
$granularity = $filters['granularity'] ?? 'month';
$rows = apply_operations_1c_filters(storage_load_table('operations_1c'), $filters);

$total = count($rows);
$positive = 0;
$refunds = 0;
$profit = 0.0;
$withPayment = 0;
$categories = [];
$channels = [];
$departments = [];
$trend = [];

foreach ($rows as $row) {
    $sales = (float) ($row['sales_amount'] ?? 0);
    $profit += (float) ($row['profit_ex_vat'] ?? 0);
    if ($sales < 0) {
        $refunds++;
    } else {
        $positive++;
    }
    if (!empty($row['payment_date'])) {
        $withPayment++;
    }
    $cat = clean_str($row['related_service_type'] ?? null) ?? clean_str($row['category'] ?? null) ?? 'Не указана';
    $categories[$cat] = ($categories[$cat] ?? 0) + 1;
    $ch = clean_str($row['channel'] ?? null) ?? 'Не указан';
    $channels[$ch] = ($channels[$ch] ?? 0) + 1;
    $dep = clean_str($row['department'] ?? null) ?? 'Не указан';
    $departments[$dep] = ($departments[$dep] ?? 0) + 1;

    $day = $row['date_operation'] ?? null;
    if ($day) {
        $bucket = period_key($day, $granularity);
        if (!isset($trend[$bucket])) {
            $trend[$bucket] = ['period' => $bucket, 'sales' => 0, 'refunds' => 0];
        }
        if ($sales < 0) {
            $trend[$bucket]['refunds']++;
        } else {
            $trend[$bucket]['sales']++;
        }
    }
}

arsort($categories);
arsort($channels);
arsort($departments);
ksort($trend);

json_response([
    'ok' => true,
    'stats' => [
        'total_operations' => $total,
        'positive_count' => $positive,
        'refund_count' => $refunds,
        'refund_pct' => $total ? $refunds / $total * 100 : null,
        'profit_total' => $profit,
        'with_payment_date' => $withPayment,
    ],
    'operation_types' => ['Продажи' => $positive, 'Возвраты' => $refunds],
    'categories' => array_slice($categories, 0, 12, true),
    'channels' => array_slice($channels, 0, 12, true),
    'departments' => array_slice($departments, 0, 12, true),
    'trend' => array_values($trend),
]);
