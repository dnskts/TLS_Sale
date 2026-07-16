<?php
/**
 * api/structure.php — структура продаж по измерениям.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('aggregates.php');
require_lib('metrics.php');

$filters = read_json_body() ?: $_GET;
$rows = load_filtered_sales($filters);
$withDimension = static fn(array $source, string $field): array =>
    array_values(array_filter($source, static fn(array $row): bool =>
        clean_str($row[$field] ?? null) !== null
    ));

json_response([
    'ok' => true,
    'category' => group_by_dimension_metric($rows, 'category', 'sales', 12),
    'channel' => group_by_dimension_metric($rows, 'channel', 'sales', 12),
    'client_type' => group_by_dimension_metric($rows, 'client_type', 'sales', 12),
    'card_type' => group_by_dimension_metric($rows, 'card_type', 'sales', 12),
    'request_type' => group_by_dimension_metric($rows, 'request_type', 'sales', 12),
    'partner' => group_by_dimension_metric($rows, 'partner_or_supplier', 'sales', 12),
    'country' => group_by_dimension_metric($withDimension($rows, 'country'), 'country', 'sales', 12),
    'city' => group_by_dimension_metric($withDimension($rows, 'city'), 'city', 'sales', 12),
    'hotel' => group_by_dimension_metric($withDimension($rows, 'hotel'), 'hotel', 'sales', 12),
]);
