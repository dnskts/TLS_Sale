<?php
/**
 * analytics_settings.php — планы продаж, SLA воронки, вероятности стадий.
 */

declare(strict_types=1);

/** @return array<string, mixed> */
function default_funnel_config(): array
{
    return [
        'stage_order' => [],
        'stage_probabilities' => [],
        'final_stages' => ['Контроль сделки', 'Партнер подтвердил бронь', 'Успех'],
        'risk_statuses' => ['Переговоры', 'Сомневается', 'Клиент не вернулся с фидбэком'],
        'sla_days_by_stage' => [],
        'stuck_days_default' => 14,
        'inactive_days_default' => 7,
        'activity_channels' => ['Call', 'Встреча с клиентом'],
        'high_amount_threshold' => 500000,
    ];
}

/** @return array<string, mixed> */
function funnel_config(?array $settings = null): array
{
    $settings = $settings ?? load_settings();
    $cfg = array_merge(default_funnel_config(), $settings['funnel'] ?? []);
    if (!is_array($cfg['stage_order'])) {
        $cfg['stage_order'] = [];
    }
    if (!is_array($cfg['stage_probabilities'])) {
        $cfg['stage_probabilities'] = [];
    }
    if (!is_array($cfg['sla_days_by_stage'])) {
        $cfg['sla_days_by_stage'] = [];
    }
    return $cfg;
}

/** Ключ периода плана YYYY-MM по date_to или сегодня. */
function plan_period_key(?string $dateTo = null): string
{
    $day = $dateTo ?: date('Y-m-d');
    return substr($day, 0, 7);
}

/**
 * @return array{total: float, by_team: array<string, float>, by_agent: array<string, float>}
 */
function sales_plan_for_period(array $settings, ?string $dateFrom = null, ?string $dateTo = null): array
{
    $key = plan_period_key($dateTo);
    $plans = $settings['sales_plans'] ?? [];
    $plan = is_array($plans[$key] ?? null) ? $plans[$key] : [];
    return [
        'period' => $key,
        'total' => (float) ($plan['total'] ?? 0),
        'by_team' => is_array($plan['by_team'] ?? null) ? array_map('floatval', $plan['by_team']) : [],
        'by_agent' => is_array($plan['by_agent'] ?? null) ? array_map('floatval', $plan['by_agent']) : [],
    ];
}

function stage_probability(string $stage, array $funnelCfg): float
{
    $probs = $funnelCfg['stage_probabilities'] ?? [];
    if (isset($probs[$stage])) {
        return max(0.0, min(1.0, (float) $probs[$stage]));
    }
    $order = $funnelCfg['stage_order'] ?? [];
    if ($order !== []) {
        $idx = array_search($stage, $order, true);
        if ($idx !== false && count($order) > 1) {
            return max(0.05, min(0.95, ($idx + 1) / count($order) * 0.85));
        }
    }
    return 0.1;
}

function sla_days_for_stage(string $stage, array $funnelCfg): int
{
    $map = $funnelCfg['sla_days_by_stage'] ?? [];
    if (isset($map[$stage])) {
        return max(1, (int) $map[$stage]);
    }
    return max(1, (int) ($funnelCfg['stuck_days_default'] ?? 14));
}

function is_activity_channel(?string $channel, array $funnelCfg): bool
{
    $list = $funnelCfg['activity_channels'] ?? ['Call', 'Встреча с клиентом'];
    $ch = clean_str($channel);
    if ($ch === null) {
        return false;
    }
    return in_array($ch, $list, true);
}
