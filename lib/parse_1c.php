<?php
/**
 * parse_1c.php
 *
 * Читает выгрузку 1С (Excel) и превращает строки в обычный массив PHP.
 *
 * Важно: колонки берём ПО НОМЕРУ (0, 1, 2…), а не по названию в шапке.
 * В 1С два столбца с одинаковым именем «Дата операции» — иначе перепутаем.
 */

declare(strict_types=1);

require_once __DIR__ . '/xlsx_reader.php';
require_once __DIR__ . '/settings.php';

/** Имена колонок по позиции (как в Python ONE_C_COLUMNS). */
function one_c_columns(): array
{
    return [
        'date_operation', 'datetime_operation', 'agent', 'issuing_agent', 'supplier',
        'card_type', 'case_raw', 'channel', 'category', 'id_crm',
        'case_status_change_date', 'client_from_case', 'id_client_from_case', 'related_company',
        'case_cost_codes', 'client', 'service_scheme', 'order_raw', 'department',
        'related_service_type', 'product', 'payment_date', 'realization_date',
        'sales_amount', 'profit', 'profit_ex_vat', 'supplier_commission', 'vat_commission',
        'markup', 'vat_markup', 'service_fee', 'vat_fee', 'sr', 'lr',
        'solid_bank_privilege', 'rs_cashback_points', 'points_ax', 'points_imp',
        'cashless', 'against_salary', 'certificate', 'loss_company', 'loss_employee', 'travelers',
    ];
}

/**
 * Загрузить operations_1c из файла.
 *
 * @return list<array<string,mixed>>
 */
function parse_1c(string $path, string $sheetName): array
{
    $rawRows = xlsx_read_sheet($path, $sheetName);
    if ($rawRows === []) {
        return [];
    }
    // Первая строка — шапка, данные со второй
    $dataRows = array_slice($rawRows, 1);
    $cols = one_c_columns();
    $colCount = count($cols);
    $stringCols = [
        'agent', 'issuing_agent', 'supplier', 'card_type', 'case_raw', 'channel', 'category',
        'id_crm', 'client_from_case', 'id_client_from_case', 'related_company', 'case_cost_codes',
        'client', 'service_scheme', 'order_raw', 'department', 'related_service_type', 'product',
        'certificate', 'travelers',
    ];
    $numericCols = [
        'sales_amount', 'profit', 'profit_ex_vat', 'supplier_commission', 'vat_commission',
        'markup', 'vat_markup', 'service_fee', 'vat_fee', 'sr', 'lr',
        'solid_bank_privilege', 'rs_cashback_points', 'points_ax', 'points_imp',
        'cashless', 'against_salary', 'loss_company', 'loss_employee',
    ];
    $out = [];
    foreach ($dataRows as $line) {
        // Пустая строка — пропускаем
        $hasValue = false;
        foreach ($line as $cell) {
            if ($cell !== null && $cell !== '') {
                $hasValue = true;
                break;
            }
        }
        if (!$hasValue) {
            continue;
        }
        $row = [];
        for ($i = 0; $i < $colCount; $i++) {
            $row[$cols[$i]] = $line[$i] ?? null;
        }
        foreach ($stringCols as $c) {
            $row[$c] = clean_str($row[$c] ?? null);
        }
        foreach (['id_crm', 'id_client_from_case'] as $c) {
            if ($row[$c] !== null) {
                $row[$c] = (string) $row[$c];
            }
        }
        foreach ($numericCols as $c) {
            $row[$c] = to_float($row[$c] ?? null);
        }
        $row['date_operation'] = to_date_string($row['date_operation'] ?? null);
        $row['datetime_operation'] = to_datetime_string($row['datetime_operation'] ?? null);
        foreach (['case_status_change_date', 'payment_date', 'realization_date'] as $c) {
            $row[$c] = to_date_string($row[$c] ?? null);
        }
        // case_id из «Кейс»: 00000212345 → 12345
        $caseRaw = $row['case_raw'] ?? '';
        $row['case_id'] = null;
        if ($caseRaw && preg_match('/000002(\d+)/', (string) $caseRaw, $m)) {
            $row['case_id'] = $m[1];
        }
        // order_no из заказа: 0000-123456
        $orderRaw = $row['order_raw'] ?? '';
        $row['order_no'] = null;
        if ($orderRaw && preg_match('/(0000-\d+)/', (string) $orderRaw, $m)) {
            $row['order_no'] = $m[1];
        }
        $row['source'] = '1c';
        $out[] = $row;
    }
    return $out;
}
