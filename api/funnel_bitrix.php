<?php
/**
 * api/funnel_bitrix.php — воронка по всем сделкам Битрикс.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('storage.php');
require_lib('filters.php');
require_lib('metrics.php');

$filters = read_json_body() ?: $_GET;
$granularity = $filters['granularity'] ?? 'month';
$rows = apply_deals_bitrix_filters(storage_load_table('deals_bitrix'), $filters);

$total = count($rows);
$success = 0;
$withPaid = 0;
$paidInPeriod = 0;
$results = [];
$statuses = [];
$lost = [];
$createdTrend = [];
$paidTrend = [];

$dateFrom = $filters['date_from'] ?? null;
$dateTo = $filters['date_to'] ?? null;

foreach ($rows as $row) {
    $result = clean_str($row['deal_result'] ?? null) ?? 'Не указан';
    $results[$result] = ($results[$result] ?? 0) + 1;
    if ($result === 'Успех') {
        $success++;
    }
    $status = clean_str($row['deal_status'] ?? null) ?? 'Не указан';
    $statuses[$status] = ($statuses[$status] ?? 0) + 1;

    if (!empty($row['client_paid_at'])) {
        $withPaid++;
        $paidDay = substr((string) $row['client_paid_at'], 0, 10);
        $ok = true;
        if ($dateFrom && $paidDay < $dateFrom) {
            $ok = false;
        }
        if ($dateTo && $paidDay > $dateTo) {
            $ok = false;
        }
        if ($ok) {
            $paidInPeriod++;
        }
        $bucket = period_key($paidDay, $granularity);
        $paidTrend[$bucket] = ($paidTrend[$bucket] ?? 0) + 1;
    }
    if ($result === 'Проиграна') {
        $reason = clean_str($row['lost_deal_reason'] ?? null) ?? 'Не указана';
        $lost[$reason] = ($lost[$reason] ?? 0) + 1;
    }
    $createdDay = !empty($row['deal_created_at']) ? substr((string) $row['deal_created_at'], 0, 10) : null;
    if ($createdDay) {
        $bucket = period_key($createdDay, $granularity);
        $createdTrend[$bucket] = ($createdTrend[$bucket] ?? 0) + 1;
    }
}

arsort($statuses);
arsort($lost);
$periods = array_unique(array_merge(array_keys($createdTrend), array_keys($paidTrend)));
sort($periods);
$trend = [];
foreach ($periods as $p) {
    $trend[] = ['period' => $p, 'created' => $createdTrend[$p] ?? 0, 'paid' => $paidTrend[$p] ?? 0];
}

$lostTable = [];
$lostTotal = array_sum($lost) ?: 1;
foreach ($lost as $reason => $count) {
    $lostTable[] = ['lost_deal_reason' => $reason, 'count' => $count, 'share_pct' => round($count / $lostTotal * 100, 1)];
}

json_response([
    'ok' => true,
    'stats' => [
        'total_created' => $total,
        'success_count' => $success,
        'conversion_pct' => $total ? $success / $total * 100 : null,
        'paid_in_period' => $paidInPeriod,
        'with_paid_date' => $withPaid,
        'without_paid_date' => $total - $withPaid,
    ],
    'results' => $results,
    'statuses' => array_slice($statuses, 0, 15, true),
    'lost' => array_slice($lost, 0, 12, true),
    'lost_table' => $lostTable,
    'trend' => $trend,
]);
