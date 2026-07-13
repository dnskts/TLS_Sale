<?php
/**
 * settings.php
 *
 * Работа с файлом settings.json:
 * - чтение настроек и справочника агентов
 * - сохранение с резервной копией в data/backups/
 *
 * Важно: соответствие имён агентов берём ТОЛЬКО из settings.json,
 * никаких «умных» догадок по ФИО.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Полный путь к settings.json */
function settings_path(): string
{
    return project_root() . DIRECTORY_SEPARATOR . 'settings.json';
}

/** @return array<string, mixed>|null */
function &settings_cache_ref(): ?array
{
    static $cache = null;
    return $cache;
}

/** Прочитать весь settings.json в массив. */
function load_settings(): array
{
    $cache = &settings_cache_ref();
    if (is_array($cache)) {
        return $cache;
    }
    $path = settings_path();
    if (!is_readable($path)) {
        throw new RuntimeException('Не найден settings.json в корне проекта');
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        throw new RuntimeException('settings.json повреждён (не JSON)');
    }
    $cache = $data;
    return $cache;
}

function clear_settings_cache(): void
{
    $cache = &settings_cache_ref();
    $cache = null;
}

/**
 * Аккуратно записать settings.json (сначала во временный файл, потом заменить).
 * Так меньше шансов сломать файл при сбое.
 */
function save_settings(array $data): void
{
    $path = settings_path();
    $tmp = $path . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Не удалось закодировать settings.json');
    }
    if (file_put_contents($tmp, $json . "\n") === false) {
        throw new RuntimeException('Не удалось записать временный settings.json');
    }
    if (!rename($tmp, $path)) {
        throw new RuntimeException('Не удалось заменить settings.json');
    }
    clear_settings_cache();
}

/** Сделать копию settings.json в data/backups/ перед важными изменениями. */
function backup_settings(): string
{
    $dir = project_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $stamp = date('Ymd_His');
    $dest = $dir . DIRECTORY_SEPARATOR . "settings_{$stamp}.json";
    if (!copy(settings_path(), $dest)) {
        throw new RuntimeException('Не удалось создать резервную копию settings.json');
    }
    // Храним не больше 20 последних копий
    $files = glob($dir . DIRECTORY_SEPARATOR . 'settings_*.json') ?: [];
    rsort($files);
    foreach (array_slice($files, 20) as $old) {
        @unlink($old);
    }
    return $dest;
}

/**
 * Найти агента по точному имени из выгрузки 1С (names_1c).
 * Если не нашли — вернём unknown:...
 */
function resolve_agent_1c(?string $rawName, array $settings, $department = null): array
{
    $raw = clean_str($rawName) ?? '';
    if ($raw === '') {
        return [
            'agent_key' => 'unknown:',
            'name_display' => '',
            'team' => department_team($department),
            'teams' => [department_team($department)],
            'is_active' => null,
        ];
    }
    foreach ($settings['agents'] ?? [] as $agent) {
        foreach ($agent['names_1c'] ?? [] as $alias) {
            if (clean_str($alias) === $raw) {
                $teams = agent_teams($agent);
                return [
                    'agent_key' => $agent['agent_key'],
                    'name_display' => $agent['name_display'] ?? $agent['agent_key'],
                    'team' => $teams[0],
                    'teams' => $teams,
                    'is_active' => $agent['is_active'] ?? true,
                ];
            }
        }
    }
    return [
        'agent_key' => 'unknown:' . $raw,
        'name_display' => $raw,
        'team' => department_team($department),
        'teams' => [department_team($department)],
        'is_active' => null,
    ];
}

/**
 * Найти агента по точному имени из Битрикс (names_bitrix / responsible_person).
 */
function resolve_agent_bitrix(?string $rawName, array $settings): array
{
    $raw = clean_str($rawName) ?? '';
    if ($raw === '') {
        return [
            'agent_key' => 'unknown:',
            'name_display' => '',
            'team' => 'Без команды',
            'teams' => ['Без команды'],
            'is_active' => null,
        ];
    }
    foreach ($settings['agents'] ?? [] as $agent) {
        foreach ($agent['names_bitrix'] ?? [] as $alias) {
            if (clean_str($alias) === $raw) {
                $teams = agent_teams($agent);
                return [
                    'agent_key' => $agent['agent_key'],
                    'name_display' => $agent['name_display'] ?? $agent['agent_key'],
                    'team' => $teams[0],
                    'teams' => $teams,
                    'is_active' => $agent['is_active'] ?? true,
                ];
            }
        }
    }
    return [
        'agent_key' => 'unknown:' . $raw,
        'name_display' => $raw,
        'team' => 'Без команды',
        'teams' => ['Без команды'],
        'is_active' => null,
    ];
}

function department_team($department): string
{
    $text = clean_str($department);
    return $text ?? 'Без команды';
}

/** Список команд агента (teams[] или legacy team). */
function agent_teams(array $agent): array
{
    if (!empty($agent['teams']) && is_array($agent['teams'])) {
        $out = array_values(array_unique(array_filter(array_map(
            fn($t) => trim((string) $t),
            $agent['teams']
        ))));
        if ($out !== []) {
            return $out;
        }
    }
    $legacy = trim((string) ($agent['team'] ?? ''));
    return $legacy !== '' ? [$legacy] : ['Без команды'];
}

/** Основная команда (первая в teams[]) — для отображения в KPI. */
function agent_primary_team(array $agent): string
{
    return agent_teams($agent)[0];
}

/** Нормализовать запись агента: teams[] + team для совместимости. */
function normalize_agent_record(array $agent): array
{
    $teams = agent_teams($agent);
    $agent['teams'] = $teams;
    $agent['team'] = $teams[0];
    return $agent;
}

/** @param list<array> $agents */
function normalize_agents_list(array $agents): array
{
    return array_map('normalize_agent_record', $agents);
}

/** Логин/пароль страницы настроек (простая защита для локального использования). */
function settings_credentials(): array
{
    return ['login' => 'admin', 'password' => '95123'];
}
