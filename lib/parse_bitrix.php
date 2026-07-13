<?php
/**
 * parse_bitrix.php — сделки Битрикс (CRM export xlsx).
 */

declare(strict_types=1);

require_once __DIR__ . '/bitrix_export.php';
require_once __DIR__ . '/settings.php';

/** @deprecated Используйте bitrix_export_header_aliases(); оставлено для parser_spec. */
function bitrix_header_aliases(): array
{
    $out = [];
    foreach (bitrix_export_header_aliases() as $header => $field) {
        if (!str_starts_with($field, '_')) {
            $out[$header] = $field;
        }
    }
    $out['Сумма продажи'] = 'sales_amount';
    $out['Прибыль без НДС'] = 'profit_ex_vat';
    $out['Прибыль'] = 'profit';
    $out['Дата оплаты Клиентом'] = 'client_paid_at';
    $out['Партнер'] = 'partner';
    $out['Номер счёта'] = 'invoice_no';
    $out['Менеджер'] = 'manager';
    $out['Номер сделки'] = 'deal_no';
    $out['Название сделки'] = 'deal_title';
    $out['Дата создания сделки'] = 'deal_created_at';
    $out['Статус сделки'] = 'deal_status';
    $out['Ответственное лицо'] = 'responsible_person';
    return $out;
}

/**
 * @return list<array<string,mixed>>
 */
function parse_bitrix(string $path, string $sheetName): array
{
    return parse_bitrix_export($path, $sheetName);
}
