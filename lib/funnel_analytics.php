<?php
/**
 * funnel_analytics.php — расчёты воронки, прогноза, качества и команды.
 */

declare(strict_types=1);

require_once __DIR__ . '/analytics_settings.php';
require_once __DIR__ . '/insights.php';

function deal_day(?string $dt): ?string
{
    if (!$dt) {
        return null;
    }
    return substr((string) $dt, 0, 10);
}

function days_between(?string $fromDay, ?string $toDay): ?int
{
    if (!$fromDay || !$toDay) {
        return null;
    }
    $a = strtotime($fromDay);
    $b = strtotime($toDay);
    if (!$a || !$b) {
        return null;
    }
    return (int) floor(($b - $a) / 86400);
}

function deal_age_days(array $deal, ?string $asOf = null): ?int
{
    $created = deal_day($deal['deal_created_at'] ?? null);
    if (!$created) {
        return null;
    }
    $asOf = $asOf ?: date('Y-m-d');
    return days_between($created, $asOf);
}

function deal_cycle_days(array $deal, ?string $asOf = null): ?int
{
    $created = deal_day($deal['deal_created_at'] ?? null);
    if (!$created) {
        return null;
    }
    $result = clean_str($deal['deal_result'] ?? null);
    $end = null;
    if ($result === 'Успех') {
        $end = deal_day($deal['date_operation'] ?? null)
            ?: deal_day($deal['service_date'] ?? null);
    } elseif ($result === 'Проиграна') {
        $end = deal_day($deal['service_date'] ?? null) ?: ($asOf ?: date('Y-m-d'));
    } else {
        return null;
    }
    return $end ? days_between($created, $end) : null;
}

function deal_amount(array $deal): float
{
    return (float) ($deal['sales_amount'] ?? 0);
}

function is_open_deal(array $deal): bool
{
    return clean_str($deal['deal_result'] ?? null) === 'В процессе';
}

/** @param list<array> $deals */
function discover_stage_order(array $deals, array $funnelCfg): array
{
    $configured = $funnelCfg['stage_order'] ?? [];
    if ($configured !== []) {
        return array_values($configured);
    }
    $counts = [];
    foreach ($deals as $deal) {
        $st = clean_str($deal['deal_status'] ?? null) ?? 'Не указан';
        $counts[$st] = ($counts[$st] ?? 0) + row_weight($deal);
    }
    arsort($counts);
    return array_keys($counts);
}

/**
 * @param list<array> $deals
 * @return list<array{stage: string, count: int, amount: float, avg_age_days: ?float}>
 */
function build_funnel_stages(array $deals, array $funnelCfg, ?string $asOf = null): array
{
    $order = discover_stage_order($deals, $funnelCfg);
    $buckets = [];
    foreach ($order as $stage) {
        $buckets[$stage] = ['stage' => $stage, 'count' => 0, 'amount' => 0.0, 'age_sum' => 0, 'age_n' => 0];
    }
    foreach ($deals as $deal) {
        $stage = clean_str($deal['deal_status'] ?? null) ?? 'Не указан';
        if (!isset($buckets[$stage])) {
            $buckets[$stage] = ['stage' => $stage, 'count' => 0, 'amount' => 0.0, 'age_sum' => 0, 'age_n' => 0];
            $order[] = $stage;
        }
        $w = row_weight($deal);
        $buckets[$stage]['count'] += $w;
        $buckets[$stage]['amount'] += deal_amount($deal) * $w;
        $age = deal_age_days($deal, $asOf);
        if ($age !== null && is_open_deal($deal)) {
            $buckets[$stage]['age_sum'] += $age * $w;
            $buckets[$stage]['age_n'] += $w;
        }
    }
    $out = [];
    foreach ($order as $stage) {
        if (!isset($buckets[$stage])) {
            continue;
        }
        $b = $buckets[$stage];
        $out[] = [
            'stage' => $stage,
            'count' => $b['count'],
            'amount' => round($b['amount'], 2),
            'avg_age_days' => $b['age_n'] ? round($b['age_sum'] / $b['age_n'], 1) : null,
        ];
    }
    return $out;
}

/**
 * @param list<array> $deals
 * @return list<array{from: string, to: string, rate_pct: ?float, from_count: int, to_count: int}>
 */
function build_stage_conversion_approx(array $deals, array $funnelCfg): array
{
    $order = discover_stage_order($deals, $funnelCfg);
    if (count($order) < 2) {
        return [];
    }
    $stageIdx = [];
    foreach ($order as $i => $st) {
        $stageIdx[$st] = $i;
    }
    $totalCreated = rows_total_weight($deals) ?: 1;
    $reached = array_fill(0, count($order), 0);
    foreach ($deals as $deal) {
        $stage = clean_str($deal['deal_status'] ?? null) ?? 'Не указан';
        $idx = $stageIdx[$stage] ?? null;
        if ($idx === null) {
            continue;
        }
        $w = row_weight($deal);
        for ($i = 0; $i <= $idx; $i++) {
            $reached[$i] += $w;
        }
    }
    $out = [];
    for ($i = 0; $i < count($order) - 1; $i++) {
        $from = $reached[$i] ?: 1;
        $to = $reached[$i + 1];
        $out[] = [
            'from' => $order[$i],
            'to' => $order[$i + 1],
            'from_count' => $reached[$i],
            'to_count' => $to,
            'rate_pct' => round($to / $from * 100, 1),
        ];
    }
    return $out;
}

/**
 * @param list<array> $deals
 * @return list<array<string, mixed>>
 */
function build_stuck_deals(array $deals, array $funnelCfg, int $limit = 10, ?string $asOf = null): array
{
    $threshold = (int) ($funnelCfg['stuck_days_default'] ?? 14);
    $rows = [];
    foreach ($deals as $deal) {
        if (!is_open_deal($deal)) {
            continue;
        }
        $age = deal_age_days($deal, $asOf);
        if ($age === null || $age < $threshold) {
            continue;
        }
        $stage = clean_str($deal['deal_status'] ?? null) ?? 'Не указан';
        $sla = sla_days_for_stage($stage, $funnelCfg);
        $rows[] = [
            'deal_no' => $deal['deal_no'] ?? null,
            'client' => $deal['client'] ?? '',
            'agent_display' => $deal['agent_display'] ?? $deal['agent'] ?? '',
            'deal_status' => $stage,
            'sales_amount' => deal_amount($deal),
            'age_days' => $age,
            'sla_days' => $sla,
            'over_sla' => $age > $sla,
        ];
    }
    usort($rows, fn($a, $b) => $b['sales_amount'] <=> $a['sales_amount']);
    return array_slice($rows, 0, $limit);
}

/**
 * @param list<array> $deals
 * @return list<array{stage: string, count: int, sla_days: int, over_sla: int, color: string}>
 */
function build_stage_bars_sla(array $deals, array $funnelCfg, ?string $asOf = null): array
{
    $stages = build_funnel_stages($deals, $funnelCfg, $asOf);
    $out = [];
    foreach ($stages as $s) {
        $sla = sla_days_for_stage($s['stage'], $funnelCfg);
        $over = 0;
        foreach ($deals as $deal) {
            if (!is_open_deal($deal)) {
                continue;
            }
            if ((clean_str($deal['deal_status'] ?? null) ?? '') !== $s['stage']) {
                continue;
            }
            $age = deal_age_days($deal, $asOf);
            if ($age !== null && $age > $sla) {
                $over += row_weight($deal);
            }
        }
        $color = ($s['avg_age_days'] !== null && $s['avg_age_days'] > $sla) ? '#ef4444' : '#00a2e8';
        $out[] = [
            'stage' => $s['stage'],
            'count' => $s['count'],
            'amount' => $s['amount'],
            'avg_age_days' => $s['avg_age_days'],
            'sla_days' => $sla,
            'over_sla' => $over,
            'color' => $color,
        ];
    }
    return $out;
}

/** @param list<int|float> $values */
function box_plot_stats(array $values): ?array
{
    $values = array_values(array_filter(array_map('intval', $values), fn($v) => $v >= 0));
    if ($values === []) {
        return null;
    }
    sort($values);
    $n = count($values);
    $q = static function (array $arr, float $p) use ($n): float {
        $pos = ($n - 1) * $p;
        $base = (int) floor($pos);
        $rest = $pos - $base;
        if (isset($arr[$base + 1])) {
            return $arr[$base] + $rest * ($arr[$base + 1] - $arr[$base]);
        }
        return (float) $arr[$base];
    };
    $min = (float) $values[0];
    $max = (float) $values[$n - 1];
    $q1 = $q($values, 0.25);
    $med = $q($values, 0.5);
    $q3 = $q($values, 0.75);
    $iqr = $q3 - $q1;
    $low = max($min, $q1 - 1.5 * $iqr);
    $high = min($max, $q3 + 1.5 * $iqr);
    $outliers = array_values(array_filter($values, fn($v) => $v < $low || $v > $high));
    return [
        'min' => $min,
        'q1' => round($q1, 1),
        'median' => round($med, 1),
        'q3' => round($q3, 1),
        'max' => $max,
        'mean' => round(array_sum($values) / $n, 1),
        'count' => $n,
        'outliers' => array_slice($outliers, 0, 20),
        'values' => array_slice($values, 0, 500),
    ];
}

/** @param list<array> $deals */
function build_pareto_lost_reasons(array $deals, int $limit = 15): array
{
    $lost = [];
    foreach ($deals as $deal) {
        if (clean_str($deal['deal_result'] ?? null) !== 'Проиграна') {
            continue;
        }
        $reason = clean_str($deal['lost_deal_reason'] ?? null) ?? 'Не указана';
        $lost[$reason] = ($lost[$reason] ?? 0) + row_weight($deal);
    }
    arsort($lost);
    $total = array_sum($lost) ?: 1;
    $items = [];
    $cum = 0.0;
    foreach (array_slice($lost, 0, $limit, true) as $reason => $count) {
        $share = $count / $total * 100;
        $cum += $share;
        $items[] = [
            'reason' => $reason,
            'count' => $count,
            'share_pct' => round($share, 1),
            'cumulative_pct' => round($cum, 1),
        ];
    }
    return $items;
}

/** @param list<array> $deals */
function build_channel_conversion(array $deals): array
{
    $groups = [];
    foreach ($deals as $deal) {
        $ch = clean_str($deal['lead_source'] ?? null)
            ?: clean_str($deal['channel'] ?? null)
            ?: 'Не указан';
        if (!isset($groups[$ch])) {
            $groups[$ch] = ['channel' => $ch, 'created' => 0, 'success' => 0, 'amount_sum' => 0.0, 'success_amount' => 0.0];
        }
        $w = row_weight($deal);
        $groups[$ch]['created'] += $w;
        $amt = deal_amount($deal);
        $groups[$ch]['amount_sum'] += $amt * $w;
        if (clean_str($deal['deal_result'] ?? null) === 'Успех') {
            $groups[$ch]['success'] += $w;
            $groups[$ch]['success_amount'] += $amt * $w;
        }
    }
    $out = [];
    foreach ($groups as $g) {
        $out[] = [
            'channel' => $g['channel'],
            'created' => $g['created'],
            'success' => $g['success'],
            'conversion_pct' => $g['created'] ? round($g['success'] / $g['created'] * 100, 1) : null,
            'avg_check' => $g['success'] ? round($g['success_amount'] / $g['success'], 0) : null,
            'total_amount' => round($g['amount_sum'], 0),
        ];
    }
    usort($out, fn($a, $b) => $b['created'] <=> $a['created']);
    return $out;
}

/** @param list<array> $deals */
function build_weighted_pipeline(array $deals, array $funnelCfg): array
{
    $weighted = 0.0;
    $openAmount = 0.0;
    $openCount = 0;
    foreach ($deals as $deal) {
        if (!is_open_deal($deal)) {
            continue;
        }
        $amt = deal_amount($deal);
        $stage = clean_str($deal['deal_status'] ?? null) ?? '';
        $prob = stage_probability($stage, $funnelCfg);
        $w = row_weight($deal);
        $openAmount += $amt * $w;
        $openCount += $w;
        $weighted += $amt * $prob * $w;
    }
    return [
        'weighted_sum' => round($weighted, 2),
        'open_amount' => round($openAmount, 2),
        'open_count' => $openCount,
    ];
}

/**
 * @param list<array> $salesRows sales_unified closed in period
 * @param list<array> $deals
 */
function build_forecast(array $salesRows, array $deals, array $funnelCfg, array $plan, ?string $asOf = null): array
{
    $asOf = $asOf ?: date('Y-m-d');
    $closedAmount = 0.0;
    foreach ($salesRows as $row) {
        if (($row['source'] ?? '') === 'bitrix') {
            $closedAmount += (float) ($row['sales_amount'] ?? 0);
        }
    }
    $pipeline = build_weighted_pipeline($deals, $funnelCfg);
    $finalStages = $funnelCfg['final_stages'] ?? [];
    $finalAmount = 0.0;
    $finalCount = 0;
    foreach ($deals as $deal) {
        if (!is_open_deal($deal)) {
            continue;
        }
        $stage = clean_str($deal['deal_status'] ?? null) ?? '';
        if (!in_array($stage, $finalStages, true)) {
            continue;
        }
        $amt = deal_amount($deal);
        $w = row_weight($deal);
        $finalAmount += $amt * $w;
        $finalCount += $w;
    }
    $forecast = $closedAmount + $pipeline['weighted_sum'];
    $planTotal = (float) ($plan['total'] ?? 0);
    return [
        'closed_amount' => round($closedAmount, 2),
        'weighted_pipeline' => $pipeline['weighted_sum'],
        'final_stage_amount' => round($finalAmount, 2),
        'final_stage_count' => $finalCount,
        'forecast_total' => round($forecast, 2),
        'plan_total' => $planTotal,
        'plan_pct' => $planTotal > 0 ? round($forecast / $planTotal * 100, 1) : null,
        'open_count' => $pipeline['open_count'],
    ];
}

/** @param list<array> $deals */
function build_overdue_deals(array $deals, ?string $asOf = null): array
{
    $asOf = $asOf ?: date('Y-m-d');
    $count = 0;
    $amount = 0.0;
    $items = [];
    foreach ($deals as $deal) {
        if (!is_open_deal($deal)) {
            continue;
        }
        $due = deal_day($deal['planned_close_date'] ?? null)
            ?: deal_day($deal['service_date'] ?? null);
        if (!$due || $due >= $asOf) {
            continue;
        }
        $w = row_weight($deal);
        $amt = deal_amount($deal);
        $count += $w;
        $amount += $amt * $w;
        $items[] = [
            'deal_no' => $deal['deal_no'] ?? null,
            'client' => $deal['client'] ?? '',
            'agent_display' => $deal['agent_display'] ?? '',
            'sales_amount' => $amt,
            'due_date' => $due,
            'days_overdue' => days_between($due, $asOf),
        ];
    }
    usort($items, fn($a, $b) => $b['sales_amount'] <=> $a['sales_amount']);
    return [
        'count' => $count,
        'amount' => round($amount, 2),
        'items' => array_slice($items, 0, 50),
    ];
}

/** @param list<array> $deals */
function build_at_risk_deals(array $deals, array $funnelCfg, ?string $asOf = null): array
{
    $asOf = $asOf ?: date('Y-m-d');
    $threshold = (float) ($funnelCfg['high_amount_threshold'] ?? 500000);
    $inactiveDays = (int) ($funnelCfg['inactive_days_default'] ?? 7);
    $riskStatuses = $funnelCfg['risk_statuses'] ?? [];
    $rows = [];
    foreach ($deals as $deal) {
        if (!is_open_deal($deal)) {
            continue;
        }
        $amt = deal_amount($deal);
        if ($amt < $threshold) {
            continue;
        }
        $reasons = [];
        $stage = clean_str($deal['deal_status'] ?? null) ?? '';
        $age = deal_age_days($deal, $asOf);
        $sla = sla_days_for_stage($stage, $funnelCfg);
        if ($age !== null && $age > $sla) {
            $reasons[] = 'Превышен SLA стадии (' . $age . ' дн.)';
        }
        $due = deal_day($deal['planned_close_date'] ?? null)
            ?: deal_day($deal['service_date'] ?? null);
        if ($due && $due < $asOf) {
            $reasons[] = 'Просрочена дата (' . $due . ')';
        }
        if ($riskStatuses !== [] && in_array($stage, $riskStatuses, true)) {
            $reasons[] = 'Статус риска: ' . $stage;
        }
        $lastAct = deal_day($deal['last_activity_at'] ?? null);
        if ($lastAct) {
            $inactive = days_between($lastAct, $asOf);
            if ($inactive !== null && $inactive > $inactiveDays) {
                $reasons[] = 'Нет активности ' . $inactive . ' дн.';
            }
        } elseif ($age !== null && $age > $inactiveDays) {
            $reasons[] = 'Долго в работе без активности (оценка)';
        }
        if ($reasons === []) {
            continue;
        }
        $rows[] = [
            'deal_no' => $deal['deal_no'] ?? null,
            'client' => $deal['client'] ?? '',
            'agent_display' => $deal['agent_display'] ?? '',
            'deal_status' => $stage,
            'sales_amount' => $amt,
            'age_days' => $age,
            'reasons' => $reasons,
        ];
    }
    usort($rows, fn($a, $b) => $b['sales_amount'] <=> $a['sales_amount']);
    return array_slice($rows, 0, 30);
}

/** @param list<array> $salesRows */
function build_team_metrics(array $salesRows, array $deals, array $plan, array $funnelCfg): array
{
    $agents = insight_agent_stats($salesRows);
    $dealsByAgent = insight_deals_by_agent($deals);
    $activityByAgent = [];
    foreach ($deals as $deal) {
        $key = (string) ($deal['agent_key'] ?? '');
        if ($key === '') {
            continue;
        }
        if (!isset($activityByAgent[$key])) {
            $activityByAgent[$key] = ['total' => 0, 'active_channel' => 0, 'calls' => 0.0, 'meetings' => 0.0, 'has_counts' => false];
        }
        $w = row_weight($deal);
        $activityByAgent[$key]['total'] += $w;
        if (is_activity_channel($deal['channel'] ?? null, $funnelCfg)) {
            $activityByAgent[$key]['active_channel'] += $w;
        }
        if (isset($deal['calls_count']) || isset($deal['meetings_count'])) {
            $activityByAgent[$key]['has_counts'] = true;
            $activityByAgent[$key]['calls'] += (float) ($deal['calls_count'] ?? 0) * $w;
            $activityByAgent[$key]['meetings'] += (float) ($deal['meetings_count'] ?? 0) * $w;
        }
    }
    $list = [];
    foreach ($agents as $key => $a) {
        $dealStats = $dealsByAgent[$key] ?? ['created' => 0, 'success' => 0, 'conversion_pct' => null];
        $planAmt = (float) ($plan['by_agent'][$key] ?? 0);
        $closedAmount = $a['sales'];
        $act = $activityByAgent[$key] ?? ['total' => 0, 'active_channel' => 0, 'calls' => 0, 'meetings' => 0, 'has_counts' => false];
        if (!empty($act['has_counts']) && $act['total'] > 0) {
            $activityScore = round(($act['calls'] + $act['meetings']) / $act['total'], 1);
        } else {
            $activityScore = $act['total'] ? round($act['active_channel'] / $act['total'] * 100, 1) : 0;
        }
        $list[] = [
            'agent_key' => $key,
            'agent_display' => $a['agent_display'],
            'agent_team' => $a['agent_team'],
            'closed_amount' => round($closedAmount, 2),
            'closed_count' => $a['count'],
            'profit' => round($a['profit'], 2),
            'win_rate_pct' => $dealStats['conversion_pct'],
            'deals_created' => $dealStats['created'],
            'deals_success' => $dealStats['success'],
            'plan_amount' => $planAmt,
            'plan_pct' => $planAmt > 0 ? round($closedAmount / $planAmt * 100, 1) : null,
            'activity_score' => $activityScore,
            'activity_deals' => $act['active_channel'],
            'total_deals' => $act['total'],
        ];
    }
    usort($list, fn($a, $b) => ($b['plan_pct'] ?? 0) <=> ($a['plan_pct'] ?? 0));
    return $list;
}
