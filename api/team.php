<?php
/**
 * api/team.php — эффективность команды: план, win rate, leaderboard, scatter.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('settings.php');
require_lib('aggregates.php');
require_lib('metrics.php');
require_lib('insights.php');
require_lib('funnel_analytics.php');
require_lib('analytics_settings.php');

$filters = read_json_body() ?: $_GET;
$settings = load_settings();
$funnelCfg = funnel_config($settings);
$plan = sales_plan_for_period($settings, $filters['date_from'] ?? null, $filters['date_to'] ?? null);

$salesRows = load_filtered_sales($filters);
$deals = load_filtered_deals_bitrix($filters);
$agents = build_team_metrics($salesRows, $deals, $plan, $funnelCfg);

$margins = array_values(array_filter(array_map(fn($a) => $a['margin_pct'] ?? null, insight_agent_stats($salesRows)), fn($m) => $m !== null));
sort($margins);
$medianMargin = count($margins) ? $margins[(int) floor(count($margins) / 2)] : null;
$agentCards = build_agent_coaching_cards(insight_agent_stats($salesRows), insight_deals_by_agent($deals), $medianMargin);

$teams = [];
foreach ($agents as $a) {
    $team = $a['agent_team'] ?? 'Без команды';
    if (!isset($teams[$team])) {
        $teams[$team] = [
            'team' => $team,
            'closed_amount' => 0.0,
            'closed_count' => 0,
            'plan_amount' => (float) ($plan['by_team'][$team] ?? 0),
        ];
    }
    $teams[$team]['closed_amount'] += $a['closed_amount'];
    $teams[$team]['closed_count'] += $a['closed_count'];
}
$teamList = array_values($teams);
foreach ($teamList as &$t) {
    $t['plan_pct'] = $t['plan_amount'] > 0 ? round($t['closed_amount'] / $t['plan_amount'] * 100, 1) : null;
}
unset($t);
usort($teamList, fn($a, $b) => ($b['plan_pct'] ?? 0) <=> ($a['plan_pct'] ?? 0));

json_response([
    'ok' => true,
    'plan' => $plan,
    'agents' => $agents,
    'teams' => $teamList,
    'leaderboard' => array_map(fn($a) => [
        'label' => $a['agent_display'],
        'value' => $a['plan_pct'] ?? 0,
        'amount' => $a['closed_amount'],
        'agent_key' => $a['agent_key'],
    ], array_slice($agents, 0, 20)),
    'scatter' => array_values(array_filter(array_map(fn($a) => [
        'name' => $a['agent_display'],
        'x' => $a['activity_score'],
        'y' => $a['closed_amount'],
        'agent_key' => $a['agent_key'],
    ], $agents), fn($p) => $p['y'] > 0 || $p['x'] > 0)),
    'coaching' => $agentCards,
    'activity_note' => 'Активность = доля сделок с каналом Call/Встреча (оценка до выгрузки звонков).',
]);
