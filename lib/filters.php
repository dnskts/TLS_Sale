<?php
/**
 * filters.php
 *
 * Применение фильтров дашборда к таблицам.
 * Фильтры приходят из браузера (период, команда, агент и т.д.).
 */

declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/metrics.php';

/**
 * Фильтр sales_unified (вкладки Обзор, Агенты, Структура, Детализация).
 *
 * @param list<array> $rows
 * @return list<array>
 */
function apply_sales_filters(array $rows, array $filters): array
{
    $settings = load_settings();
    $dateFrom = $filters['date_from'] ?? null;
    $dateTo = $filters['date_to'] ?? null;
    $source = $filters['source'] ?? 'all';
    $teams = $filters['teams'] ?? [];
    $agents = $filters['agents'] ?? [];
    $showInactive = !empty($filters['show_inactive_agents']);
    $showUnknown = !empty($filters['show_unknown_agents']);

    $activeKeys = [];
    $inactiveKeys = [];
    foreach ($settings['agents'] ?? [] as $a) {
        $key = $a['agent_key'] ?? '';
        if ($key === '') {
            continue;
        }
        if (!empty($a['is_active'])) {
            $activeKeys[$key] = true;
        } else {
            $inactiveKeys[$key] = true;
        }
    }

    $out = [];
    foreach ($rows as $row) {
        $date = $row['date'] ?? null;
        if ($dateFrom && $date && $date < $dateFrom) {
            continue;
        }
        if ($dateTo && $date && $date > $dateTo) {
            continue;
        }
        if ($source && $source !== 'all' && ($row['source'] ?? '') !== $source) {
            continue;
        }
        if ($teams) {
            $rowTeams = $row['agent_teams'] ?? [($row['agent_team'] ?? '')];
            $match = false;
            foreach ($rowTeams as $rt) {
                if (in_array($rt, $teams, true)) {
                    $match = true;
                    break;
                }
            }
            if (!$match) {
                continue;
            }
        }
        $key = (string) ($row['agent_key'] ?? '');
        if ($agents) {
            if (!in_array($key, $agents, true)) {
                continue;
            }
        } else {
            $allowed = $activeKeys;
            if ($showInactive) {
                $allowed = $allowed + $inactiveKeys;
            }
            $isUnknown = str_starts_with($key, 'unknown:');
            if ($isUnknown) {
                if (!$showUnknown) {
                    continue;
                }
            } elseif (!isset($allowed[$key])) {
                continue;
            }
        }
        foreach (
            [
                'clients' => 'client',
                'partners' => 'partner_or_supplier',
            ] as $filterKey => $col
        ) {
            $vals = $filters[$filterKey] ?? [];
            if (!$vals) {
                $legacy = $filters[rtrim($filterKey, 's')] ?? null;
                if ($legacy) {
                    $vals = is_array($legacy) ? $legacy : [$legacy];
                }
            }
            if ($vals && !in_array($row[$col] ?? null, $vals, true)) {
                continue 2;
            }
        }
        foreach (
            [
                'categories' => 'category',
                'channels' => 'channel',
                'card_types' => 'card_type',
                'client_types' => 'client_type',
                'request_types' => 'request_type',
            ] as $filterKey => $col
        ) {
            $vals = $filters[$filterKey] ?? [];
            if (!$vals) {
                $legacy = $filters[rtrim($filterKey, 's')] ?? null;
                if ($legacy !== null && $legacy !== '') {
                    $vals = is_array($legacy) ? $legacy : [$legacy];
                }
            }
            if ($vals && !in_array($row[$col] ?? null, $vals, true)) {
                continue 2;
            }
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * Фильтр deals_bitrix для воронки (период по deal_created_at).
 * @param list<array> $rows
 */
function apply_deals_bitrix_filters(array $rows, array $filters): array
{
    // Обогащаем агентом на лету
    $settings = load_settings();
    $enriched = [];
    foreach ($rows as $row) {
        if (!isset($row['agent_key'])) {
            $resolved = resolve_agent_bitrix($row['responsible_person'] ?? null, $settings);
            $row['agent_key'] = $resolved['agent_key'];
            $row['agent_display'] = $resolved['name_display'];
            $row['agent_team'] = $resolved['team'];
            $row['agent_teams'] = $resolved['teams'] ?? [$resolved['team']];
        }
        $enriched[] = $row;
    }

    $dateFrom = $filters['date_from'] ?? null;
    $dateTo = $filters['date_to'] ?? null;
    $out = [];
    foreach ($enriched as $row) {
        $created = $row['deal_created_at'] ?? null;
        $day = $created ? substr((string) $created, 0, 10) : null;
        if ($dateFrom && $day && $day < $dateFrom) {
            continue;
        }
        if ($dateTo && $day && $day > $dateTo) {
            continue;
        }
        // Дальше те же правила агентов/команд/категорий
        $tmp = apply_sales_filters(
            [[
                'date' => $day,
                'source' => 'bitrix',
                'agent_key' => $row['agent_key'],
                'agent_team' => $row['agent_team'],
                'agent_teams' => $row['agent_teams'] ?? [$row['agent_team']],
                'category' => $row['category'] ?? null,
                'channel' => $row['channel'] ?? null,
                'request_type' => $row['request_type'] ?? null,
                'client' => $row['client'] ?? null,
                'partner_or_supplier' => $row['partner'] ?? null,
                'card_type' => $row['card_type'] ?? null,
                'client_type' => $row['client_type'] ?? null,
            ]],
            array_merge($filters, ['source' => 'all'])
        );
        if ($tmp) {
            $out[] = $row;
        }
    }
    return apply_deals_bitrix_drill_filters($out, $filters);
}

/**
 * Фильтр operations_1c для воронки 1С (период по date_operation).
 * @param list<array> $rows
 */
function apply_operations_1c_filters(array $rows, array $filters): array
{
    $settings = load_settings();
    $enriched = [];
    foreach ($rows as $row) {
        if (!isset($row['agent_key'])) {
            $resolved = resolve_agent_1c($row['agent'] ?? null, $settings, $row['department'] ?? null);
            $row['agent_key'] = $resolved['agent_key'];
            $row['agent_display'] = $resolved['name_display'];
            $row['agent_team'] = $resolved['team'];
            $row['agent_teams'] = $resolved['teams'] ?? [$resolved['team']];
        }
        $enriched[] = $row;
    }
    $dateFrom = $filters['date_from'] ?? null;
    $dateTo = $filters['date_to'] ?? null;
    $out = [];
    foreach ($enriched as $row) {
        $day = $row['date_operation'] ?? null;
        if ($dateFrom && $day && $day < $dateFrom) {
            continue;
        }
        if ($dateTo && $day && $day > $dateTo) {
            continue;
        }
        $category = clean_str($row['related_service_type'] ?? null) ?? clean_str($row['category'] ?? null);
        $tmp = apply_sales_filters(
            [[
                'date' => $day,
                'source' => '1c',
                'agent_key' => $row['agent_key'],
                'agent_team' => $row['agent_team'],
                'agent_teams' => $row['agent_teams'] ?? [$row['agent_team']],
                'category' => $category,
                'channel' => $row['channel'] ?? null,
                'card_type' => $row['card_type'] ?? null,
                'client' => $row['client'] ?? null,
                'partner_or_supplier' => $row['supplier'] ?? null,
                'client_type' => null,
                'request_type' => null,
            ]],
            array_merge($filters, ['source' => 'all'])
        );
        if ($tmp) {
            $out[] = $row;
        }
    }
    return apply_operations_1c_drill_filters($out, $filters);
}

/** Доп. фильтры воронки Битрикс для drill-down в детализацию. */
function apply_deals_bitrix_drill_filters(array $rows, array $filters): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!empty($filters['deal_status'])) {
            $actual = clean_str($row['deal_status'] ?? null) ?? 'Не указан';
            if ($actual !== $filters['deal_status']) {
                continue;
            }
        }
        if (!empty($filters['deal_result'])) {
            $actual = clean_str($row['deal_result'] ?? null) ?? 'Не указан';
            if ($actual !== $filters['deal_result']) {
                continue;
            }
        }
        if (!empty($filters['lost_deal_reason'])) {
            $actual = clean_str($row['lost_deal_reason'] ?? null) ?? 'Не указана';
            if ($actual !== $filters['lost_deal_reason']) {
                continue;
            }
        }
        if (!empty($filters['appeal_funnel'])) {
            $group = deal_appeal_funnel_group(clean_str($row['deal_result'] ?? null));
            if ($group !== $filters['appeal_funnel']) {
                continue;
            }
        }
        $out[] = $row;
    }
    return $out;
}

/** Доп. фильтры воронки 1С для drill-down в детализацию. */
function apply_operations_1c_drill_filters(array $rows, array $filters): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!empty($filters['department'])) {
            $actual = clean_str($row['department'] ?? null) ?? 'Не указан';
            if ($actual !== $filters['department']) {
                continue;
            }
        }
        if (!empty($filters['category'])) {
            $actual = clean_str($row['related_service_type'] ?? null)
                ?? clean_str($row['category'] ?? null)
                ?? 'Не указана';
            if ($actual !== $filters['category']) {
                continue;
            }
        }
        if (!empty($filters['channel'])) {
            $actual = clean_str($row['channel'] ?? null) ?? 'Не указан';
            if ($actual !== $filters['channel']) {
                continue;
            }
        }
        if (!empty($filters['operation_type'])) {
            $sales = (float) ($row['sales_amount'] ?? 0);
            if ($filters['operation_type'] === 'refund' && $sales >= 0) {
                continue;
            }
            if ($filters['operation_type'] === 'sales' && $sales < 0) {
                continue;
            }
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * Нормализация drill-ключей (singular → plural) для apply_sales_filters.
 *
 * @param array<string, mixed> $filters
 * @return array<string, mixed>
 */
function normalize_drill_filters(array $filters): array
{
    $map = [
        'category' => 'categories',
        'channel' => 'channels',
        'client_type' => 'client_types',
        'card_type' => 'card_types',
        'client' => 'clients',
        'partner' => 'partners',
        'request_type' => 'request_types',
    ];
    foreach ($map as $singular => $plural) {
        if (!empty($filters[$singular]) && empty($filters[$plural])) {
            $filters[$plural] = is_array($filters[$singular]) ? $filters[$singular] : [$filters[$singular]];
        }
    }
    if (!empty($filters['team']) && empty($filters['teams'])) {
        $filters['teams'] = is_array($filters['team']) ? $filters['team'] : [$filters['team']];
    }
    return $filters;
}

/** Человекочитаемые метки активных drill-фильтров. */
function drill_filter_labels(array $filters): array
{
    $map = [
        'source' => 'Источник',
        'deal_status' => 'Статус',
        'deal_result' => 'Результат',
        'appeal_funnel' => 'Воронка',
        'lost_deal_reason' => 'Причина проигрыша',
        'department' => 'Подразделение 1С',
        'category' => 'Категория',
        'channel' => 'Канал',
        'client_type' => 'Тип клиента',
        'card_type' => 'Тип карты',
        'client' => 'Клиент',
        'operation_type' => 'Тип операции',
        'date_from' => 'Период с',
        'date_to' => 'Период по',
    ];
    $labels = [];
    foreach ($map as $key => $title) {
        if (empty($filters[$key])) {
            continue;
        }
        $val = $filters[$key];
        if ($key === 'operation_type') {
            $val = $val === 'refund' ? 'Возвраты' : 'Продажи';
        }
        if ($key === 'source') {
            $val = $val === '1c' ? '1С' : ($val === 'bitrix' ? 'Битрикс' : (string) $val);
        }
        $labels[] = ['key' => $key, 'label' => $title, 'value' => (string) $val];
    }
    if (!empty($filters['teams']) && is_array($filters['teams'])) {
        $labels[] = ['key' => 'teams', 'label' => 'Команда', 'value' => implode(', ', $filters['teams'])];
    }
    if (!empty($filters['clients']) && is_array($filters['clients'])) {
        $labels[] = ['key' => 'clients', 'label' => 'Клиент', 'value' => implode(', ', $filters['clients'])];
    }
    return $labels;
}
