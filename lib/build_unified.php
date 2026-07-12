<?php
/**
 * build_unified.php
 *
 * Собирает единую таблицу sales_unified:
 * - ВСЕ строки 1С
 * - только сделки Битрикс с результатом «Успех»
 *
 * Источники НЕ склеиваем между собой (нет дедупа 1С↔Битрикс).
 * Прибыль везде = profit_ex_vat.
 */

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

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
        $category = clean_str($record['related_service_type'] ?? null)
            ?? clean_str($record['category'] ?? null);
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
            'client_type' => null,
            'request_type' => null,
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
        if ($result !== $success) {
            continue;
        }
        $sales = isset($record['sales_amount']) ? to_float($record['sales_amount']) : null;
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
            'category' => clean_str($record['category'] ?? null),
            'channel' => clean_str($record['channel'] ?? null),
            'card_type' => clean_str($record['card_type'] ?? null),
            'client_type' => clean_str($record['client_type'] ?? null),
            'request_type' => clean_str($record['request_type'] ?? null),
            'sales_amount' => $sales,
            'profit_ex_vat' => isset($record['profit_ex_vat']) ? to_float($record['profit_ex_vat']) : null,
            'service_fee' => isset($record['service_fee']) ? to_float($record['service_fee']) : null,
            'is_refund' => false,
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
