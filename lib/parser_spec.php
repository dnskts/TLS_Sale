<?php
/**
 * parser_spec.php
 *
 * Описание колонок парсера 1С, Битрикс и unified-таблицы для страницы parser_spec.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/parse_1c.php';
require_once __DIR__ . '/bitrix_export.php';

/** @return list<array{index:int,field:string,label:string,note:string}> */
function parser_spec_one_c(): array
{
    $labels = [
        'date_operation' => 'Дата операции',
        'datetime_operation' => 'Дата и время операции',
        'agent' => 'Агент',
        'issuing_agent' => 'Агент, выдавший карту',
        'supplier' => 'Поставщик',
        'card_type' => 'Тип карты',
        'case_raw' => 'Обращение',
        'channel' => 'Канал связи',
        'category' => 'Категория',
        'id_crm' => 'ID CRM',
        'case_status_change_date' => 'Дата изменения статуса обращения',
        'client_from_case' => 'Клиент из обращения',
        'id_client_from_case' => 'ID клиента из обращения',
        'related_company' => 'Связанная компания',
        'case_cost_codes' => 'Коды затрат обращения',
        'client' => 'Клиент',
        'service_scheme' => 'Схема обслуживания',
        'order_raw' => 'Заказ',
        'department' => 'Подразделение',
        'related_service_type' => 'Тип связанной услуги',
        'product' => 'Продукт',
        'payment_date' => 'Дата оплаты',
        'realization_date' => 'Дата реализации',
        'sales_amount' => 'Сумма продажи',
        'profit' => 'Прибыль',
        'profit_ex_vat' => 'Прибыль без НДС',
        'supplier_commission' => 'Комиссия поставщика',
        'vat_commission' => 'НДС комиссии',
        'markup' => 'Наценка',
        'vat_markup' => 'НДС наценки',
        'service_fee' => 'Сервисный сбор',
        'vat_fee' => 'НДС сбора',
        'sr' => 'SR',
        'lr' => 'LR',
        'solid_bank_privilege' => 'Solid Bank Privilege',
        'rs_cashback_points' => 'Баллы кэшбэка РС',
        'points_ax' => 'Баллы AX',
        'points_imp' => 'Баллы IMP',
        'cashless' => 'Безнал',
        'against_salary' => 'Против зарплаты',
        'certificate' => 'Сертификат',
        'loss_company' => 'Убыток компании',
        'loss_employee' => 'Убыток сотрудника',
        'travelers' => 'Путешественники',
    ];
    $notes = [
        'date_operation' => 'Колонка по индексу 0 (первая «Дата операции» в Excel)',
        'agent' => 'Сопоставление с settings.json → names_1c',
        'department' => 'Для unknown-агентов — department_map',
        'related_service_type' => 'Приоритет категории в unified',
        'sales_amount' => 'Отрицательные значения → is_refund',
    ];
    $cols = one_c_columns();
    $out = [];
    foreach ($cols as $i => $field) {
        $out[] = [
            'index' => $i,
            'field' => $field,
            'label' => $labels[$field] ?? $field,
            'note' => $notes[$field] ?? '',
        ];
    }
    return $out;
}

/** @return list<array{header:string,field:string,note:string}> */
function parser_spec_bitrix(): array
{
    $notes = [
        'deal_no' => 'export: колонка ID',
        'responsible_person' => 'export: Ответственный → names_bitrix',
        'client_paid_at' => 'В export нет; date_for_sales из Дата создания (fallback)',
        'deal_created_at' => 'export: Дата создания',
        'deal_result' => 'В unified только «Успех»',
        'sales_amount' => 'Формула: Всего к оплате Клиентом',
        'profit_ex_vat' => 'Формула: Комиссия + Сервисный сбор',
        'service_fee' => 'export: Сервисный сбор',
    ];
    $out = [];
    foreach (bitrix_export_header_aliases() as $header => $field) {
        if (str_starts_with($field, '_')) {
            continue;
        }
        $out[] = [
            'header' => $header,
            'field' => $field,
            'note' => $notes[$field] ?? '',
        ];
    }
    $out[] = [
        'header' => 'Всего к оплате Клиентом',
        'field' => 'sales_amount',
        'note' => 'Источник для sales_amount (не alias, вычисляется)',
    ];
    $out[] = [
        'header' => 'Комиссия + Сервисный сбор',
        'field' => 'profit_ex_vat',
        'note' => 'Источник для profit_ex_vat (вычисляется)',
    ];
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
        ['field' => 'category', 'label' => 'Категория', 'sources' => '1c: related_service_type|category; bitrix: category', 'note' => ''],
        ['field' => 'channel', 'label' => 'Канал', 'sources' => '1c / bitrix', 'note' => ''],
        ['field' => 'card_type', 'label' => 'Тип карты', 'sources' => '1c / bitrix', 'note' => ''],
        ['field' => 'client_type', 'label' => 'Тип клиента', 'sources' => 'bitrix', 'note' => ''],
        ['field' => 'request_type', 'label' => 'Тип запроса', 'sources' => 'bitrix', 'note' => ''],
        ['field' => 'sales_amount', 'label' => 'Сумма продажи', 'sources' => '1c / bitrix', 'note' => ''],
        ['field' => 'profit_ex_vat', 'label' => 'Прибыль без НДС', 'sources' => '1c / bitrix', 'note' => ''],
        ['field' => 'service_fee', 'label' => 'Сервисный сбор', 'sources' => '1c / bitrix', 'note' => ''],
        ['field' => 'is_refund', 'label' => 'Возврат', 'sources' => '1c: sales_amount < 0', 'note' => ''],
        ['field' => 'raw_id', 'label' => 'ID строки', 'sources' => '1c: order_no; bitrix: deal_no', 'note' => ''],
        ['field' => 'date_fallback_used', 'label' => 'Fallback даты', 'sources' => 'bitrix', 'note' => 'true, если взята deal_created_at'],
    ];
}
