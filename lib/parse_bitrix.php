<?php
/**
 * parse_bitrix.php
 *
 * Читает выгрузку Битрикс24 (Excel) по названиям колонок в шапке.
 * Берём ВСЕ сделки (не только «Успех») — для воронки нужны и проигранные.
 *
 * Дата для продаж: client_paid_at, если пусто — deal_created_at (date_fallback_used).
 */

declare(strict_types=1);

require_once __DIR__ . '/xlsx_reader.php';
require_once __DIR__ . '/settings.php';

/** Русское название колонки в Excel → короткое имя в коде */
function bitrix_header_aliases(): array
{
    return [
        'Номер сделки' => 'deal_no',
        'Название сделки' => 'deal_title',
        '% участия агента в продаже*' => 'agent_sale_participation',
        'Дата создания сделки' => 'deal_created_at',
        'Статус сделки' => 'deal_status',
        'Ответственное лицо' => 'responsible_person',
        'Тип клиента' => 'client_type',
        'Клиент' => 'client',
        'Менеджер' => 'manager',
        'ID клиента' => 'id_client',
        'Дата оплаты Клиентом' => 'client_paid_at',
        'Тип карты' => 'card_type',
        'Партнер' => 'partner',
        'Категория' => 'category',
        'Канал связи' => 'channel',
        'Сумма продажи' => 'sales_amount',
        'Прибыль' => 'profit',
        'Прибыль без НДС' => 'profit_ex_vat',
        'Сервисный сбор' => 'service_fee',
        'Тип запроса' => 'request_type',
        'Лид' => 'lead_id',
        'Номер счёта' => 'invoice_no',
        'Дата оказания услуги' => 'service_date',
        'Результат сделки' => 'deal_result',
        'Причина стадии Сделка проиграна' => 'lost_deal_reason',
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function parse_bitrix(string $path, string $sheetName): array
{
    $rawRows = xlsx_read_sheet($path, $sheetName);
    if (count($rawRows) < 2) {
        return [];
    }
    $headerRow = $rawRows[0];
    $aliases = bitrix_header_aliases();
    // Индекс колонки → alias
    $map = [];
    foreach ($headerRow as $idx => $title) {
        $norm = clean_str(str_replace("\n", ' ', (string) $title)) ?? '';
        if ($norm !== '' && isset($aliases[$norm])) {
            $map[$idx] = $aliases[$norm];
        }
    }

    $out = [];
    foreach (array_slice($rawRows, 1) as $line) {
        $row = [];
        foreach ($map as $idx => $alias) {
            $row[$alias] = $line[$idx] ?? null;
        }
        if ($row === []) {
            continue;
        }
        // Строковые поля
        foreach ([
            'deal_title', 'deal_status', 'responsible_person', 'client_type', 'client',
            'manager', 'card_type', 'partner', 'category', 'channel', 'request_type',
            'invoice_no', 'deal_result', 'lost_deal_reason', 'id_client',
        ] as $c) {
            if (array_key_exists($c, $row)) {
                $row[$c] = clean_str($row[$c]);
            }
        }
        foreach (['sales_amount', 'profit', 'profit_ex_vat', 'service_fee', 'deal_no', 'lead_id'] as $c) {
            if (array_key_exists($c, $row)) {
                $row[$c] = to_float($row[$c]);
            }
        }
        foreach (['deal_created_at', 'client_paid_at', 'service_date'] as $c) {
            if (array_key_exists($c, $row)) {
                $row[$c] = to_datetime_string($row[$c]);
            }
        }
        // Дата для продаж: оплата клиентом или дата создания
        $paid = $row['client_paid_at'] ?? null;
        $created = $row['deal_created_at'] ?? null;
        if ($paid) {
            $row['date_for_sales'] = substr($paid, 0, 10);
            $row['date_fallback_used'] = false;
        } elseif ($created) {
            $row['date_for_sales'] = substr($created, 0, 10);
            $row['date_fallback_used'] = true;
        } else {
            $row['date_for_sales'] = null;
            $row['date_fallback_used'] = null;
        }
        $row['source'] = 'bitrix';
        $out[] = $row;
    }
    return $out;
}
