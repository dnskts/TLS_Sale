<?php
/**
 * bitrix_export.php — парсер CRM-отчётов Битрикс (deals_export / universal).
 * Соответствия колонок и enrich: mapping.json → bitrix_profiles.
 */

declare(strict_types=1);

require_once __DIR__ . '/xlsx_reader.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/mapping.php';

/** Заголовки текущего профиля (по умолчанию deals_export) → поля deals_bitrix. */
function bitrix_export_header_aliases(?string $profileId = null): array
{
    $profileId = $profileId ?? bitrix_default_profile_id();
    $aliases = bitrix_header_aliases_for_profile($profileId);
    if ($aliases !== []) {
        return $aliases;
    }
    return bitrix_header_aliases_for_profile('deals_export', default_mapping());
}

/** Маркеры шапки для авто-детекта файла/листа. */
function bitrix_export_fingerprint_headers(?string $profileId = null): array
{
    $profileId = $profileId ?? bitrix_default_profile_id();
    $fp = bitrix_fingerprint_for_profile($profileId);
    if ($fp !== []) {
        return $fp;
    }
    // Union fingerprints of all profiles for input_detect scoring.
    $all = [];
    $mapping = load_mapping();
    foreach (array_keys($mapping['bitrix_profiles'] ?? []) as $pid) {
        foreach (bitrix_fingerprint_for_profile((string) $pid, $mapping) as $h) {
            $all[$h] = true;
        }
    }
    return array_keys($all);
}

function bitrix_export_default_sheet(): string
{
    $profile = bitrix_mapping_profile(bitrix_default_profile_id());
    $hint = $profile['sheet_hint'] ?? null;
    return is_string($hint) && $hint !== '' ? $hint : 'Отчет по сделкам';
}

/**
 * @param array<string, mixed> $row
 * @param array{sales_amount_from:?string,profit_ex_vat_from:list<string>} $enrich
 * @return array<string, mixed>
 */
function enrich_bitrix_export_row(array $row, ?array $enrich = null, string $profileId = 'deals_export'): array
{
    $enrich = $enrich ?? bitrix_enrich_config_for_profile($profileId);
    $salesFrom = $enrich['sales_amount_from'] ?? null;
    if ($salesFrom !== null && $salesFrom !== 'sales_amount') {
        $row['sales_amount'] = to_float($row[$salesFrom] ?? null);
    } elseif (!array_key_exists('sales_amount', $row) || $row['sales_amount'] === null || $row['sales_amount'] === '') {
        $row['sales_amount'] = to_float($row['_total_client_pay'] ?? null);
    } else {
        $row['sales_amount'] = to_float($row['sales_amount'] ?? null);
    }

    $profitParts = $enrich['profit_ex_vat_from'] ?? [];
    if ($profitParts === []) {
        $profitParts = ['_commission', 'service_fee'];
    }
    // Single field that already holds profit_ex_vat
    if (count($profitParts) === 1 && $profitParts[0] === 'profit_ex_vat') {
        $row['profit_ex_vat'] = to_float($row['profit_ex_vat'] ?? null);
    } else {
        $sum = 0.0;
        $any = false;
        foreach ($profitParts as $part) {
            if (array_key_exists($part, $row) && $row[$part] !== null && $row[$part] !== '') {
                $sum += (float) to_float($row[$part]);
                $any = true;
            }
        }
        $row['profit_ex_vat'] = $any ? $sum : to_float($row['profit_ex_vat'] ?? null);
    }
    $row['profit'] = array_key_exists('profit', $row) && $row['profit'] !== null && $row['profit'] !== ''
        ? to_float($row['profit'])
        : $row['profit_ex_vat'];

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
    $row['bitrix_format'] = $profileId;
    return $row;
}

/**
 * @return list<array<string, mixed>>
 */
function parse_bitrix_export(string $path, string $sheetName, ?string $profileId = null): array
{
    $rawRows = xlsx_read_sheet($path, $sheetName);
    if (count($rawRows) < 2) {
        return [];
    }

    $headerRow = $rawRows[0];
    $profileId = $profileId ?? detect_bitrix_mapping_profile($headerRow);
    $aliases = bitrix_export_header_aliases($profileId);
    $enrich = bitrix_enrich_config_for_profile($profileId);
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
            $row[$alias] = mapping_coerce_cell($alias, $line[$idx] ?? null);
        }
        if ($row === []) {
            continue;
        }
        // deal_no / lead_id — строки (ID в Excel часто похож на число)
        foreach (['deal_no', 'lead_id', 'id_client'] as $idField) {
            if (array_key_exists($idField, $row) && $row[$idField] !== null && $row[$idField] !== '') {
                $row[$idField] = (string) $row[$idField];
                if (is_numeric($row[$idField]) && floor((float) $row[$idField]) == (float) $row[$idField]) {
                    $row[$idField] = (string) (int) (float) $row[$idField];
                }
            }
        }
        $out[] = enrich_bitrix_export_row($row, $enrich, $profileId);
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
    $formats = [];
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
        $fmt = (string) ($row['bitrix_format'] ?? 'export');
        $formats[$fmt] = ($formats[$fmt] ?? 0) + 1;
    }
    $fmtLabel = implode(',', array_keys($formats));
    $warnings = ['bitrix_format: ' . $fmtLabel];
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
