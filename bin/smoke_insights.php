<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('storage.php');
require_lib('filters.php');
require_lib('metrics.php');
require_lib('insights.php');
$p = compute_insights_payload([]);
echo 'signals=' . count($p['signals']) . ' agent_cards=' . count($p['agent_cards']) . ' teams=' . count($p['teams']) . PHP_EOL;
