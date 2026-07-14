<?php
/**
 * input_detect.php — автоопределение файлов 1С и Битрикс export в input/.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/input_files.php';
require_once __DIR__ . '/xlsx_reader.php';
require_once __DIR__ . '/html_table_reader.php';
require_once __DIR__ . '/bitrix_export.php';
require_once __DIR__ . '/parse_1c.php';

/** @return list<string> */
function one_c_fingerprint_headers(): array
{
    require_once __DIR__ . '/mapping.php';
    $fp = one_c_fingerprint_from_mapping();
    return $fp !== [] ? $fp : ['Дата операции', 'Агент', 'Поставщик', 'Сумма продажи'];
}

/**
 * Нормализовать заголовок ячейки.
 *
 * @param mixed $title
 */
function input_detect_norm_header($title): string
{
    return clean_str(str_replace("\n", ' ', (string) $title)) ?? '';
}

/**
 * @param list<mixed> $headerRow
 * @return array<string, true>
 */
function input_detect_header_set(array $headerRow): array
{
    $set = [];
    foreach ($headerRow as $cell) {
        $norm = input_detect_norm_header($cell);
        if ($norm !== '') {
            $set[$norm] = true;
        }
    }
    return $set;
}

/**
 * @param list<mixed> $headerRow
 */
function score_bitrix_export_probe(array $headerRow, string $sheetName): int
{
    $set = input_detect_header_set($headerRow);
    $score = 0;
    foreach (bitrix_export_fingerprint_headers() as $h) {
        if (isset($set[$h])) {
            $score += 10;
        }
    }
    if (isset($set['ID'], $set['Ответственный'], $set['Результат сделки'])) {
        $score += 25;
    }
    if (isset($set['Всего к оплате Клиентом'], $set['Комиссия'], $set['Сервисный сбор'])) {
        $score += 25;
    }
    $colCount = count(array_filter($headerRow, static fn($c) => $c !== null && $c !== ''));
    if ($colCount >= 65 && $colCount <= 85) {
        $score += 8;
    }
    if (stripos($sheetName, 'отчет') !== false || stripos($sheetName, 'сделк') !== false) {
        $score += 5;
    }
    // 1С-маркеры снижают score
    if (isset($set['Дата операции']) && !isset($set['ID'])) {
        $score -= 40;
    }
    return $score;
}

/**
 * @param list<mixed> $headerRow
 */
function score_one_c_probe(array $headerRow, string $sheetName): int
{
    $set = input_detect_header_set($headerRow);
    $colCount = count($headerRow);
    $score = 0;

    $first = input_detect_norm_header($headerRow[0] ?? '');
    if ($first === 'Дата операции' || str_contains($first, 'Дата операции')) {
        $score += 30;
    }
    if ($colCount >= 40 && $colCount <= 50) {
        $score += 20;
    }
    foreach (one_c_fingerprint_headers() as $h) {
        if (isset($set[$h])) {
            $score += 8;
        }
    }
    if (strcasecmp($sheetName, 'TDSheet') === 0) {
        $score += 5;
    }
    // Bitrix export — не 1С
    if (isset($set['ID'], $set['Ответственный'], $set['Результат сделки'])) {
        $score -= 50;
    }
    if (score_bitrix_export_probe($headerRow, $sheetName) >= 40) {
        $score -= 30;
    }
    return $score;
}

/**
 * @return list<array{sheet: string, score: int, header: list<mixed>}>
 */
function probe_xlsx_file(string $path): array
{
    if (!is_readable($path) || !str_ends_with(strtolower($path), '.xlsx')) {
        return [];
    }
    $probes = [];
    foreach (xlsx_list_sheet_names($path) as $sheetName) {
        try {
            $rows = xlsx_read_first_rows($path, $sheetName, 1);
        } catch (Throwable $e) {
            continue;
        }
        if ($rows === []) {
            continue;
        }
        $header = $rows[0];
        $bx = score_bitrix_export_probe($header, $sheetName);
        $oc = score_one_c_probe($header, $sheetName);
        $probes[] = [
            'sheet' => $sheetName,
            'bitrix_score' => $bx,
            'one_c_score' => $oc,
            'header' => $header,
        ];
    }
    return $probes;
}

/**
 * @param array<string, mixed> $settings
 * @return array{
 *   one_c: ?array{path: string, sheet: string, score: int},
 *   bitrix: ?array{path: string, sheet: string, score: int},
 *   scanned: list<array<string, mixed>>,
 *   warnings: list<string>
 * }
 */
function resolve_input_sources(string $inputDir, array $settings = []): array
{
    $warnings = [];
    $scanned = [];
    $bestOneC = null;
    $bestBitrix = null;

    foreach (list_input_data_files($inputDir) as $fileInfo) {
        $path = $fileInfo['path'];
        $basename = $fileInfo['name'];

        if (is_html_table_export($path)) {
            $scanned[] = [
                'file' => $basename,
                'kind' => 'unknown',
                'reason' => 'HTML .xls (legacy Битрикс) не поддерживается',
            ];
            continue;
        }

        if (!str_ends_with(strtolower($path), '.xlsx')) {
            $scanned[] = ['file' => $basename, 'kind' => 'unknown', 'reason' => 'не xlsx'];
            continue;
        }

        $probes = probe_xlsx_file($path);
        if ($probes === []) {
            $scanned[] = ['file' => $basename, 'kind' => 'unknown', 'reason' => 'нет читаемых листов'];
            continue;
        }

        $fileBestBx = null;
        $fileBestOc = null;
        foreach ($probes as $probe) {
            $sheet = $probe['sheet'];
            $bx = (int) $probe['bitrix_score'];
            $oc = (int) $probe['one_c_score'];
            if ($bx > $oc && $bx >= 25) {
                if ($fileBestBx === null || $bx > $fileBestBx['score']) {
                    $fileBestBx = ['path' => $path, 'sheet' => $sheet, 'score' => $bx, 'file' => $basename];
                }
            }
            if ($oc > $bx && $oc >= 25) {
                if ($fileBestOc === null || $oc > $fileBestOc['score']) {
                    $fileBestOc = ['path' => $path, 'sheet' => $sheet, 'score' => $oc, 'file' => $basename];
                }
            }
        }

        $scanned[] = [
            'file' => $basename,
            'mtime' => $fileInfo['mtime'],
            'best_bitrix_score' => $fileBestBx['score'] ?? 0,
            'best_one_c_score' => $fileBestOc['score'] ?? 0,
            'sheets' => array_column($probes, 'sheet'),
        ];

        if ($fileBestBx !== null) {
            if ($bestBitrix === null || $fileBestBx['score'] > $bestBitrix['score']
                || ($fileBestBx['score'] === $bestBitrix['score'] && $fileInfo['mtime'] > ($bestBitrix['mtime'] ?? 0))) {
                $fileBestBx['mtime'] = $fileInfo['mtime'];
                $bestBitrix = $fileBestBx;
            }
        }
        if ($fileBestOc !== null) {
            if ($bestOneC === null || $fileBestOc['score'] > $bestOneC['score']
                || ($fileBestOc['score'] === $bestOneC['score'] && $fileInfo['mtime'] > ($bestOneC['mtime'] ?? 0))) {
                $fileBestOc['mtime'] = $fileInfo['mtime'];
                $bestOneC = $fileBestOc;
            }
        }
    }

    if ($bestOneC !== null && $bestBitrix !== null && $bestOneC['path'] === $bestBitrix['path']) {
        if ($bestOneC['score'] >= $bestBitrix['score']) {
            $bestBitrix = null;
            $warnings[] = 'input_detect: файл классифицирован как 1С (не как Битрикс): ' . $bestOneC['file'];
        } else {
            $bestOneC = null;
            $warnings[] = 'input_detect: файл классифицирован как Битрикс (не как 1С): ' . $bestBitrix['file'];
        }
    }

    if ($bestOneC !== null) {
        foreach (list_input_data_files($inputDir) as $fi) {
            if ($fi['path'] === $bestOneC['path']) {
                continue;
            }
            $probes = probe_xlsx_file($fi['path']);
            foreach ($probes as $probe) {
                if ((int) $probe['one_c_score'] >= 25 && (int) $probe['one_c_score'] >= (int) $probe['bitrix_score']) {
                    $warnings[] = 'ignored_one_c: ' . $fi['name'] . ' (score ' . $probe['one_c_score'] . ')';
                }
            }
        }
    }

    if ($bestBitrix !== null) {
        foreach (list_input_data_files($inputDir) as $fi) {
            if ($fi['path'] === $bestBitrix['path']) {
                continue;
            }
            $probes = probe_xlsx_file($fi['path']);
            foreach ($probes as $probe) {
                if ((int) $probe['bitrix_score'] >= 25 && (int) $probe['bitrix_score'] > (int) $probe['one_c_score']) {
                    $warnings[] = 'ignored_bitrix: ' . $fi['name'] . ' (score ' . $probe['bitrix_score'] . ')';
                }
            }
        }
    }

    $normalize = static function (?array $item): ?array {
        if ($item === null) {
            return null;
        }
        return [
            'path' => $item['path'],
            'sheet' => $item['sheet'],
            'score' => $item['score'],
            'file' => $item['file'] ?? basename($item['path']),
        ];
    };

    $result = [
        'one_c' => $normalize($bestOneC),
        'bitrix' => $normalize($bestBitrix),
        'scanned' => $scanned,
        'warnings' => $warnings,
    ];

    $override1c = $settings['paths']['file_1c'] ?? null;
    $overrideBx = $settings['paths']['file_bitrix'] ?? null;
    if (is_string($override1c) && $override1c !== '') {
        $p = $inputDir . DIRECTORY_SEPARATOR . $override1c;
        if (is_readable($p)) {
            $result['one_c'] = [
                'path' => $p,
                'sheet' => $settings['sheets']['1c'] ?? 'TDSheet',
                'score' => 999,
                'file' => $override1c,
            ];
            $result['warnings'][] = 'input_detect: file_1c задан в settings.json';
        }
    }
    if (is_string($overrideBx) && $overrideBx !== '') {
        $p = $inputDir . DIRECTORY_SEPARATOR . $overrideBx;
        if (is_readable($p)) {
            $result['bitrix'] = [
                'path' => $p,
                'sheet' => $settings['sheets']['bitrix'] ?? bitrix_export_default_sheet(),
                'score' => 999,
                'file' => $overrideBx,
            ];
            $result['warnings'][] = 'input_detect: file_bitrix задан в settings.json';
        }
    }

    return $result;
}

/**
 * @param array<string, mixed> $sources from resolve_input_sources
 */
function format_input_detect_error(array $sources, string $inputDir): string
{
    $lines = [];
    if ($sources['one_c'] === null) {
        $lines[] = 'Не найден файл 1С в ' . $inputDir;
    }
    if ($sources['bitrix'] === null) {
        $lines[] = 'Не найден файл Битрикс (export CRM, лист «Отчет по сделкам») в ' . $inputDir;
    }
    foreach ($sources['scanned'] as $row) {
        $lines[] = '  · ' . ($row['file'] ?? '?')
            . (isset($row['best_one_c_score']) ? ' 1С=' . $row['best_one_c_score'] : '')
            . (isset($row['best_bitrix_score']) ? ' Bx=' . $row['best_bitrix_score'] : '')
            . (isset($row['reason']) ? ' — ' . $row['reason'] : '');
    }
    return implode("\n", $lines);
}
