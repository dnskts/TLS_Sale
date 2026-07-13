<?php
/**
 * api/kpi.php — лёгкая KPI-полоска без графиков.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('aggregates.php');
require_lib('kpi.php');

$filters = read_json_body();
if ($filters === []) {
    $filters = $_GET;
}

$rows = load_filtered_sales($filters);
$ops1c = load_filtered_operations_1c($filters);
$dealsBx = load_filtered_deals_bitrix($filters);

json_response([
    'ok' => true,
    'kpi' => build_kpi_payload($rows, $ops1c, $dealsBx, $filters),
]);
