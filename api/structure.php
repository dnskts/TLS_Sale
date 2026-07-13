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

json_response([
    'ok' => true,
    'category' => group_by_dimension($rows, 'category'),
    'channel' => group_by_dimension($rows, 'channel'),
    'client_type' => group_by_dimension($rows, 'client_type'),
    'card_type' => group_by_dimension($rows, 'card_type'),
    'request_type' => group_by_dimension($rows, 'request_type'),
    'partner' => group_by_dimension($rows, 'partner_or_supplier'),
]);
