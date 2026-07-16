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

function parse_1c_case_id(mixed $value): ?string
{
    $raw = clean_str($value === null ? null : (string) $value);
    if ($raw === null || !preg_match('/Кейс\s+(\d+)/iu', $raw, $m)) {
        return null;
    }
    $normalized = ltrim($m[1], '0');
    return $normalized === '' ? '0' : $normalized;
}

function parse_1c_order_no(mixed $value): ?string
{
    $raw = clean_str($value === null ? null : (string) $value);
    if ($raw === null || !preg_match('/(0000-\d+)/', $raw, $m)) {
        return null;
    }
    return $m[1];
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

        // Канонические поля сразу заменяют длинные исходные строки.
        $row['case_id'] = parse_1c_case_id($row['case_id'] ?? null);
        $row['deal_no'] = parse_1c_order_no($row['deal_no'] ?? null);
        // Если I d CRM пуст, используем «ID клиента из кейса» (индекс 13),
        // не сохраняя отдельное техническое поле.
        if (empty($row['client_id'])) {
            $row['client_id'] = mapping_coerce_cell('client_id', $line[13] ?? null);
        }

        $row['source'] = '1c';
        $row['is_refund'] = isset($row['sales_amount']) && (float) $row['sales_amount'] < 0;
        $out[] = $row;
    }
    return $out;
}
