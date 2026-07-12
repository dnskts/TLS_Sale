<?php
/**
 * api/agents.php — рейтинг агентов и таблицы команд.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('storage.php');
require_lib('filters.php');
require_lib('metrics.php');

$filters = read_json_body() ?: $_GET;
$rows = apply_sales_filters(storage_load_table('sales_unified'), $filters);

$teams = [];
$agents = [];
foreach ($rows as $row) {
    $team = $row['agent_team'] ?? 'Без команды';
    if (!isset($teams[$team])) {
        $teams[$team] = ['agent_team' => $team, 'sales_amount' => 0.0, 'profit_ex_vat' => 0.0, 'count' => 0];
    }
    $teams[$team]['sales_amount'] += (float) ($row['sales_amount'] ?? 0);
    $teams[$team]['profit_ex_vat'] += (float) ($row['profit_ex_vat'] ?? 0);
    $teams[$team]['count']++;

    $key = $row['agent_key'] ?? '';
    if (!isset($agents[$key])) {
        $agents[$key] = [
            'agent_key' => $key,
            'agent_display' => $row['agent_display'] ?? $key,
            'agent_team' => $team,
            'sales_1c' => 0.0,
            'sales_bitrix' => 0.0,
            'sales_total' => 0.0,
            'profit_ex_vat' => 0.0,
            'count' => 0,
        ];
    }
    $sales = (float) ($row['sales_amount'] ?? 0);
    $agents[$key]['sales_total'] += $sales;
    $agents[$key]['profit_ex_vat'] += (float) ($row['profit_ex_vat'] ?? 0);
    $agents[$key]['count']++;
    if (($row['source'] ?? '') === '1c') {
        $agents[$key]['sales_1c'] += $sales;
    } else {
        $agents[$key]['sales_bitrix'] += $sales;
    }
}

$teamList = array_values($teams);
usort($teamList, fn($a, $b) => $b['profit_ex_vat'] <=> $a['profit_ex_vat']);
$agentList = array_values($agents);
usort($agentList, fn($a, $b) => $b['profit_ex_vat'] <=> $a['profit_ex_vat']);

json_response(['ok' => true, 'teams' => $teamList, 'agents' => array_slice($agentList, 0, 100)]);
