<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
file_put_contents('php://memory', '');
require __DIR__ . '/../lib/bootstrap.php';
require_lib('storage.php');
require_lib('filters.php');
require_lib('metrics.php');
$filters = ['date_from' => '2026-07-01', 'date_to' => '2026-07-12', 'source' => 'all', 'teams' => [], 'agents' => []];
$rows = apply_sales_filters(storage_load_table('sales_unified'), $filters);
$s = summarize_sales($rows);
echo "rows=" . count($rows) . " sales=" . $s['sales_total'] . "\n";
