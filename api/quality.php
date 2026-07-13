<?php
/**
 * api/quality.php — качество потока: Pareto, цикл сделки, box plot, источники.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('aggregates.php');
require_lib('metrics.php');
require_lib('funnel_analytics.php');

$filters = read_json_body() ?: $_GET;
$deals = load_filtered_deals_bitrix($filters);
$asOf = date('Y-m-d');

$cycleSuccess = [];
$cycleLost = [];
$cycleAll = [];
foreach ($deals as $deal) {
    $days = deal_cycle_days($deal, $asOf);
    if ($days === null) {
        continue;
    }
    $cycleAll[] = $days;
    $result = clean_str($deal['deal_result'] ?? null);
    if ($result === 'Успех') {
        $cycleSuccess[] = $days;
    } elseif ($result === 'Проиграна') {
        $cycleLost[] = $days;
    }
}

$avgCycle = $cycleAll !== [] ? round(array_sum($cycleAll) / count($cycleAll), 1) : null;

json_response([
    'ok' => true,
    'pareto_lost' => build_pareto_lost_reasons($deals),
    'channel_conversion' => build_channel_conversion($deals),
    'cycle' => [
        'avg_days' => $avgCycle,
        'avg_success_days' => $cycleSuccess !== [] ? round(array_sum($cycleSuccess) / count($cycleSuccess), 1) : null,
        'avg_lost_days' => $cycleLost !== [] ? round(array_sum($cycleLost) / count($cycleLost), 1) : null,
        'box_all' => box_plot_stats($cycleAll),
        'box_success' => box_plot_stats($cycleSuccess),
    ],
    'cycle_note' => 'Цикл: создание → оплата/service_date (Успех) или service_date/сегодня (Проиграна).',
    'source_note' => 'Источники: lead_source или канал связи.',
]);
