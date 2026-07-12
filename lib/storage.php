<?php
/**
 * storage.php
 *
 * Хранение таблиц дашборда.
 *
 * Порядок выбора:
 * 1) SQLite (data/dashboard.db) — если есть PDO SQLite и папка writable
 * 2) иначе JSON-файлы в data/tables/ — работает почти везде
 *
 * MySQL можно включить позже через settings.json → storage.mysql
 *
 * Таблицы: operations_1c, deals_bitrix, sales_unified, agents_dim
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/settings.php';

/** Какой backend сейчас используем: sqlite | json */
function storage_backend(): string
{
    static $backend = null;
    if ($backend !== null) {
        return $backend;
    }
    $dataDir = project_root() . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0775, true);
    }
    if (extension_loaded('pdo_sqlite') && is_writable($dataDir)) {
        $backend = 'sqlite';
    } else {
        $backend = 'json';
        $tables = $dataDir . DIRECTORY_SEPARATOR . 'tables';
        if (!is_dir($tables)) {
            @mkdir($tables, 0775, true);
        }
    }
    return $backend;
}

function storage_sqlite_path(): string
{
    $settings = load_settings();
    $rel = $settings['paths']['sqlite'] ?? 'data/dashboard.db';
    return project_root() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
}

function storage_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $path = storage_sqlite_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

/**
 * Сохранить таблицу (массив ассоциативных строк).
 * Старые данные этой таблицы полностью заменяются.
 */
function storage_save_table(string $table, array $rows): void
{
    if (storage_backend() === 'sqlite') {
        storage_sqlite_save($table, $rows);
        return;
    }
    $path = project_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tables' . DIRECTORY_SEPARATOR . $table . '.json';
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException("Не удалось сохранить таблицу {$table}");
    }
    file_put_contents($path, $json);
}

/** Загрузить таблицу как массив строк. */
function storage_load_table(string $table): array
{
    if (storage_backend() === 'sqlite') {
        return storage_sqlite_load($table);
    }
    $path = project_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tables' . DIRECTORY_SEPARATOR . $table . '.json';
    if (!is_readable($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function storage_sqlite_save(string $table, array $rows): void
{
    $pdo = storage_pdo();
    // Старые таблицы Python имеют другую схему — пересоздаём под PHP
    $safe = preg_replace('/[^a-z0-9_]/i', '', $table);
    if ($safe === '') {
        throw new RuntimeException('Некорректное имя таблицы');
    }
    $pdo->exec("DROP TABLE IF EXISTS {$safe}");
    $pdo->exec("CREATE TABLE {$safe} (id INTEGER PRIMARY KEY AUTOINCREMENT, payload TEXT NOT NULL)");
    $stmt = $pdo->prepare("INSERT INTO {$safe} (payload) VALUES (:payload)");
    $pdo->beginTransaction();
    foreach ($rows as $row) {
        $stmt->execute([':payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
    $pdo->commit();
}

function storage_sqlite_load(string $table): array
{
    $pdo = storage_pdo();
    $safe = preg_replace('/[^a-z0-9_]/i', '', $table);
    try {
        $stmt = $pdo->query("SELECT payload FROM {$safe}");
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($row['payload'])) {
            // На всякий случай: если попали в старую схему Python
            return [];
        }
        $decoded = json_decode($row['payload'], true);
        if (is_array($decoded)) {
            $out[] = $decoded;
        }
    }
    return $out;
}

/** Метаданные последней загрузки (когда грузили, сколько строк, предупреждения). */
function storage_save_meta(array $meta): void
{
    $settings = load_settings();
    $rel = $settings['paths']['last_load_meta'] ?? 'data/last_load.json';
    $path = project_root() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents(
        $path,
        json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
}

function storage_load_meta(): array
{
    $settings = load_settings();
    $rel = $settings['paths']['last_load_meta'] ?? 'data/last_load.json';
    $path = project_root() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
    if (!is_readable($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}
