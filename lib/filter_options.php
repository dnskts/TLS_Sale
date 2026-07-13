<?php
/**
 * filter_options.php — списки значений для фильтров дашборда + кэш после pipeline.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/storage.php';

function filter_options_cache_path(): string
{
    return project_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'filter_options.json';
}

function filter_opts_from_set(array $set): array
{
    $keys = array_keys($set);
    sort($keys, SORT_STRING);
    $out = [];
    foreach (array_slice($keys, 0, 500) as $k) {
        $out[] = ['label' => $k, 'value' => $k];
    }
    return $out;
}

/**
 * @return array{teams: list, agents_active: list, agents_inactive: list, clients: list, partners: list, categories: list, channels: list, card_types: list, client_types: list, request_types: list}
 */
function build_filter_options(array $sales, array $settings): array
{
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

    return [
        'teams' => filter_opts_from_set($teams),
        'agents_active' => $agentsActive,
        'agents_inactive' => $agentsInactive,
        'clients' => filter_opts_from_set($clients),
        'partners' => filter_opts_from_set($partners),
        'categories' => filter_opts_from_set($categories),
        'channels' => filter_opts_from_set($channels),
        'card_types' => filter_opts_from_set($cardTypes),
        'client_types' => filter_opts_from_set($clientTypes),
        'request_types' => filter_opts_from_set($requestTypes),
    ];
}

function save_filter_options_cache(array $options, string $loadedAt): void
{
    $path = filter_options_cache_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $payload = [
        'built_at' => $loadedAt,
        'options' => $options,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Не удалось сохранить filter_options.json');
    }
    file_put_contents($path, $json);
}

/** @return array<string, mixed>|null */
function load_filter_options_cache(): ?array
{
    $meta = storage_load_meta();
    $loadedAt = $meta['loaded_at'] ?? null;
    if (!$loadedAt) {
        return null;
    }
    $path = filter_options_cache_path();
    if (!is_readable($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || ($data['built_at'] ?? null) !== $loadedAt) {
        return null;
    }
    $options = $data['options'] ?? null;
    return is_array($options) ? $options : null;
}
