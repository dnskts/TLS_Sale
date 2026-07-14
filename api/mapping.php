<?php
/**
 * api/mapping.php — чтение/сохранение mapping.json (нужен X-Settings-Token).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('mapping.php');
require_lib('auth.php');
require_lib('xlsx_reader.php');
require_lib('html_table_reader.php');
require_lib('input_files.php');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    require_settings_auth();
    $mapping = load_mapping();
    json_response([
        'ok' => true,
        'mapping' => $mapping,
        'path' => 'mapping.json',
        'exists' => is_readable(mapping_path()),
    ]);
}

if ($method === 'POST') {
    require_settings_auth();
    $body = read_json_body();
    $action = $body['action'] ?? 'save';

    if ($action === 'scan_headers') {
        $rel = clean_str($body['file'] ?? null) ?? '';
        $sheet = clean_str($body['sheet'] ?? null);
        if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/') || str_contains($rel, '\\..')) {
            json_response(['ok' => false, 'error' => 'Некорректный путь file'], 400);
        }
        $root = project_root();
        $full = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
        $realRoot = realpath($root);
        $realFile = realpath($full);
        if ($realRoot === false || $realFile === false || !str_starts_with($realFile, $realRoot) || !is_file($realFile)) {
            json_response(['ok' => false, 'error' => 'Файл не найден: ' . $rel], 404);
        }
        $ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
        $headers = [];
        $sheets = [];
        $usedSheet = $sheet;
        try {
            if ($ext === 'xlsx') {
                $sheets = xlsx_list_sheet_names($realFile);
                if ($usedSheet === null || $usedSheet === '') {
                    $usedSheet = $sheets[0] ?? null;
                }
                if ($usedSheet === null) {
                    json_response(['ok' => false, 'error' => 'Нет листов в xlsx'], 400);
                }
                $rows = xlsx_read_first_rows($realFile, $usedSheet, 1);
                $headers = $rows[0] ?? [];
            } elseif (is_html_table_export($realFile)) {
                $rows = html_table_read_rows($realFile);
                $headers = $rows[0] ?? [];
                $usedSheet = '(html table)';
            } else {
                json_response(['ok' => false, 'error' => 'Поддерживаются xlsx и HTML-xls'], 400);
            }
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 500);
        }
        $norm = [];
        foreach ($headers as $i => $t) {
            $norm[] = [
                'index' => (int) $i,
                'header' => clean_str(str_replace("\n", ' ', (string) $t)) ?? '',
            ];
        }
        json_response([
            'ok' => true,
            'file' => $rel,
            'sheet' => $usedSheet,
            'sheets' => $sheets,
            'headers' => $norm,
            'detected_bitrix_profile' => detect_bitrix_mapping_profile(array_column($norm, 'header')),
        ]);
    }

    if ($action === 'save' || $action === null || $action === '') {
        $mapping = $body['mapping'] ?? null;
        if (!is_array($mapping)) {
            json_response(['ok' => false, 'error' => 'Нужен объект mapping'], 400);
        }
        $warnings = validate_mapping($mapping);
        try {
            save_mapping($mapping);
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 500);
        }
        $saved = load_mapping();
        json_response([
            'ok' => true,
            'mapping' => $saved,
            'warnings' => $warnings,
            'backup' => true,
        ]);
    }

    json_response(['ok' => false, 'error' => 'Неизвестное action'], 400);
}

json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
