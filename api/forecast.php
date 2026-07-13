<?php
/**
 * api/forecast.php — прогноз, weighted pipeline, просроченные, at-risk.
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
$asOf = date('Y-m-d');

$salesRows = load_filtered_sales($filters);
$deals = load_filtered_deals_bitrix($filters);
$forecast = build_forecast($salesRows, $deals, $funnelCfg, $plan, $asOf);
$overdue = build_overdue_deals($deals, $asOf);
$atRisk = build_at_risk_deals($deals, $funnelCfg, $asOf);
$pipeline = build_weighted_pipeline($deals, $funnelCfg);

$signals = compute_insights_payload($filters);

json_response([
    'ok' => true,
    'plan' => $plan,
    'forecast' => $forecast,
    'pipeline' => $pipeline,
    'overdue' => $overdue,
    'at_risk' => $atRisk,
    'signals' => array_values(array_filter($signals['signals'] ?? [], fn($s) => ($s['level'] ?? '') !== 'ok')),
    'checklist' => $signals['checklist'] ?? [],
    'approximation_note' => 'Просрочка по service_date/planned_close_date. Активность — оценка без last_activity в выгрузке.',
]);
