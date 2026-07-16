<?php
/**
 * Generate mapping.json from current parser defaults + sample headers where aligned.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('mapping.php');
require_lib('xlsx_reader.php');

// Always seed from built-in defaults, not from existing mapping.json
$base = default_mapping();
$oneCBaseCols = $base['one_c']['columns'];
$fieldList = array_column($oneCBaseCols, 'field');
$oneCLabels = [];
foreach ($oneCBaseCols as $row) {
    $oneCLabels[$row['field']] = $row['label'] ?? $row['header'] ?? $row['field'];
}

// Prefer actual sample headers for 1C when count matches, else labels.
$oneCSampleHeaders = [];
$oneCPath = dirname(__DIR__) . '/выгрузки с прода/1C 14.07.xlsx';
if (is_file($oneCPath)) {
    $rows = xlsx_read_first_rows($oneCPath, 'TDSheet', 1);
    foreach (($rows[0] ?? []) as $i => $t) {
        $oneCSampleHeaders[(int) $i] = clean_str(str_replace("\n", ' ', (string) $t)) ?? '';
    }
}

$oneCColumns = [];
foreach ($oneCBaseCols as $baseRow) {
    $field = (string) ($baseRow['field'] ?? '');
    $i = (int) ($baseRow['index'] ?? -1);
    if ($field === '' || $i < 0) {
        continue;
    }
    $header = $oneCLabels[$field] ?? $field;
    if (isset($oneCSampleHeaders[$i]) && $oneCSampleHeaders[$i] !== '') {
        $header = $oneCSampleHeaders[$i];
    }
    $oneCColumns[] = [
        'index' => $i,
        'field' => $field,
        'header' => $header,
        'label' => $oneCLabels[$field] ?? $field,
    ];
}

$dealsExportHeaders = $base['bitrix_profiles']['deals_export']['headers'];

$mapping = $base;
$mapping['one_c']['columns'] = $oneCColumns;
$mapping['bitrix_profiles']['deals_export']['headers'] = $dealsExportHeaders;
$mapping['canonical_fields'] = [
    'one_c' => array_values(array_unique(array_merge(
        $fieldList,
        array_values($base['one_c']['extra_by_header'] ?? [])
    ))),
    'bitrix' => array_values(array_unique(array_merge(
        array_values($dealsExportHeaders),
        array_keys($base['bitrix_profiles']['deals_export']['formulas'] ?? []),
        ['vat_factor', 'date_for_sales', 'date_fallback_used', 'source', 'bitrix_format']
    ))),
];

$path = dirname(__DIR__) . '/mapping.json';
$json = json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "encode failed\n");
    exit(1);
}
file_put_contents($path, $json . "\n");
echo "Wrote $path (" . strlen($json) . " bytes)\n";
echo "one_c columns: " . count($oneCColumns) . "\n";
echo "deals_export headers: " . count($dealsExportHeaders) . "\n";
