<?php
/**
 * api/insights.php — советы и сигналы для руководителя.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('storage.php');
require_lib('filters.php');
require_lib('metrics.php');
require_lib('insights.php');

$filters = read_json_body();
if ($filters === []) {
    $filters = $_GET;
}

$payload = compute_insights_payload($filters);

json_response(array_merge(['ok' => true], $payload));
