<?php
/**
 * api/filters.php — варианты для выпадающих списков фильтров + статус загрузки.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('settings.php');
require_lib('storage.php');

$settings = load_settings();
$sales = storage_load_table('sales_unified');
$meta = storage_load_meta();

$teams = [];
$clients = [];
$partners = [];
$categories = [];
$channels = [];
$cardTypes = [];
$clientTypes = [];
$requestTypes = [];

foreach ($settings['agents'] ?? [] as $a) {
    foreach (agent_teams($a) as $t) {
        if ($t) {
            $teams[$t] = true;
        }
    }
}
foreach ($sales as $row) {
    foreach ($row['agent_teams'] ?? [($row['agent_team'] ?? '')] as $t) {
        if ($t) {
            $teams[$t] = true;
        }
    }
    if (!empty($row['agent_team'])) {
        $teams[$row['agent_team']] = true;
    }
    foreach (
        [
            'client' => &$clients,
            'partner_or_supplier' => &$partners,
            'category' => &$categories,
            'channel' => &$channels,
            'card_type' => &$cardTypes,
            'client_type' => &$clientTypes,
            'request_type' => &$requestTypes,
        ] as $col => &$bag
    ) {
        $v = clean_str($row[$col] ?? null);
        if ($v) {
            $bag[$v] = true;
        }
    }
    unset($bag);
}

$agentsActive = [];
$agentsInactive = [];
foreach ($settings['agents'] ?? [] as $a) {
    $opt = ['label' => $a['name_display'] ?? $a['agent_key'], 'value' => $a['agent_key']];
    if (!empty($a['is_active'])) {
        $agentsActive[] = $opt;
    } else {
        $agentsInactive[] = $opt;
    }
}

function opts_from_set(array $set): array
{
    $keys = array_keys($set);
    sort($keys, SORT_STRING);
    $out = [];
    foreach (array_slice($keys, 0, 500) as $k) {
        $out[] = ['label' => $k, 'value' => $k];
    }
    return $out;
}

json_response([
    'ok' => true,
    'backend' => storage_backend(),
    'meta' => $meta,
    'title' => $settings['app']['title'] ?? 'Дашборд продаж РС ТЛС',
    'defaults' => $settings['defaults'] ?? [],
    'options' => [
        'teams' => opts_from_set($teams),
        'agents_active' => $agentsActive,
        'agents_inactive' => $agentsInactive,
        'clients' => opts_from_set($clients),
        'partners' => opts_from_set($partners),
        'categories' => opts_from_set($categories),
        'channels' => opts_from_set($channels),
        'card_types' => opts_from_set($cardTypes),
        'client_types' => opts_from_set($clientTypes),
        'request_types' => opts_from_set($requestTypes),
    ],
]);
