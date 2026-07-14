<?php
/**
 * parse_1c.php
 *
 * Читает выгрузку 1С (Excel) → строки с полями парсера из mapping.json.
 *
 * Колонки берутся ПО НОМЕРУ (index в mapping one_c.columns).
 * Имя поля (deal_no, sales_amount, …) задаётся вами в таблице маппинга.
 */

declare(strict_types=1);

require_once __DIR__ . '/xlsx_reader.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/mapping.php';

/** @return array<int, string> index => field */
function one_c_columns(): array
{
    return one_c_columns_from_mapping();
}

/**
 * @return list<array<string,mixed>>
 */
function parse_1c(string $path, string $sheetName): array
{
    $rawRows = xlsx_read_sheet($path, $sheetName);
    if ($rawRows === []) {
        return [];
    }
    $headerRow = $rawRows[0] ?? [];
    $dataRows = array_slice($rawRows, 1);
    /** @var array<int, string> index => field */
    $cols = one_c_columns();
    $extraByHeader = one_c_extra_by_header();
    /** @var array<string, int> */
    $extraCols = [];
    foreach ($headerRow as $idx => $title) {
        $norm = clean_str(str_replace("\n", ' ', (string) $title));
        if ($norm !== null && isset($extraByHeader[$norm])) {
            $extraCols[$extraByHeader[$norm]] = (int) $idx;
        }
    }

    $out = [];
    foreach ($dataRows as $line) {
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
        foreach ($cols as $excelIdx => $field) {
            $row[$field] = mapping_coerce_cell($field, $line[$excelIdx] ?? null);
        }
        foreach ($extraCols as $field => $colIdx) {
            if (!array_key_exists($field, $row)) {
                $row[$field] = mapping_coerce_cell($field, $line[$colIdx] ?? null);
            }
        }

        // Кейс / сделка: номер из case_raw или deal_no (одно бизнес-поле в маппинге)
        $caseRaw = $row['case_raw'] ?? $row['deal_no'] ?? '';
        $row['case_id'] = null;
        if ($caseRaw !== null && $caseRaw !== '' && preg_match('/000002(\d+)/', (string) $caseRaw, $m)) {
            $row['case_id'] = $m[1];
        }
        if (!isset($row['case_raw']) && isset($row['deal_no'])) {
            $row['case_raw'] = $row['deal_no'];
        }

        $orderRaw = $row['order_raw'] ?? '';
        $row['order_no'] = null;
        if ($orderRaw !== null && $orderRaw !== '' && preg_match('/(0000-\d+)/', (string) $orderRaw, $m)) {
            $row['order_no'] = $m[1];
        }

        $row['source'] = '1c';
        if (!array_key_exists('client_type', $row)) {
            $row['client_type'] = null;
        }
        $out[] = $row;
    }
    return $out;
}
