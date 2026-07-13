<?php
/**
 * build_unified.php
 *
 * Собирает единую таблицу sales_unified:
 * - ВСЕ строки 1С (в т.ч. возвраты с отрицательной sales_amount)
 * - сделки Битрикс с результатом «Успех»
 * - сделки Битрикс на стадиях возврата — с отрицательной sales_amount
 *
 * Источники НЕ склеиваем между собой (нет дедупа 1С↔Битрикс).
 * Прибыль везде = profit_ex_vat.
 */

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

/** Стадии CRM, которые считаем возвратами (сумма в unified идёт со знаком минус). */
function bitrix_refund_statuses(?array $settings = null): array
{
    $settings = $settings ?? [];
    $configured = $settings['metrics']['bitrix_refund_statuses'] ?? null;
    if (is_array($configured) && $configured !== []) {
        return array_values(array_filter(array_map('clean_str', $configured)));
    }
    return ['Возврат подтверждён', 'Возврат'];
}

function is_bitrix_refund_deal(array $record, ?array $settings = null): bool
{
    $status = clean_str($record['deal_status'] ?? null);
    if ($status === null) {
        return false;
    }
    foreach (bitrix_refund_statuses($settings) as $refundStatus) {
        if ($status === $refundStatus) {
            return true;
        }
    }
    return mb_stripos($status, 'возврат') !== false;
}

/** Синонимы категорий: «Отельный билет» → «Отель» и т.п. */
function normalize_category(?string $category, ?array $settings = null): ?string
{
    $category = clean_str($category);
    if ($category === null) {
        return null;
    }
    $map = $settings['category_aliases'] ?? [];
    if (!is_array($map) || $map === []) {
        return $category;
    }
    $canonical = $map[$category] ?? null;
    return clean_str(is_string($canonical) ? $canonical : null) ?? $category;
}

/** Регистронезависимый lookup в map alias → canonical. */
function alias_lookup(?string $value, array $map): ?string
{
    $value = clean_str($value);
    if ($value === null || $map === []) {
        return null;
    }
    if (isset($map[$value]) && is_string($map[$value])) {
        return clean_str($map[$value]);
    }
    $lower = mb_strtolower($value);
    foreach ($map as $from => $to) {
        if (!is_string($from) || !is_string($to)) {
            continue;
        }
        if (mb_strtolower($from) === $lower) {
            return clean_str($to);
        }
    }
    return null;
}

/**
 * Тип запроса: явный request_type, иначе вывод из категории 1С (Air Tickets → Travel).
 *
 * @param list<string|null> $categoryHints сырые category / related_service_type / нормализованная category
 */
function resolve_request_type(?string $requestType, array $categoryHints, ?array $settings = null): ?string
{
    $settings = $settings ?? [];
    $rtMap = is_array($settings['request_type_aliases'] ?? null) ? $settings['request_type_aliases'] : [];
    $catMap = is_array($settings['category_to_request_type'] ?? null) ? $settings['category_to_request_type'] : [];

    $requestType = clean_str($requestType);
    if ($requestType !== null) {
        return alias_lookup($requestType, $rtMap) ?? $requestType;
    }
    foreach ($categoryHints as $hint) {
        $mapped = alias_lookup(is_string($hint) ? $hint : null, $catMap);
        if ($mapped !== null) {
            return $mapped;
        }
    }
    return null;
}

/**
 * @param list<array> $operations1c
 * @param list<array> $dealsBitrix
 * @return list<array>
 */
function build_sales_unified(array $operations1c, array $dealsBitrix, array $settings): array
{
    $success = $settings['metrics']['bitrix_success_value'] ?? 'Успех';
    $departmentMap = $settings['department_map'] ?? [];
    $rows = [];

    foreach ($operations1c as $record) {
        $sales = isset($record['sales_amount']) ? (float) $record['sales_amount'] : null;
        $resolved = resolve_agent_1c($record['agent'] ?? null, $settings, $record['department'] ?? null);
        $team = $resolved['team'];
        $agentTeams = $resolved['teams'] ?? [$team];
        // Если агент неизвестен — пробуем department_map
        if (str_starts_with((string) $resolved['agent_key'], 'unknown:')) {
            $dept = clean_str($record['department'] ?? null);
            if ($dept !== null) {
                foreach ($departmentMap as $key => $display) {
                    if ($display === $dept || $key === $dept) {
                        $team = $key;
                        break;
                    }
                }
                if ($team === $resolved['team']) {
                    $team = $dept;
                }
                $agentTeams = [$team];
            }
        }
        $rawCategory = clean_str($record['category'] ?? null);
        $relatedService = clean_str($record['related_service_type'] ?? null);
        $category = normalize_category($relatedService ?? $rawCategory, $settings);
        $requestType = resolve_request_type(null, [$rawCategory, $relatedService, $category], $settings);
        $rows[] = [
            'source' => '1c',
            'date' => $record['date_operation'] ?? null,
            'agent_key' => $resolved['agent_key'],
            'agent_display' => $resolved['name_display'],
            'agent_team' => $team ?: 'Без команды',
            'agent_teams' => $agentTeams,
            'agent_is_active' => $resolved['is_active'],
            'agent_raw' => clean_str($record['agent'] ?? null),
            'client' => clean_str($record['client'] ?? null),
            'client_id' => clean_str($record['id_crm'] ?? null),
            'partner_or_supplier' => clean_str($record['supplier'] ?? null),
            'category' => $category,
            'channel' => clean_str($record['channel'] ?? null),
            'card_type' => clean_str($record['card_type'] ?? null),
            'client_type' => clean_str($record['client_type'] ?? null) ?? '1С (нет типа клиента)',
            'request_type' => $requestType,
            'sales_amount' => $sales,
            'profit_ex_vat' => isset($record['profit_ex_vat']) ? to_float($record['profit_ex_vat']) : null,
            'service_fee' => isset($record['service_fee']) ? to_float($record['service_fee']) : null,
            'is_refund' => $sales !== null && $sales < 0,
            'raw_id' => clean_str($record['order_no'] ?? null),
            'date_fallback_used' => null,
        ];
    }

    foreach ($dealsBitrix as $record) {
        $result = clean_str($record['deal_result'] ?? null);
        $isRefund = is_bitrix_refund_deal($record, $settings);
        if ($result !== $success && !$isRefund) {
            continue;
        }
        $sales = isset($record['sales_amount']) ? to_float($record['sales_amount']) : null;
        // Возвраты CRM хранят сумму положительной → в unified как у 1С (минус).
        if ($isRefund && $sales !== null) {
            $sales = -abs($sales);
        }
        $resolved = resolve_agent_bitrix($record['responsible_person'] ?? null, $settings);
        $bTeams = $resolved['teams'] ?? [$resolved['team']];
        $dealNo = $record['deal_no'] ?? null;
        $rawId = null;
        if ($dealNo !== null && $dealNo !== '') {
            $rawId = is_numeric($dealNo) ? (string) (int) $dealNo : (string) $dealNo;
        }
        $rows[] = [
            'source' => 'bitrix',
            'date' => $record['date_for_sales'] ?? null,
            'agent_key' => $resolved['agent_key'],
            'agent_display' => $resolved['name_display'],
            'agent_team' => $resolved['team'] ?: 'Без команды',
            'agent_teams' => $bTeams,
            'agent_is_active' => $resolved['is_active'],
            'agent_raw' => clean_str($record['responsible_person'] ?? null),
            'client' => clean_str($record['client'] ?? null),
            'client_id' => clean_str($record['id_client'] ?? null),
            'partner_or_supplier' => clean_str($record['partner'] ?? null),
            'category' => normalize_category(clean_str($record['category'] ?? null), $settings),
            'channel' => clean_str($record['channel'] ?? null),
            'card_type' => clean_str($record['card_type'] ?? null),
            'client_type' => clean_str($record['client_type'] ?? null),
            'request_type' => resolve_request_type(
                clean_str($record['request_type'] ?? null),
                [
                    clean_str($record['category'] ?? null),
                    normalize_category(clean_str($record['category'] ?? null), $settings),
                ],
                $settings
            ),
            'sales_amount' => $sales,
            'profit_ex_vat' => isset($record['profit_ex_vat']) ? to_float($record['profit_ex_vat']) : null,
            'service_fee' => isset($record['service_fee']) ? to_float($record['service_fee']) : null,
            'is_refund' => $isRefund,
            'raw_id' => $rawId,
            'date_fallback_used' => $record['date_fallback_used'] ?? null,
        ];
    }

    return $rows;
}

/**
 * Справочник агентов для фильтров (из settings + неизвестные из продаж).
 * @return list<array>
 */
function build_agents_dim(array $settings, array $salesUnified): array
{
    $dim = [];
    foreach ($settings['agents'] ?? [] as $agent) {
        $dim[] = [
            'agent_key' => $agent['agent_key'],
            'name_display' => $agent['name_display'] ?? $agent['agent_key'],
            'team' => agent_primary_team($agent),
            'teams' => agent_teams($agent),
            'is_active' => (bool) ($agent['is_active'] ?? true),
            'is_unknown' => 0,
        ];
    }
    $known = array_column($dim, 'agent_key');
    $seen = [];
    foreach ($salesUnified as $row) {
        $key = $row['agent_key'] ?? '';
        if ($key === '' || in_array($key, $known, true) || isset($seen[$key])) {
            continue;
        }
        if (!str_starts_with($key, 'unknown:')) {
            continue;
        }
        $seen[$key] = true;
        $dim[] = [
            'agent_key' => $key,
            'name_display' => $row['agent_display'] ?? $key,
            'team' => $row['agent_team'] ?? 'Без команды',
            'teams' => $row['agent_teams'] ?? [($row['agent_team'] ?? 'Без команды')],
            'is_active' => false,
            'is_unknown' => 1,
        ];
    }
    return $dim;
}
