<?php
/**
 * bitrix_export.php — парсер сырой выгрузки Б24 NEW (deals_export).
 * Соответствия колонок и enrich: mapping.json → bitrix_profiles.
 */

declare(strict_types=1);

require_once __DIR__ . '/xlsx_reader.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/mapping.php';
require_once __DIR__ . '/mapping_formula.php';

/** Коэффициент НДС по дате из версионируемой таблицы профиля mapping.json. */
function bitrix_vat_factor_for_date(mixed $value, string $profileId = 'deals_export'): float
{
    $date = to_date_string($value);
    $profile = bitrix_mapping_profile($profileId) ?? [];
    $rates = is_array($profile['vat_rates'] ?? null) ? $profile['vat_rates'] : [];
    $selected = 1.22;
    usort($rates, static fn(array $a, array $b): int =>
        strcmp((string) ($a['effective_from'] ?? ''), (string) ($b['effective_from'] ?? ''))
    );
    foreach ($rates as $rate) {
        $from = clean_str($rate['effective_from'] ?? null);
        $factor = to_float($rate['factor'] ?? null);
        if ($from === null || $factor === null || $factor <= 0) {
            continue;
        }
        if ($date === null || $date >= $from) {
            $selected = $factor;
        }
    }
    return $selected;
}

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
function enrich_bitrix_export_row(array $row, ?array $enrich = null, string $profileId = 'deals_export', bool $skipSales = false, bool $skipProfit = false): array
{
    $enrich = $enrich ?? bitrix_enrich_config_for_profile($profileId);
    if (!$skipSales) {
        $salesFrom = $enrich['sales_amount_from'] ?? null;
        if ($salesFrom !== null && $salesFrom !== 'sales_amount') {
            $row['sales_amount'] = to_float($row[$salesFrom] ?? null);
        } elseif (!array_key_exists('sales_amount', $row) || $row['sales_amount'] === null || $row['sales_amount'] === '') {
            $row['sales_amount'] = to_float($row['_total_client_pay'] ?? null);
        } else {
            $row['sales_amount'] = to_float($row['sales_amount'] ?? null);
        }
    } else {
        $row['sales_amount'] = to_float($row['sales_amount'] ?? null);
    }

    if (!$skipProfit) {
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
    } else {
        $row['profit_ex_vat'] = to_float($row['profit_ex_vat'] ?? null);
    }
    $row['profit'] = array_key_exists('profit', $row) && $row['profit'] !== null && $row['profit'] !== ''
        ? to_float($row['profit'])
        : $row['profit_ex_vat'];

    unset($row['_total_client_pay'], $row['_commission']);

    if (empty($row['date_operation'])) {
        $row['date_operation'] = null;
    } else {
        $row['date_operation'] = to_date_string($row['date_operation']);
    }
    foreach (['planned_close_date', 'last_activity_at'] as $dc) {
        if (!empty($row[$dc])) {
            $row[$dc] = to_datetime_string($row[$dc]);
        }
    }
    if ($row['date_operation']) {
        $row['date_for_sales'] = substr((string) $row['date_operation'], 0, 10);
        $row['date_fallback_used'] = false;
    } else {
        $row['date_for_sales'] = null;
        $row['date_fallback_used'] = false;
    }
    $row['payment_date'] = $row['date_operation'];
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
    $formulas = bitrix_formulas_for_profile($profileId);
    $enrich = bitrix_enrich_config_for_profile($profileId);
    $map = [];
    $headerNormByIdx = [];
    foreach ($headerRow as $idx => $title) {
        $norm = clean_str(str_replace("\n", ' ', (string) $title)) ?? '';
        $headerNormByIdx[(int) $idx] = $norm;
        if ($norm !== '' && isset($aliases[$norm])) {
            $map[$idx] = $aliases[$norm];
        }
    }

    $out = [];
    foreach (array_slice($rawRows, 1) as $line) {
        $excelByHeader = [];
        foreach ($headerNormByIdx as $idx => $norm) {
            if ($norm === '') {
                continue;
            }
            $excelByHeader[$norm] = $line[$idx] ?? null;
        }

        $row = [];
        foreach ($map as $idx => $alias) {
            $value = mapping_coerce_cell($alias, $line[$idx] ?? null);
            // Несколько заголовков могут вести в одно поле (например,
            // Компания | Полное наименование). Пустой fallback не затирает значение.
            if (!array_key_exists($alias, $row) || $value !== null && $value !== '') {
                $row[$alias] = $value;
            }
        }
        if ($row === [] && $formulas === []) {
            continue;
        }
        // Бизнес-ID всегда строки.
        foreach (['deal_no', 'case_id', 'client_id'] as $idField) {
            if (array_key_exists($idField, $row) && $row[$idField] !== null && $row[$idField] !== '') {
                $row[$idField] = (string) $row[$idField];
                if (is_numeric($row[$idField]) && floor((float) $row[$idField]) == (float) $row[$idField]) {
                    $row[$idField] = (string) (int) (float) $row[$idField];
                }
            }
        }

        // Ставка определяется до арифметических формул. Если дата оплаты ещё
        // не выгружается, service/created date используются только для ставки,
        // но не становятся датой финансовой продажи.
        $vatDate = $row['date_operation'] ?? $row['service_date'] ?? $row['deal_created_at'] ?? null;
        $row['vat_factor'] = bitrix_vat_factor_for_date($vatDate, $profileId);
        $row = mapping_apply_formulas($row, $formulas, $excelByHeader);
        $case = clean_str($row['case_id'] ?? null);
        if ($case !== null && preg_match('/\d+/', $case, $m)) {
            $normalized = ltrim($m[0], '0');
            $row['case_id'] = $normalized === '' ? '0' : $normalized;
        }
        $skipSales = array_key_exists('sales_amount', $formulas);
        $skipProfit = array_key_exists('profit_ex_vat', $formulas);
        $out[] = enrich_bitrix_export_row($row, $enrich, $profileId, $skipSales, $skipProfit);
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
    $noSalesDate = 0;
    $formats = [];
    foreach ($deals as $row) {
        $sales = $row['sales_amount'] ?? null;
        if ($sales === null || (float) $sales == 0.0) {
            $noSales++;
        }
        if (empty($row['agent'])) {
            $noAgent++;
        }
        if (!empty($row['date_fallback_used'])) {
            $fallback++;
        }
        if (empty($row['date_for_sales'])) {
            $noSalesDate++;
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
    if ($noSalesDate > 0) {
        $warnings[] = 'bitrix_export: без «Дата оплаты клиентом» и date_for_sales: '
            . $noSalesDate . ' / ' . $total . ' (' . round($noSalesDate / $total * 100, 1) . '%)';
    }
    if ($noAgent > 0) {
        $warnings[] = 'bitrix_export: без ответственного: ' . $noAgent;
    }
    return $warnings;
}
