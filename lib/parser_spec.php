<?php
/**
 * parser_spec.php
 *
 * Описание колонок парсера 1С, Битрикс и unified — из mapping.json.
 */

declare(strict_types=1);

require_once __DIR__ . '/mapping.php';
require_once __DIR__ . '/parse_1c.php';
require_once __DIR__ . '/bitrix_export.php';

/** @return list<array{index:int,field:string,label:string,note:string}> */
function parser_spec_one_c(): array
{
    $mapping = load_mapping();
    $notes = [
        'date_operation' => 'Колонка по индексу 0 (первая «Дата операции» в Excel)',
        'agent' => 'Сопоставление с settings.json → names_1c',
        'department' => 'Для unknown-агентов — department_map',
        'related_service_type' => 'Приоритет категории в unified',
        'sales_amount' => 'Отрицательные значения → is_refund',
    ];
    $out = [];
    foreach (($mapping['one_c']['columns'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $field = (string) ($row['field'] ?? '');
        $out[] = [
            'index' => (int) ($row['index'] ?? 0),
            'field' => $field,
            'label' => (string) ($row['label'] ?? $row['header'] ?? $field),
            'note' => $notes[$field] ?? '',
        ];
    }
    return $out;
}

/** @return list<array{header:string,field:string,note:string,profile?:string}> */
function parser_spec_bitrix(): array
{
    $mapping = load_mapping();
    $notes = [
        'deal_no' => 'ID / Номер сделки',
        'responsible_person' => 'Ответственный → names_bitrix',
        'deal_result' => 'В unified: «Успех» + стадии возврата',
        'sales_amount' => 'Из enrich.sales_amount_from',
        'profit_ex_vat' => 'Из enrich.profit_ex_vat_from',
    ];
    $out = [];
    foreach (($mapping['bitrix_profiles'] ?? []) as $pid => $profile) {
        if (!is_array($profile)) {
            continue;
        }
        $label = (string) ($profile['label'] ?? $pid);
        foreach (($profile['headers'] ?? []) as $header => $field) {
            if (!is_string($field) || str_starts_with($field, '_')) {
                continue;
            }
            $out[] = [
                'header' => (string) $header,
                'field' => $field,
                'note' => ($notes[$field] ?? '') . ' [' . $label . ']',
                'profile' => (string) $pid,
            ];
        }
        $enrich = $profile['enrich'] ?? [];
        if (is_array($enrich)) {
            $salesFrom = $enrich['sales_amount_from'] ?? null;
            if (is_string($salesFrom) && $salesFrom !== '') {
                $out[] = [
                    'header' => $salesFrom . ' → sales_amount',
                    'field' => 'sales_amount',
                    'note' => 'enrich [' . $label . ']',
                    'profile' => (string) $pid,
                ];
            }
        }
    }
    return $out;
}

/** @return list<array{field:string,label:string,sources:string,note:string}> */
function parser_spec_unified(): array
{
    return [
        ['field' => 'source', 'label' => 'Источник', 'sources' => '1c / bitrix', 'note' => ''],
        ['field' => 'date', 'label' => 'Дата', 'sources' => '1c: date_operation; bitrix: client_paid_at или deal_created_at', 'note' => ''],
        ['field' => 'agent_key', 'label' => 'Ключ агента', 'sources' => 'resolve_agent_*', 'note' => 'unknown:… если не найден в справочнике'],
        ['field' => 'agent_display', 'label' => 'Имя агента', 'sources' => 'name_display из settings', 'note' => ''],
        ['field' => 'agent_team', 'label' => 'Основная команда', 'sources' => 'teams[0] агента', 'note' => 'Для KPI и отображения'],
        ['field' => 'agent_teams', 'label' => 'Все команды', 'sources' => 'teams[] агента', 'note' => 'Фильтр по команде: пересечение'],
        ['field' => 'agent_is_active', 'label' => 'Активен', 'sources' => 'settings', 'note' => ''],
        ['field' => 'agent_raw', 'label' => 'Имя в выгрузке', 'sources' => '1c: agent; bitrix: responsible_person', 'note' => ''],
        ['field' => 'client', 'label' => 'Клиент', 'sources' => '1c / bitrix', 'note' => ''],
        ['field' => 'client_id', 'label' => 'ID клиента', 'sources' => '1c: id_crm; bitrix: id_client', 'note' => ''],
        ['field' => 'partner_or_supplier', 'label' => 'Партнёр / поставщик', 'sources' => '1c: supplier; bitrix: partner', 'note' => ''],
        ['field' => 'category', 'label' => 'Категория', 'sources' => '1c: related_service_type|category; bitrix: category', 'note' => 'Синонимы: settings.category_aliases'],
        ['field' => 'channel', 'label' => 'Канал', 'sources' => '1c / bitrix', 'note' => ''],
        ['field' => 'card_type', 'label' => 'Тип карты', 'sources' => '1c / bitrix', 'note' => ''],
        ['field' => 'client_type', 'label' => 'Тип клиента', 'sources' => '1c extra_by_header / bitrix', 'note' => ''],
        ['field' => 'request_type', 'label' => 'Тип запроса', 'sources' => 'bitrix; 1c via category_to_request_type', 'note' => ''],
        ['field' => 'sales_amount', 'label' => 'Сумма продажи', 'sources' => '1c / bitrix', 'note' => ''],
        ['field' => 'profit_ex_vat', 'label' => 'Прибыль без НДС', 'sources' => '1c / bitrix', 'note' => ''],
        ['field' => 'service_fee', 'label' => 'Сервисный сбор', 'sources' => '1c / bitrix', 'note' => ''],
        ['field' => 'is_refund', 'label' => 'Возврат', 'sources' => '1c: sales_amount < 0; bitrix: стадии возврата', 'note' => ''],
        ['field' => 'raw_id', 'label' => 'ID строки', 'sources' => '1c: order_no; bitrix: deal_no', 'note' => ''],
        ['field' => 'date_fallback_used', 'label' => 'Fallback даты', 'sources' => 'bitrix', 'note' => 'true, если взята deal_created_at'],
    ];
}
