<?php
/**
 * insights.php — сигналы и советы для руководителя (rule-based).
 */

declare(strict_types=1);

/** @return array{date_from: ?string, date_to: ?string} */
function previous_period_range(?string $dateFrom, ?string $dateTo): array
{
    if (!$dateFrom || !$dateTo) {
        return ['date_from' => null, 'date_to' => null];
    }
    $tsFrom = strtotime($dateFrom);
    $tsTo = strtotime($dateTo);
    if (!$tsFrom || !$tsTo || $tsTo < $tsFrom) {
        return ['date_from' => null, 'date_to' => null];
    }
    $days = (int) (($tsTo - $tsFrom) / 86400) + 1;
    $prevTo = strtotime('-1 day', $tsFrom);
    $prevFrom = strtotime('-' . ($days - 1) . ' days', $prevTo);
    return [
        'date_from' => date('Y-m-d', $prevFrom),
        'date_to' => date('Y-m-d', $prevTo),
    ];
}

/** @param array<string, mixed> $filters */
function filters_for_previous_period(array $filters): array
{
    $prev = previous_period_range($filters['date_from'] ?? null, $filters['date_to'] ?? null);
    $out = $filters;
    $out['date_from'] = $prev['date_from'];
    $out['date_to'] = $prev['date_to'];
    return $out;
}

/**
 * @param list<array> $rows sales_unified
 * @return array<string, array{agent_key: string, agent_display: string, agent_team: string, sales: float, profit: float, count: int, margin_pct: ?float}>
 */
function insight_agent_stats(array $rows): array
{
    $agents = [];
    foreach ($rows as $row) {
        $key = (string) ($row['agent_key'] ?? '');
        if ($key === '') {
            continue;
        }
        if (!isset($agents[$key])) {
            $agents[$key] = [
                'agent_key' => $key,
                'agent_display' => $row['agent_display'] ?? $key,
                'agent_team' => $row['agent_team'] ?? 'Без команды',
                'sales' => 0.0,
                'profit' => 0.0,
                'count' => 0,
            ];
        }
        $sales = (float) ($row['sales_amount'] ?? 0);
        $agents[$key]['sales'] += $sales;
        $agents[$key]['profit'] += (float) ($row['profit_ex_vat'] ?? 0);
        $agents[$key]['count']++;
    }
    foreach ($agents as &$a) {
        $a['margin_pct'] = $a['sales'] ? $a['profit'] / $a['sales'] * 100 : null;
    }
    unset($a);
    return $agents;
}

/**
 * @param list<array> $deals
 * @return array<string, array{created: int, success: int, conversion_pct: ?float}>
 */
function insight_deals_by_agent(array $deals): array
{
    $map = [];
    foreach ($deals as $row) {
        $key = (string) ($row['agent_key'] ?? '');
        if ($key === '') {
            continue;
        }
        if (!isset($map[$key])) {
            $map[$key] = ['created' => 0, 'success' => 0, 'conversion_pct' => null];
        }
        $map[$key]['created']++;
        if (clean_str($row['deal_result'] ?? null) === 'Успех') {
            $map[$key]['success']++;
        }
    }
    foreach ($map as &$m) {
        $m['conversion_pct'] = $m['created'] ? $m['success'] / $m['created'] * 100 : null;
    }
    unset($m);
    return $map;
}

/**
 * @param list<array> $ops
 * @return array{total: int, refunds: int, refund_pct: ?float}
 */
function insight_refund_stats(array $ops): array
{
    $total = count($ops);
    $refunds = 0;
    foreach ($ops as $row) {
        if ((float) ($row['sales_amount'] ?? 0) < 0) {
            $refunds++;
        }
    }
    return [
        'total' => $total,
        'refunds' => $refunds,
        'refund_pct' => $total ? $refunds / $total * 100 : null,
    ];
}

/** @param list<array> $rows */
function insight_client_concentration(array $rows, int $topN = 10): array
{
    $byClient = [];
    $total = 0.0;
    foreach ($rows as $row) {
        $client = clean_str($row['client'] ?? null) ?? 'Не указан';
        $sales = (float) ($row['sales_amount'] ?? 0);
        if ($sales <= 0) {
            continue;
        }
        $total += $sales;
        $byClient[$client] = ($byClient[$client] ?? 0.0) + $sales;
    }
    arsort($byClient);
    $top = array_slice($byClient, 0, $topN, true);
    $topSum = array_sum($top);
    return [
        'total_sales' => $total,
        'top_sum' => $topSum,
        'top_share_pct' => $total ? $topSum / $total * 100 : null,
        'top_clients' => array_map(fn($label, $sales) => ['label' => $label, 'sales' => $sales], array_keys($top), array_values($top)),
    ];
}

/** @param list<array> $deals */
function insight_top_lost_reasons(array $deals, int $limit = 3): array
{
    $lost = [];
    foreach ($deals as $row) {
        $result = clean_str($row['deal_result'] ?? null);
        if ($result === 'Успех') {
            continue;
        }
        $reason = clean_str($row['lost_deal_reason'] ?? null);
        if (!$reason) {
            $reason = $result ?? 'Не указана';
        }
        $lost[$reason] = ($lost[$reason] ?? 0) + 1;
    }
    arsort($lost);
    $out = [];
    foreach (array_slice($lost, 0, $limit, true) as $reason => $count) {
        $out[] = ['label' => $reason, 'count' => $count];
    }
    return $out;
}

/**
 * @param array<string, mixed> $ctx
 * @return list<array<string, mixed>>
 */
function build_insight_signals(array $ctx): array
{
    $signals = [];
    $summary = $ctx['summary'];
    $prevSummary = $ctx['prev_summary'] ?? null;
    $refund = $ctx['refund'];
    $dealsTotal = $ctx['deals_total'];
    $dealsSuccess = $ctx['deals_success'];
    $conversion = $dealsTotal ? $dealsSuccess / $dealsTotal * 100 : null;
    $concentration = $ctx['concentration'];
    $unknownCount = $ctx['unknown_agent_rows'];
    $lostTop = $ctx['lost_top'];

    if ($refund['refund_pct'] !== null && $refund['refund_pct'] >= 15) {
        $signals[] = [
            'level' => 'danger',
            'title' => 'Высокая доля возвратов в 1С',
            'detail' => format_pct($refund['refund_pct']) . ' операций — ' . format_count($refund['refunds']) . ' из ' . format_count($refund['total']),
            'drill' => ['view' => 'operations_1c', 'operation_type' => 'refund'],
        ];
    } elseif ($refund['refund_pct'] !== null && $refund['refund_pct'] >= 8) {
        $signals[] = [
            'level' => 'warning',
            'title' => 'Доля возвратов выше нормы',
            'detail' => format_pct($refund['refund_pct']) . ' — проверьте качество операций',
            'drill' => ['view' => 'operations_1c', 'operation_type' => 'refund'],
        ];
    }

    if ($conversion !== null && $conversion < 25 && $dealsTotal >= 20) {
        $signals[] = [
            'level' => 'warning',
            'title' => 'Низкая конверсия Битрикс',
            'detail' => format_pct($conversion) . ' успех / ' . format_count($dealsTotal) . ' создано',
            'drill' => ['view' => 'deals_bitrix'],
        ];
    }

    if ($prevSummary && $prevSummary['profit_total'] > 0) {
        $drop = ($summary['profit_total'] - $prevSummary['profit_total']) / $prevSummary['profit_total'] * 100;
        if ($drop <= -15) {
            $signals[] = [
                'level' => 'danger',
                'title' => 'Прибыль снизилась vs прошлый период',
                'detail' => format_pct($drop) . ' (' . format_rub($summary['profit_total']) . ' vs ' . format_rub($prevSummary['profit_total']) . ')',
                'drill' => ['view' => 'sales'],
            ];
        } elseif ($drop <= -8) {
            $signals[] = [
                'level' => 'warning',
                'title' => 'Прибыль ниже прошлого периода',
                'detail' => format_pct($drop),
                'drill' => ['view' => 'sales'],
            ];
        }
    }

    if ($concentration['top_share_pct'] !== null && $concentration['top_share_pct'] >= 40) {
        $signals[] = [
            'level' => 'warning',
            'title' => 'Концентрация на топ-клиентах',
            'detail' => 'Топ-10 клиентов = ' . format_pct($concentration['top_share_pct']) . ' продаж',
            'drill' => ['view' => 'sales'],
        ];
    }

    if ($unknownCount > 0) {
        $signals[] = [
            'level' => 'warning',
            'title' => 'Продажи агентов не из справочника',
            'detail' => format_count($unknownCount) . ' строк с unknown: — обновите настройки',
            'drill' => ['view' => 'sales'],
            'tab' => 'settings',
        ];
    }

    if ($lostTop) {
        $top = $lostTop[0];
        $signals[] = [
            'level' => 'info',
            'title' => 'Главная причина проигрыша',
            'detail' => $top['label'] . ' — ' . format_count($top['count']) . ' сделок',
            'drill' => ['view' => 'deals_bitrix', 'lost_deal_reason' => $top['label']],
        ];
    }

    if ($summary['margin_pct'] !== null && $summary['margin_pct'] < 3 && $summary['sales_total'] > 0) {
        $signals[] = [
            'level' => 'warning',
            'title' => 'Низкая маржа по периоду',
            'detail' => format_pct($summary['margin_pct']) . ' — смотрите не только объём продаж',
            'drill' => ['view' => 'sales'],
        ];
    }

    if (!$signals) {
        $signals[] = [
            'level' => 'ok',
            'title' => 'Критичных отклонений не найдено',
            'detail' => 'Проверьте чек-лист и карточки агентов ниже',
            'drill' => null,
        ];
    }

    return $signals;
}

/**
 * @param array<string, array> $agents
 * @param array<string, array> $dealsByAgent
 * @param float|null $medianMargin
 * @return list<array<string, mixed>>
 */
function build_agent_coaching_cards(array $agents, array $dealsByAgent, ?float $medianMargin): array
{
    $cards = [];
    foreach ($agents as $key => $a) {
        if ($a['count'] < 3) {
            continue;
        }
        $notes = [];
        $level = 'info';

        if ($medianMargin !== null && $a['margin_pct'] !== null && $a['profit'] > 0) {
            if ($a['margin_pct'] < $medianMargin * 0.6 && $a['sales'] > 500000) {
                $notes[] = 'Высокий объём, но маржа ' . format_pct($a['margin_pct']) . ' (медиана ' . format_pct($medianMargin) . ')';
                $level = 'warning';
            }
        }

        $deal = $dealsByAgent[$key] ?? null;
        if ($deal && $deal['created'] >= 5) {
            $conv = $deal['conversion_pct'];
            if ($conv !== null && $conv < 20) {
                $notes[] = 'Конверсия Битрикс ' . format_pct($conv) . ' (' . $deal['success'] . '/' . $deal['created'] . ')';
                $level = 'warning';
            }
            if ($deal['created'] >= 10 && $deal['success'] === 0) {
                $notes[] = 'Нет успешных сделок при ' . $deal['created'] . ' созданных';
                $level = 'danger';
            }
        }

        if (str_starts_with($key, 'unknown:')) {
            $notes[] = 'Агент не в справочнике — добавьте в настройки';
            $level = 'danger';
        }

        if (!$notes) {
            continue;
        }

        $cards[] = [
            'level' => $level,
            'agent_key' => $key,
            'agent_display' => $a['agent_display'],
            'agent_team' => $a['agent_team'],
            'notes' => $notes,
            'profit' => $a['profit'],
            'sales' => $a['sales'],
            'margin_pct' => $a['margin_pct'],
            'drill' => ['view' => 'sales', 'agents' => [$key]],
        ];
    }

    usort($cards, function ($a, $b) {
        $order = ['danger' => 0, 'warning' => 1, 'info' => 2, 'ok' => 3];
        $la = $order[$a['level']] ?? 9;
        $lb = $order[$b['level']] ?? 9;
        if ($la !== $lb) {
            return $la <=> $lb;
        }
        return $b['profit'] <=> $a['profit'];
    });

    return array_slice($cards, 0, 12);
}

/** @param list<array> $rows */
function build_team_comparison(array $rows, array $ops): array
{
    $teams = [];
    foreach ($rows as $row) {
        $team = $row['agent_team'] ?? 'Без команды';
        if (!isset($teams[$team])) {
            $teams[$team] = ['label' => $team, 'sales' => 0.0, 'profit' => 0.0, 'count' => 0];
        }
        $teams[$team]['sales'] += (float) ($row['sales_amount'] ?? 0);
        $teams[$team]['profit'] += (float) ($row['profit_ex_vat'] ?? 0);
        $teams[$team]['count']++;
    }

    $refundsByTeam = [];
    foreach ($ops as $row) {
        $team = $row['agent_team'] ?? 'Без команды';
        if (!isset($refundsByTeam[$team])) {
            $refundsByTeam[$team] = ['total' => 0, 'refunds' => 0];
        }
        $refundsByTeam[$team]['total']++;
        if ((float) ($row['sales_amount'] ?? 0) < 0) {
            $refundsByTeam[$team]['refunds']++;
        }
    }

    $list = [];
    foreach ($teams as $team => $t) {
        $r = $refundsByTeam[$team] ?? ['total' => 0, 'refunds' => 0];
        $list[] = [
            'label' => $team,
            'sales' => $t['sales'],
            'profit' => $t['profit'],
            'margin_pct' => $t['sales'] ? $t['profit'] / $t['sales'] * 100 : null,
            'refund_pct' => $r['total'] ? $r['refunds'] / $r['total'] * 100 : null,
            'drill' => ['view' => 'sales', 'teams' => [$team]],
        ];
    }
    usort($list, fn($a, $b) => $b['profit'] <=> $a['profit']);
    return $list;
}

/** @return list<array{title: string, text: string}> */
function insight_manager_checklist(): array
{
    return [
        ['title' => 'Маржа, не только продажи', 'text' => 'Сравнивайте прибыль и маржу — высокий объём с низкой маржой не равен сильному результату.'],
        ['title' => 'Возвраты — ранний сигнал', 'text' => 'Рост доли возвратов по команде или агенту — повод разобрать категории и поставщиков.'],
        ['title' => 'Причины проигрыша', 'text' => 'Топ причин (дорого, нет фидбэка) — тема для коучинга, а не только оценки агента.'],
        ['title' => 'Справочник агентов', 'text' => 'Unknown-агенты искажают рейтинги — сверяйте настройки после каждой выгрузки.'],
        ['title' => 'Подразделение 1С ≠ команда', 'text' => 'В детализации «Подразделение 1С» из Excel и «Команда» из настроек — разные поля.'],
        ['title' => 'Топ-клиенты', 'text' => 'Если 40%+ продаж на 10 клиентов — риск концентрации.'],
        ['title' => 'Сделки в работе', 'text' => 'Много обращений «в процессе» без движения — нагрузка или квалификация лидов.'],
    ];
}

/** @param array<string, mixed> $filters */
function compute_insights_payload(array $filters): array
{
    $allSales = storage_load_table('sales_unified');
    $rows = apply_sales_filters($allSales, $filters);
    $deals = apply_deals_bitrix_filters(storage_load_table('deals_bitrix'), $filters);
    $ops = apply_operations_1c_filters(storage_load_table('operations_1c'), $filters);

    $prevFilters = filters_for_previous_period($filters);
    $prevRows = apply_sales_filters($allSales, $prevFilters);

    $summary = summarize_sales($rows);
    $prevSummary = summarize_sales($prevRows);
    $refund = insight_refund_stats($ops);
    $concentration = insight_client_concentration($rows);
    $agents = insight_agent_stats($rows);
    $dealsByAgent = insight_deals_by_agent($deals);

    $margins = array_values(array_filter(array_map(fn($a) => $a['margin_pct'], $agents), fn($m) => $m !== null));
    sort($margins);
    $medianMargin = count($margins) ? $margins[(int) floor(count($margins) / 2)] : null;

    $unknownRows = 0;
    foreach ($rows as $row) {
        if (str_starts_with((string) ($row['agent_key'] ?? ''), 'unknown:')) {
            $unknownRows++;
        }
    }

    $dealsSuccess = 0;
    foreach ($deals as $row) {
        if (clean_str($row['deal_result'] ?? null) === 'Успех') {
            $dealsSuccess++;
        }
    }

    $lostTop = insight_top_lost_reasons($deals);

    $signals = build_insight_signals([
        'summary' => $summary,
        'prev_summary' => $prevSummary,
        'refund' => $refund,
        'deals_total' => count($deals),
        'deals_success' => $dealsSuccess,
        'concentration' => $concentration,
        'unknown_agent_rows' => $unknownRows,
        'lost_top' => $lostTop,
    ]);

    $counts = ['danger' => 0, 'warning' => 0, 'info' => 0, 'ok' => 0];
    foreach ($signals as $s) {
        $counts[$s['level']] = ($counts[$s['level']] ?? 0) + 1;
    }

    $priorities = [];
    foreach (array_slice($signals, 0, 5) as $s) {
        if ($s['level'] === 'ok') {
            continue;
        }
        $priorities[] = $s['title'] . ': ' . $s['detail'];
    }

    return [
        'summary' => [
            'profit' => $summary['profit_total'],
            'sales' => $summary['sales_total'],
            'margin_pct' => $summary['margin_pct'],
            'conversion_pct' => count($deals) ? $dealsSuccess / count($deals) * 100 : null,
            'refund_pct' => $refund['refund_pct'],
            'prev_profit' => $prevSummary['profit_total'],
            'prev_period' => $prevFilters['date_from'] && $prevFilters['date_to']
                ? $prevFilters['date_from'] . ' — ' . $prevFilters['date_to']
                : null,
        ],
        'counts' => $counts,
        'priorities' => $priorities,
        'signals' => $signals,
        'agent_cards' => build_agent_coaching_cards($agents, $dealsByAgent, $medianMargin),
        'teams' => build_team_comparison($rows, $ops),
        'checklist' => insight_manager_checklist(),
        'lost_reasons' => $lostTop,
    ];
}
