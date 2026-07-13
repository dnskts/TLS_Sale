<?php
/**
 * api/settings.php — чтение и сохранение справочника агентов / команд.
 *
 * GET  — вернуть agents, teams, dismissed_agent_warnings
 * POST — сохранить (нужен заголовок X-Settings-Token)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('settings.php');
require_lib('auth.php');
require_lib('analytics_settings.php');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $settings = load_settings();
    $teams = $settings['teams'] ?? [];
    if (!$teams) {
        // Соберём из агентов и department_map
        $set = [];
        foreach ($settings['department_map'] ?? [] as $k => $v) {
            if ($k) {
                $set[$k] = true;
            }
            if ($v) {
                $set[$v] = true;
            }
        }
    foreach ($settings['agents'] ?? [] as $a) {
        if (!empty($a['team'])) {
            $set[$a['team']] = true;
        }
        foreach (agent_teams($a) as $t) {
            if ($t) {
                $set[$t] = true;
            }
        }
    }
        $teams = array_keys($set);
        sort($teams, SORT_STRING);
    }
    json_response([
        'ok' => true,
        'agents' => normalize_agents_list($settings['agents'] ?? []),
        'teams' => $teams,
        'dismissed_agent_warnings' => $settings['dismissed_agent_warnings'] ?? [],
        'app' => $settings['app'] ?? [],
        'defaults' => $settings['defaults'] ?? [],
        'ui' => $settings['ui'] ?? ['agents_page_size' => 25],
        'paths' => $settings['paths'] ?? [],
        'funnel' => funnel_config($settings),
        'sales_plans' => $settings['sales_plans'] ?? [],
    ]);
}

if ($method === 'POST') {
    require_settings_auth();
    $body = read_json_body();
    $agents = $body['agents'] ?? null;
    $teams = $body['teams'] ?? null;
    $dismissed = $body['dismissed_agent_warnings'] ?? null;
    $app = $body['app'] ?? null;
    $defaults = $body['defaults'] ?? null;
    $ui = $body['ui'] ?? null;
    $funnel = $body['funnel'] ?? null;
    $salesPlans = $body['sales_plans'] ?? null;

    if (!is_array($agents)) {
        json_response(['ok' => false, 'error' => 'Нужен массив agents'], 400);
    }

    // Простая валидация
    $errors = [];
    $keys = [];
    $teamCatalog = is_array($teams) ? array_values(array_unique(array_filter(array_map('trim', $teams)))) : [];
    foreach ($agents as $i => $agent) {
        $key = trim((string) ($agent['agent_key'] ?? ''));
        $name = trim((string) ($agent['name_display'] ?? ''));
        $agentTeams = $agent['teams'] ?? null;
        if (!is_array($agentTeams) || $agentTeams === []) {
            $legacyTeam = trim((string) ($agent['team'] ?? ''));
            $agentTeams = $legacyTeam !== '' ? [$legacyTeam] : [];
        }
        $agentTeams = array_values(array_unique(array_filter(array_map(
            fn($t) => trim((string) $t),
            $agentTeams
        ))));
        if ($key === '') {
            $errors[] = 'Строка ' . ($i + 1) . ': нужен agent_key';
        } elseif (isset($keys[$key])) {
            $errors[] = "Дублируется agent_key «{$key}»";
        } else {
            $keys[$key] = true;
        }
        if ($name === '') {
            $errors[] = "«{$key}»: нужно отображаемое имя";
        }
        if ($agentTeams === []) {
            $errors[] = "«{$key}»: нужна хотя бы одна команда";
        }
        foreach (['names_1c', 'names_bitrix'] as $aliasField) {
            $aliases = $agent[$aliasField] ?? [];
            if (!is_array($aliases)) {
                $errors[] = "«{$key}»: {$aliasField} должен быть массивом";
            }
        }
    }
    if ($errors) {
        json_response(['ok' => false, 'error' => 'Ошибки валидации', 'errors' => $errors], 400);
    }

    backup_settings();
    $settings = load_settings();
    $normalizedAgents = [];
    foreach ($agents as $agent) {
        $normalizedAgents[] = normalize_agent_record($agent);
    }
    $settings['agents'] = $normalizedAgents;
    if (is_array($teams)) {
        $settings['teams'] = $teamCatalog;
    }
    // Команды из агентов тоже попадают в каталог (на случай новой команды через агента)
    $mergedTeams = $settings['teams'] ?? [];
    foreach ($normalizedAgents as $agent) {
        foreach (agent_teams($agent) as $t) {
            if ($t !== '' && !in_array($t, $mergedTeams, true)) {
                $mergedTeams[] = $t;
            }
        }
    }
    sort($mergedTeams, SORT_STRING);
    $settings['teams'] = array_values($mergedTeams);
    if (is_array($dismissed)) {
        $settings['dismissed_agent_warnings'] = array_values(array_unique($dismissed));
    }
    if (is_array($app)) {
        $title = trim((string) ($app['title'] ?? ''));
        if ($title !== '') {
            $settings['app']['title'] = $title;
        }
    }
    if (is_array($defaults)) {
        $source = $defaults['source'] ?? null;
        if (in_array($source, ['all', '1c', 'bitrix'], true)) {
            $settings['defaults']['source'] = $source;
        }
        if (array_key_exists('show_inactive_agents', $defaults)) {
            $settings['defaults']['show_inactive_agents'] = !empty($defaults['show_inactive_agents']);
        }
    }
    if (is_array($ui)) {
        $pageSize = (int) ($ui['agents_page_size'] ?? 25);
        if (in_array($pageSize, [25, 50, 100], true)) {
            $settings['ui']['agents_page_size'] = $pageSize;
        }
    }
    if (is_array($funnel)) {
        $settings['funnel'] = array_merge(funnel_config($settings), $funnel);
    }
    if (is_array($salesPlans)) {
        $settings['sales_plans'] = $salesPlans;
    }
    save_settings($settings);

    json_response([
        'ok' => true,
        'count' => count($normalizedAgents),
        'message' => 'Сохранено в settings.json. Чтобы привязать к продажам — нажмите «Применить к данным».',
    ]);
}

json_response(['ok' => false, 'error' => 'Метод не поддерживается'], 405);
