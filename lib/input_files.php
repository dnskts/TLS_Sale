<?php
/**
 * input_files.php — поиск файлов выгрузки в input/ с запасными именами.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * @return list<array{name: string, path: string, mtime: int}>
 */
function list_input_data_files(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === '.gitkeep' || $name === '.htaccess') {
            continue;
        }
        if (str_starts_with($name, '.')) {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            continue;
        }
        $out[] = [
            'name' => $name,
            'path' => $path,
            'mtime' => (int) filemtime($path),
        ];
    }
    usort($out, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}

/**
 * Найти первый читаемый файл из списка кандидатов.
 *
 * @param list<string> $fallbackNames
 */
function resolve_input_file(string $dir, string $preferredName, array $fallbackNames = []): ?string
{
    $names = array_merge([$preferredName], $fallbackNames);
    $seen = [];
    foreach ($names as $name) {
        if ($name === '' || isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_readable($path)) {
            return $path;
        }
    }
    return null;
}
