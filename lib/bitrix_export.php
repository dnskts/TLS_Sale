<?php
/**
 * bitrix_export.php — парсер CRM-отчёта «Отчет по сделкам» (export xlsx).
 */

declare(strict_types=1);

require_once __DIR__ . '/xlsx_reader.php';
require_once __DIR__ . '/settings.php';

/** Заголовки export → поля deals_bitrix (включая служебные для формул). */
function bitrix_export_header_aliases(): array
{
    return [
        'ID' => 'deal_no',
        'Название' => 'deal_title',
        'Дата создания' => 'deal_created_at',
        'Стадия' => 'deal_status',
        'Ответственный' => 'responsible_person',
        '% участия агента в продаже' => 'agent_sale_participation',
        'Тип клиента' => 'client_type',
        'Клиент' => 'client',
        'ID клиента' => 'id_client',
        'Тип карты' => 'card_type',
        'Категория' => 'category',
        'Канал связи' => 'channel',
        'Лид' => 'lead_id',
        'Тип запроса' => 'request_type',
        'Дата оказания услуги' => 'service_date',
        'Дата оплаты Клиентом' => 'client_paid_at',
        'Плановая дата закрытия' => 'planned_close_date',
        'Дата последней активности' => 'last_activity_at',
        'Источник' => 'lead_source',
        'Маркетинговый канал' => 'lead_source',
        'Количество звонков' => 'calls_count',
        'Количество встреч' => 'meetings_count',
        'Результат сделки' => 'deal_result',
        'Причина стадии Сделка проиграна' => 'lost_deal_reason',
        'Полное наименование организации' => 'partner',
        'Сервисный сбор' => 'service_fee',
        'Комиссия' => '_commission',
        'Всего к оплате Клиентом' => '_total_client_pay',
    ];
}

/** Маркеры шапки для авто-детекта (подмножество обязательных). */
function bitrix_export_fingerprint_headers(): array
{
    return [
        'ID',
        'Ответственный',
        'Результат сделки',
        'Всего к оплате Клиентом',
        'Комиссия',
        'Сервисный сбор',
        'Дата создания',
        'Стадия',
        'Клиент',
    ];
}

function bitrix_export_default_sheet(): string
{
    return 'Отчет по сделкам';
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function enrich_bitrix_export_row(array $row): array
{
    $row['sales_amount'] = to_float($row['_total_client_pay'] ?? null);
    $commission = to_float($row['_commission'] ?? null);
    $fee = to_float($row['service_fee'] ?? null);
    $row['profit_ex_vat'] = $commission + $fee;
    $row['profit'] = $row['profit_ex_vat'];
    unset($row['_total_client_pay'], $row['_commission']);

    $created = $row['deal_created_at'] ?? null;
    if (empty($row['client_paid_at'])) {
        $row['client_paid_at'] = null;
    } else {
        $row['client_paid_at'] = to_datetime_string($row['client_paid_at']);
    }
    foreach (['planned_close_date', 'last_activity_at'] as $dc) {
        if (!empty($row[$dc])) {
            $row[$dc] = to_datetime_string($row[$dc]);
        }
    }
    if ($row['client_paid_at']) {
        $row['date_for_sales'] = substr((string) $row['client_paid_at'], 0, 10);
        $row['date_fallback_used'] = false;
    } elseif ($created) {
        $row['date_for_sales'] = substr((string) $created, 0, 10);
        $row['date_fallback_used'] = true;
    } else {
        $row['date_for_sales'] = null;
        $row['date_fallback_used'] = null;
    }
    $row['source'] = 'bitrix';
    $row['bitrix_format'] = 'export';
    return $row;
}

/**
 * @return list<array<string, mixed>>
 */
function parse_bitrix_export(string $path, string $sheetName): array
{
    $rawRows = xlsx_read_sheet($path, $sheetName);
    if (count($rawRows) < 2) {
        return [];
    }

    $headerRow = $rawRows[0];
    $aliases = bitrix_export_header_aliases();
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
        foreach ([
            'deal_title', 'deal_status', 'responsible_person', 'client_type', 'client',
            'card_type', 'partner', 'category', 'channel', 'request_type',
            'deal_result', 'lost_deal_reason', 'id_client', 'agent_sale_participation', 'lead_source',
        ] as $c) {
            if (array_key_exists($c, $row)) {
                $row[$c] = clean_str($row[$c]);
            }
        }
        foreach (['deal_no', 'lead_id', 'service_fee', '_commission', '_total_client_pay', 'calls_count', 'meetings_count'] as $c) {
            if (array_key_exists($c, $row)) {
                $row[$c] = to_float($row[$c]);
            }
        }
        foreach (['deal_created_at', 'service_date', 'client_paid_at', 'planned_close_date', 'last_activity_at'] as $c) {
            if (array_key_exists($c, $row)) {
                $row[$c] = to_datetime_string($row[$c]);
            }
        }
        $out[] = enrich_bitrix_export_row($row);
    }
    return $out;
}

/**
 * @param list<array<string, mixed>> $deals
 * @return list<string>
 */
function bitrix_export_quality_warnings(array $deals): array
{
    if ($deals === []) {
        return ['bitrix_export: нет строк после парсинга'];
    }
    $total = count($deals);
    $noSales = 0;
    $noAgent = 0;
    $fallback = 0;
    foreach ($deals as $row) {
        $sales = $row['sales_amount'] ?? null;
        if ($sales === null || (float) $sales == 0.0) {
            $noSales++;
        }
        if (empty($row['responsible_person'])) {
            $noAgent++;
        }
        if (!empty($row['date_fallback_used'])) {
            $fallback++;
        }
    }
    $warnings = ['bitrix_format: export'];
    if ($noSales > 0) {
        $warnings[] = 'bitrix_export: без sales_amount (или 0): '
            . $noSales . ' / ' . $total . ' (' . round($noSales / $total * 100, 1) . '%)';
    }
    if ($fallback > 0) {
        $warnings[] = 'bitrix_export: date_fallback_used: '
            . $fallback . ' / ' . $total . ' (' . round($fallback / $total * 100, 1) . '%)';
    }
    if ($noAgent > 0) {
        $warnings[] = 'bitrix_export: без ответственного: ' . $noAgent;
    }
    return $warnings;
}
